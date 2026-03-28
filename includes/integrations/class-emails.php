<?php
class OBM_Integration_Emails {
    private static $instance = null;
    public static function get_instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_post_obm_save_email_template', [$this, 'save_template']);
        add_action('wp_ajax_obm_send_email', [$this, 'ajax_send_email']);
        add_action('obm_lead_status_changed', [$this, 'on_status_change'], 10, 3);
        add_action('obm_check_scheduled_emails', [$this, 'process_scheduled']);
        if (!wp_next_scheduled('obm_check_scheduled_emails')) {
            wp_schedule_event(time(), 'daily', 'obm_check_scheduled_emails');
        }
    }

    private function get_merge_tags($lead) {
        $staff = $lead->staff_id ? OBM_DB::get_staff_member($lead->staff_id) : null;
        $biz = obm_get('business_name', get_bloginfo('name'));
        $staff_label = obm_get('staff_label', 'Staff');
        $portal_url = '';
        if (!empty($lead->portal_token)) {
            $portal_url = home_url('/my-booking/' . $lead->portal_token);
        }
        return [
            '{client_name}' => $lead->name,
            '{email}' => $lead->email,
            '{phone}' => $lead->phone,
            '{date}' => $lead->requested_date,
            '{time}' => $lead->start_time ?: 'TBD',
            '{guests}' => $lead->guests,
            '{staff_name}' => $staff ? $staff->name : 'TBD',
            '{staff_label}' => $staff_label,
            '{business_name}' => $biz,
            '{duration}' => $lead->service_duration ?: 'TBD',
            '{review_link}' => get_option('obm_review_url', ''),
            '{payment_link}' => '',
            '{portal_link}' => $portal_url,
            '{waiver_link}' => !empty($lead->waiver_token) ? home_url('/waiver/' . $lead->waiver_token) : '',
        ];
    }

    public function send_template($template_slug, $lead_id) {
        global $wpdb;
        $p = OBM_DB::get_prefix();
        $template = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$p}email_templates WHERE slug=%s AND active=1", $template_slug
        ));
        if (!$template) return false;

        $lead = OBM_DB::get_lead($lead_id);
        if (!$lead || empty($lead->email)) return false;

        $tags = $this->get_merge_tags($lead);
        $subject = str_replace(array_keys($tags), array_values($tags), $template->subject);
        $body = str_replace(array_keys($tags), array_values($tags), $template->body);

        $brand = obm_get('brand_color', '#2c5f2d');
        $html_body = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;">';
        $html_body .= nl2br(esc_html($body));
        $html_body .= '</div>';

        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $sent = wp_mail($lead->email, $subject, $html_body, $headers);

        $wpdb->insert($p . 'email_log', [
            'lead_id' => $lead_id,
            'template_slug' => $template_slug,
            'subject' => $subject,
            'recipient' => $lead->email,
            'status' => $sent ? 'sent' : 'failed',
            'sent_at' => current_time('mysql'),
        ]);

        return $sent;
    }

    public function on_status_change($lead_id, $old_status, $new_status) {
        if ($new_status === 'booked') {
            $this->send_template('welcome', $lead_id);
        }
        if ($new_status === 'completed') {
            wp_schedule_single_event(time() + 86400, 'obm_send_delayed_email', [$lead_id, 'thank_you']);
        }
    }

    public function process_scheduled() {
        global $wpdb;
        $p = OBM_DB::get_prefix();
        $today = date('Y-m-d');

        $templates = $wpdb->get_results(
            "SELECT * FROM {$p}email_templates WHERE trigger_event='reminder' AND active=1"
        );

        foreach ($templates as $t) {
            $target_date = date('Y-m-d', strtotime($today . ' ' . abs($t->delay_days) . ' days'));
            $leads = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$p}leads WHERE requested_date=%s AND status='booked'", $target_date
            ));
            foreach ($leads as $lead) {
                $already = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$p}email_log WHERE lead_id=%d AND template_slug=%s AND DATE(sent_at)=%s",
                    $lead->id, $t->slug, $today
                ));
                if (!$already) {
                    $this->send_template($t->slug, $lead->id);
                }
            }
        }

        $dep_template = $wpdb->get_row(
            "SELECT * FROM {$p}email_templates WHERE slug='deposit_reminder' AND active=1"
        );
        if ($dep_template) {
            $cutoff = date('Y-m-d H:i:s', strtotime("-{$dep_template->delay_days} days"));
            $leads = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$p}leads WHERE status='booked' AND payment_status='none' AND created_at <= %s", $cutoff
            ));
            foreach ($leads as $lead) {
                $already = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$p}email_log WHERE lead_id=%d AND template_slug='deposit_reminder'",
                    $lead->id
                ));
                if (!$already) {
                    $this->send_template('deposit_reminder', $lead->id);
                }
            }
        }
    }

    public function ajax_send_email() {
        check_ajax_referer('obm_nonce', 'nonce');
        if (!current_user_can('obm_manage_bookings')) wp_send_json_error('Unauthorized');
        $lead_id = intval($_POST['lead_id']);
        $slug = sanitize_text_field($_POST['template']);
        if ($this->send_template($slug, $lead_id)) {
            wp_send_json_success();
        } else {
            wp_send_json_error('Failed to send');
        }
    }

    public function get_log($lead_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . OBM_DB::get_prefix() . "email_log WHERE lead_id=%d ORDER BY sent_at DESC",
            $lead_id
        ));
    }

    public function get_templates() {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM " . OBM_DB::get_prefix() . "email_templates ORDER BY id ASC");
    }

    public function add_menu() {
        add_submenu_page('obm-dashboard', 'Email Templates', null, 'manage_options', 'obm-int-emails', [$this, 'render_settings']);
    }

    public function save_template() {
        check_admin_referer('obm_email_template_action');
        global $wpdb;
        $p = OBM_DB::get_prefix();
        $id = intval($_POST['template_id']);
        $data = [
            'name' => sanitize_text_field($_POST['name']),
            'subject' => sanitize_text_field($_POST['subject']),
            'body' => sanitize_textarea_field($_POST['body']),
            'active' => isset($_POST['active']) ? 1 : 0,
        ];
        $wpdb->update($p . 'email_templates', $data, ['id' => $id]);
        wp_redirect(admin_url('admin.php?page=obm-int-emails&msg=saved'));
        exit;
    }

    public function render_settings() {
        $templates = $this->get_templates();
        $editing = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
        ?>
        <div class="wrap obm-wrap">
        <h1>Email Templates</h1>
        <?php if (isset($_GET['msg'])): ?>
        <div class="notice notice-success"><p>Template saved.</p></div>
        <?php endif; ?>
        <p>Available merge tags: <code>{client_name}</code> <code>{email}</code> <code>{phone}</code> <code>{date}</code> <code>{time}</code> <code>{guests}</code> <code>{staff_name}</code> <code>{staff_label}</code> <code>{business_name}</code> <code>{duration}</code> <code>{review_link}</code> <code>{payment_link}</code> <code>{portal_link}</code> <code>{waiver_link}</code></p>

        <?php if ($editing):
            global $wpdb;
            $t = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . OBM_DB::get_prefix() . "email_templates WHERE id=%d", $editing));
            if ($t): ?>
        <h2>Edit: <?php echo esc_html($t->name); ?></h2>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="obm_save_email_template">
            <input type="hidden" name="template_id" value="<?php echo $t->id; ?>">
            <?php wp_nonce_field('obm_email_template_action'); ?>
            <table class="form-table">
            <tr><th>Name</th><td><input type="text" name="name" value="<?php echo esc_attr($t->name); ?>" class="regular-text"></td></tr>
            <tr><th>Subject</th><td><input type="text" name="subject" value="<?php echo esc_attr($t->subject); ?>" class="large-text"></td></tr>
            <tr><th>Body</th><td><textarea name="body" rows="12" class="large-text"><?php echo esc_textarea($t->body); ?></textarea></td></tr>
            <tr><th>Active</th><td><label><input type="checkbox" name="active" <?php checked($t->active, 1); ?>> Enabled</label></td></tr>
            </table>
            <p><input type="submit" class="button button-primary" value="Save Template">
            <a href="?page=obm-int-emails" class="button">Cancel</a></p>
        </form>
            <?php endif;
        else: ?>
        <table class="wp-list-table widefat fixed striped">
        <thead><tr><th>Name</th><th>Subject</th><th>Trigger</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($templates as $t): ?>
        <tr>
            <td><strong><?php echo esc_html($t->name); ?></strong></td>
            <td><?php echo esc_html($t->subject); ?></td>
            <td><?php echo esc_html($t->trigger_event); ?><?php if ($t->delay_days): ?> (<?php echo $t->delay_days; ?>d)<?php endif; ?></td>
            <td><?php echo $t->active ? '<span style="color:green;">Active</span>' : '<span style="color:#999;">Inactive</span>'; ?></td>
            <td><a href="?page=obm-int-emails&edit=<?php echo $t->id; ?>" class="button button-small">Edit</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
        </div>
        <?php
    }
}

add_action('obm_send_delayed_email', function($lead_id, $slug) {
    if (class_exists('OBM_Integration_Emails')) {
        OBM_Integration_Emails::get_instance()->send_template($slug, $lead_id);
    }
}, 10, 2);
