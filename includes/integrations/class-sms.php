<?php
class OBM_Integration_SMS {
    private static $instance = null;
    public static function get_instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'add_menu'], 99);
        add_action('admin_post_obm_sms_settings', [$this, 'save_settings']);
        add_action('wp_ajax_obm_send_sms', [$this, 'ajax_send_sms']);
        add_action('obm_lead_status_changed', [$this, 'on_status_change'], 10, 3);
    }

    private function get_sid() { return get_option('obm_twilio_sid', ''); }
    private function get_token() { return get_option('obm_twilio_token', ''); }
    private function get_from() { return get_option('obm_twilio_from', ''); }

    public function send($to, $message, $lead_id = 0) {
        $sid = $this->get_sid();
        $token = $this->get_token();
        $from = $this->get_from();
        if (empty($sid) || empty($token) || empty($from)) return false;

        $to = preg_replace('/[^0-9+]/', '', $to);
        if (strlen($to) === 10) $to = '+1' . $to;
        if ($to[0] !== '+') $to = '+' . $to;

        $url = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";
        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode("{$sid}:{$token}"),
            ],
            'body' => [
                'From' => $from,
                'To' => $to,
                'Body' => $message,
            ],
        ]);

        $success = false;
        $twilio_sid = '';
        if (!is_wp_error($response)) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (isset($body['sid'])) {
                $success = true;
                $twilio_sid = $body['sid'];
            }
        }

        global $wpdb;
        $wpdb->insert(OBM_DB::get_prefix() . 'sms_log', [
            'lead_id' => $lead_id,
            'phone' => $to,
            'message' => $message,
            'status' => $success ? 'sent' : 'failed',
            'twilio_sid' => $twilio_sid,
            'sent_at' => current_time('mysql'),
        ]);

        return $success;
    }

    private function get_default_templates() {
        return [
            'confirm' => '{business_name}: Hi {client_name}! Your booking for {date} is confirmed. We look forward to seeing you!',
            'reminder' => '{business_name}: Reminder — your booking is tomorrow, {date}! Please arrive 15 minutes early. See you soon!',
            'custom' => '',
        ];
    }

    private function get_templates() {
        $defaults = $this->get_default_templates();
        $saved = get_option('obm_sms_templates', []);
        return array_merge($defaults, $saved);
    }

    private function merge_tags($message, $lead) {
        $biz = obm_get('business_name', get_bloginfo('name'));
        $staff = $lead->staff_id ? OBM_DB::get_staff_member($lead->staff_id) : null;
        $tags = [
            '{client_name}' => $lead->name,
            '{date}' => $lead->requested_date,
            '{time}' => $lead->start_time ?: 'TBD',
            '{guests}' => $lead->guests,
            '{phone}' => $lead->phone,
            '{staff_name}' => $staff ? $staff->name : 'TBD',
            '{business_name}' => $biz,
            '{duration}' => $lead->service_duration ?: 'TBD',
        ];
        return str_replace(array_keys($tags), array_values($tags), $message);
    }

    public function on_status_change($lead_id, $old_status, $new_status) {
        $auto = get_option('obm_sms_automation', []);
        $lead = OBM_DB::get_lead($lead_id);
        if (!$lead || empty($lead->phone)) return;

        $templates = $this->get_templates();

        if ($new_status === 'booked' && !empty($auto['on_booked'])) {
            $msg = $this->merge_tags($templates['confirm'], $lead);
            $this->send($lead->phone, $msg, $lead_id);
        }
    }

    public function ajax_send_sms() {
        check_ajax_referer('obm_nonce', 'nonce');
        if (!current_user_can('obm_manage_bookings')) wp_send_json_error('Unauthorized');
        $lead_id = intval($_POST['lead_id']);
        $message = sanitize_textarea_field($_POST['message']);
        $lead = OBM_DB::get_lead($lead_id);
        if (!$lead || empty($lead->phone)) wp_send_json_error('No phone number');
        if ($this->send($lead->phone, $message, $lead_id)) {
            wp_send_json_success();
        } else {
            wp_send_json_error('Failed to send SMS');
        }
    }

    public function get_log($lead_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . OBM_DB::get_prefix() . "sms_log WHERE lead_id=%d ORDER BY sent_at DESC", $lead_id
        ));
    }

    public function add_menu() {
        add_submenu_page('obm-dashboard', 'SMS Notifications', 'SMS', 'manage_options', 'obm-int-sms', [$this, 'render_settings']);
    }

    public function save_settings() {
        check_admin_referer('obm_sms_settings_action');
        update_option('obm_twilio_sid', sanitize_text_field($_POST['twilio_sid']));
        update_option('obm_twilio_token', sanitize_text_field($_POST['twilio_token']));
        update_option('obm_twilio_from', sanitize_text_field($_POST['twilio_from']));

        $auto = [
            'on_booked' => isset($_POST['auto_on_booked']) ? 1 : 0,
            'reminder_1d' => isset($_POST['auto_reminder_1d']) ? 1 : 0,
        ];
        update_option('obm_sms_automation', $auto);

        $templates = [
            'confirm' => sanitize_textarea_field($_POST['tpl_confirm']),
            'reminder' => sanitize_textarea_field($_POST['tpl_reminder']),
        ];
        update_option('obm_sms_templates', $templates);

        wp_redirect(admin_url('admin.php?page=obm-int-sms&msg=saved'));
        exit;
    }

    public function render_settings() {
        $templates = $this->get_templates();
        $auto = get_option('obm_sms_automation', []);
        ?>
        <div class="wrap obm-wrap">
        <h1>SMS Notifications</h1>
        <?php if (isset($_GET['msg'])): ?>
        <div class="notice notice-success"><p>Settings saved.</p></div>
        <?php endif; ?>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="obm_sms_settings">
            <?php wp_nonce_field('obm_sms_settings_action'); ?>

            <h2>Twilio API</h2>
            <table class="form-table">
            <tr><th>Account SID</th><td><input type="text" name="twilio_sid" value="<?php echo esc_attr($this->get_sid()); ?>" class="regular-text"></td></tr>
            <tr><th>Auth Token</th><td><input type="password" name="twilio_token" value="<?php echo esc_attr($this->get_token()); ?>" class="regular-text"></td></tr>
            <tr><th>From Number</th><td><input type="text" name="twilio_from" value="<?php echo esc_attr($this->get_from()); ?>" class="regular-text" placeholder="+1234567890">
                <p class="description">Your Twilio phone number (must be SMS-enabled)</p></td></tr>
            </table>

            <hr>
            <h2>Automation</h2>
            <table class="widefat" style="max-width:700px;">
            <thead><tr><th>Trigger</th><th>Description</th><th style="width:80px;text-align:center;">On/Off</th></tr></thead>
            <tbody>
            <tr>
                <td><strong>Booking Confirmation</strong></td>
                <td>Text the client when their booking is confirmed</td>
                <td style="text-align:center;"><input type="checkbox" name="auto_on_booked" <?php checked(!empty($auto['on_booked'])); ?>></td>
            </tr>
            <tr>
                <td><strong>1-Day Reminder</strong></td>
                <td>Text the client the day before their booking</td>
                <td style="text-align:center;"><input type="checkbox" name="auto_reminder_1d" <?php checked(!empty($auto['reminder_1d'])); ?>></td>
            </tr>
            </tbody>
            </table>

            <hr>
            <h2>Message Templates</h2>
            <p>Merge tags: <code>{client_name}</code> <code>{date}</code> <code>{time}</code> <code>{guests}</code> <code>{staff_name}</code> <code>{business_name}</code> <code>{duration}</code></p>
            <p class="description">SMS messages should be under 160 characters for a single segment. Longer messages still send but cost more.</p>
            <table class="form-table">
            <tr><th>Confirmation</th>
                <td><textarea name="tpl_confirm" rows="3" class="large-text" maxlength="320"><?php echo esc_textarea($templates['confirm']); ?></textarea>
                <p class="description">Sent when a lead is booked (if automation is on)</p></td></tr>
            <tr><th>1-Day Reminder</th>
                <td><textarea name="tpl_reminder" rows="3" class="large-text" maxlength="320"><?php echo esc_textarea($templates['reminder']); ?></textarea>
                <p class="description">Sent the day before the booking date (if automation is on)</p></td></tr>
            </table>

            <p><input type="submit" class="button button-primary" value="Save SMS Settings"></p>
        </form>
        </div>
        <?php
    }
}
