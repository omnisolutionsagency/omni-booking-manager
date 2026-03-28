<?php
class OBM_Integration_SMS {
    private static $instance = null;
    public static function get_instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
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

    public function on_status_change($lead_id, $old_status, $new_status) {
        if (!get_option('obm_sms_auto_confirm', false)) return;
        $lead = OBM_DB::get_lead($lead_id);
        if (!$lead || empty($lead->phone)) return;

        $biz = obm_get('business_name', get_bloginfo('name'));
        if ($new_status === 'booked') {
            $msg = "{$biz}: Hi {$lead->name}! Your booking for {$lead->requested_date} is confirmed. We look forward to seeing you!";
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
        add_submenu_page('obm-dashboard', 'SMS Settings', null, 'manage_options', 'obm-int-sms', [$this, 'render_settings']);
    }

    public function save_settings() {
        check_admin_referer('obm_sms_settings_action');
        update_option('obm_twilio_sid', sanitize_text_field($_POST['twilio_sid']));
        update_option('obm_twilio_token', sanitize_text_field($_POST['twilio_token']));
        update_option('obm_twilio_from', sanitize_text_field($_POST['twilio_from']));
        update_option('obm_sms_auto_confirm', isset($_POST['auto_confirm']) ? 1 : 0);
        wp_redirect(admin_url('admin.php?page=obm-int-sms&msg=saved'));
        exit;
    }

    public function render_settings() {
        ?>
        <div class="wrap obm-wrap">
        <h1>SMS Notifications (Twilio)</h1>
        <?php if (isset($_GET['msg'])): ?>
        <div class="notice notice-success"><p>Settings saved.</p></div>
        <?php endif; ?>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="obm_sms_settings">
            <?php wp_nonce_field('obm_sms_settings_action'); ?>
            <table class="form-table">
            <tr><th>Twilio Account SID</th><td><input type="text" name="twilio_sid" value="<?php echo esc_attr($this->get_sid()); ?>" class="regular-text"></td></tr>
            <tr><th>Twilio Auth Token</th><td><input type="password" name="twilio_token" value="<?php echo esc_attr($this->get_token()); ?>" class="regular-text"></td></tr>
            <tr><th>From Number</th><td><input type="text" name="twilio_from" value="<?php echo esc_attr($this->get_from()); ?>" class="regular-text" placeholder="+1234567890"></td></tr>
            <tr><th>Auto-Send Confirmation</th><td><label><input type="checkbox" name="auto_confirm" <?php checked(get_option('obm_sms_auto_confirm', 0)); ?>> Send SMS when booking is confirmed</label></td></tr>
            </table>
            <p><input type="submit" class="button button-primary" value="Save SMS Settings"></p>
        </form>
        </div>
        <?php
    }
}
