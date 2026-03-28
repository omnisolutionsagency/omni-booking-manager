<?php
class OBM_Admin_Staff {
    private static $instance = null;
    public static function get_instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {
        add_action('admin_post_obm_add_staff', [$this, 'handle_add']);
        add_action('admin_post_obm_update_staff', [$this, 'handle_update']);
        add_action('admin_post_obm_delete_staff', [$this, 'handle_delete']);
        add_action('obm_lead_status_changed', [$this, 'notify_staff_on_booked'], 20, 3);
    }

    public function handle_add() {
        check_admin_referer('obm_staff_action');
        OBM_DB::insert_staff([
            'name' => sanitize_text_field($_POST['name']),
            'phone' => sanitize_text_field($_POST['phone']),
            'email' => sanitize_email($_POST['email'])
        ]);
        wp_redirect(admin_url('admin.php?page=obm-staff&msg=added'));
        exit;
    }

    public function handle_update() {
        check_admin_referer('obm_staff_action');
        $id = intval($_POST['staff_id']);
        OBM_DB::update_staff($id, [
            'name' => sanitize_text_field($_POST['name']),
            'phone' => sanitize_text_field($_POST['phone']),
            'email' => sanitize_email($_POST['email']),
            'active' => isset($_POST['active']) ? 1 : 0,
            'receive_digest' => isset($_POST['receive_digest']) ? 1 : 0,
        ]);
        wp_redirect(admin_url('admin.php?page=obm-staff&msg=updated'));
        exit;
    }

    public function handle_delete() {
        check_admin_referer('obm_staff_action');
        $id = intval($_POST['staff_id']);

        // Unassign this staff from any leads
        global $wpdb;
        $wpdb->update(OBM_DB::get_prefix() . 'leads', ['staff_id' => 0], ['staff_id' => $id]);

        // Delete
        $wpdb->delete(OBM_DB::get_prefix() . 'staff', ['id' => $id]);

        wp_redirect(admin_url('admin.php?page=obm-staff&msg=deleted'));
        exit;
    }

    public function notify_staff_on_booked($lead_id, $old_status, $new_status) {
        if ($new_status !== 'booked') return;
        $this->send_staff_assignment_email($lead_id);
    }

    public static function send_staff_assignment_email($lead_id) {
        $lead = OBM_DB::get_lead($lead_id);
        if (!$lead || !$lead->staff_id) return;

        $staff = OBM_DB::get_staff_member($lead->staff_id);
        if (!$staff || empty($staff->email)) return;

        $biz = obm_get('business_name', get_bloginfo('name'));
        $staff_label = obm_get('staff_label', 'Staff');
        $brand = obm_get('brand_color', '#2c5f2d');

        $subject = "{$biz} — You've been assigned a booking";

        $body = "Hi {$staff->name},\n\n";
        $body .= "You've been assigned to a booking:\n\n";
        $body .= "Client: {$lead->name}\n";
        $body .= "Date: {$lead->requested_date}\n";
        if ($lead->start_time) $body .= "Time: {$lead->start_time}\n";
        $body .= "Guests: {$lead->guests}";
        if ($lead->guests_under_6) $body .= " ({$lead->guests_under_6} under 6)";
        $body .= "\n";
        if ($lead->service_duration) $body .= "Duration: {$lead->service_duration}\n";
        $body .= "Phone: {$lead->phone}\n";
        $body .= "Email: {$lead->email}\n";
        if ($lead->message) $body .= "\nMessage: {$lead->message}\n";
        if ($lead->notes) $body .= "Notes: {$lead->notes}\n";
        $body .= "\nThank you!\n{$biz}";

        // Use branded template if Email/CRM is available
        if (class_exists('OBM_Integration_Emails') && method_exists('OBM_Integration_Emails', 'get_instance')) {
            $emails = OBM_Integration_Emails::get_instance();
            if (method_exists($emails, 'build_html_email')) {
                // Reflection to access private method — fall back to plain if not accessible
            }
        }

        // Build simple branded HTML
        $r = hexdec(substr($brand, 1, 2));
        $g = hexdec(substr($brand, 3, 2));
        $b_val = hexdec(substr($brand, 5, 2));
        $light = sprintf('#%02x%02x%02x', min(255, $r + 200), min(255, $g + 200), min(255, $b_val + 200));
        $lum = (0.299 * $r + 0.587 * $g + 0.114 * $b_val) / 255;
        $text_color = $lum < 0.5 ? '#ffffff' : '#333333';

        $logo_url = '';
        $custom_logo_id = get_theme_mod('custom_logo');
        if ($custom_logo_id) {
            $logo = wp_get_attachment_image_src($custom_logo_id, 'medium');
            if ($logo) $logo_url = $logo[0];
        }
        $logo_block = $logo_url ? '<img src="' . esc_url($logo_url) . '" alt="' . esc_attr($biz) . '" style="max-width:160px;max-height:60px;margin-bottom:8px;">' : '';

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>';
        $html .= '<body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,sans-serif;">';
        $html .= '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:20px 0;"><tr><td align="center">';
        $html .= '<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#fff;border-radius:8px;overflow:hidden;">';
        $html .= '<tr><td style="background:' . esc_attr($brand) . ';padding:20px 30px;text-align:center;">' . $logo_block;
        $html .= '<h1 style="margin:0;font-size:18px;color:' . esc_attr($text_color) . ';">Booking Assignment</h1></td></tr>';
        $html .= '<tr><td style="padding:25px 30px;font-size:15px;line-height:1.7;color:#333;">';
        $html .= '<p>Hi ' . esc_html($staff->name) . ',</p>';
        $html .= '<p>You\'ve been assigned to a booking:</p>';
        $html .= '<table style="width:100%;font-size:14px;margin:15px 0;" cellpadding="6" cellspacing="0">';
        $html .= '<tr style="background:#f9f9f9;"><td style="font-weight:600;width:120px;">Client</td><td>' . esc_html($lead->name) . '</td></tr>';
        $html .= '<tr><td style="font-weight:600;">Date</td><td>' . esc_html($lead->requested_date) . '</td></tr>';
        if ($lead->start_time) $html .= '<tr style="background:#f9f9f9;"><td style="font-weight:600;">Time</td><td>' . esc_html($lead->start_time) . '</td></tr>';
        $html .= '<tr><td style="font-weight:600;">Guests</td><td>' . $lead->guests . ($lead->guests_under_6 ? ' (' . $lead->guests_under_6 . ' under 6)' : '') . '</td></tr>';
        if ($lead->service_duration) $html .= '<tr style="background:#f9f9f9;"><td style="font-weight:600;">Duration</td><td>' . esc_html($lead->service_duration) . '</td></tr>';
        $html .= '<tr><td style="font-weight:600;">Phone</td><td><a href="tel:' . esc_attr($lead->phone) . '">' . esc_html($lead->phone) . '</a></td></tr>';
        $html .= '<tr style="background:#f9f9f9;"><td style="font-weight:600;">Email</td><td><a href="mailto:' . esc_attr($lead->email) . '">' . esc_html($lead->email) . '</a></td></tr>';
        if ($lead->message) $html .= '<tr><td style="font-weight:600;">Message</td><td>' . esc_html($lead->message) . '</td></tr>';
        if ($lead->notes) $html .= '<tr style="background:#f9f9f9;"><td style="font-weight:600;">Notes</td><td>' . esc_html($lead->notes) . '</td></tr>';
        $html .= '</table></td></tr>';
        $html .= '<tr><td style="background:' . esc_attr($light) . ';padding:15px 30px;text-align:center;font-size:12px;color:#666;">';
        $html .= '<p style="margin:0;">' . esc_html($biz) . '</p>';
        $html .= '<p style="margin:4px 0 0;color:#999;">Powered by Omni Booking Manager</p></td></tr>';
        $html .= '</table></td></tr></table></body></html>';

        wp_mail($staff->email, $subject, $html, ['Content-Type: text/html; charset=UTF-8']);
    }

    public function render() {
        $staff = OBM_DB::get_staff(false);
        $label = obm_get('staff_label', 'Staff');
        ?>
        <div class="wrap obm-wrap">
        <h1><?php echo esc_html($label); ?> Management</h1>
        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'digest_saved'): ?>
        <div class="notice notice-success"><p>Digest settings saved.</p></div>
        <?php elseif (isset($_GET['msg'])): ?>
        <div class="notice notice-success"><p><?php echo esc_html($label); ?> <?php echo esc_html($_GET['msg']); ?> successfully.</p></div>
        <?php endif; ?>
        <h2>Add <?php echo esc_html($label); ?></h2>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="obm_add_staff">
            <?php wp_nonce_field('obm_staff_action'); ?>
            <table class="form-table"><tr>
                <th>Name</th><td><input type="text" name="name" required></td>
            </tr><tr>
                <th>Phone</th><td><input type="text" name="phone"></td>
            </tr><tr>
                <th>Email</th><td><input type="email" name="email"></td>
            </tr></table>
            <p><input type="submit" class="button button-primary" value="Add <?php echo esc_attr($label); ?>"></p>
        </form>
        <h2>Current <?php echo esc_html($label); ?> Members</h2>
        <table class="wp-list-table widefat fixed striped">
        <thead><tr><th>Name</th><th>Phone</th><th>Email</th><th>Status</th><th>Digest</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($staff as $s): ?>
        <tr>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="obm_update_staff">
            <input type="hidden" name="staff_id" value="<?php echo $s->id; ?>">
            <?php wp_nonce_field('obm_staff_action'); ?>
            <td><input type="text" name="name" value="<?php echo esc_attr($s->name); ?>"></td>
            <td><input type="text" name="phone" value="<?php echo esc_attr($s->phone); ?>"></td>
            <td><input type="email" name="email" value="<?php echo esc_attr($s->email); ?>"></td>
            <td><label><input type="checkbox" name="active" <?php checked($s->active, 1); ?>> Active</label></td>
            <td><label><input type="checkbox" name="receive_digest" <?php checked(isset($s->receive_digest) ? $s->receive_digest : 1, 1); ?>> Yes</label></td>
            <td style="white-space:nowrap;">
                <input type="submit" class="button" value="Update">
            </form>
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display:inline;margin-left:4px;">
                <input type="hidden" name="action" value="obm_delete_staff">
                <input type="hidden" name="staff_id" value="<?php echo $s->id; ?>">
                <?php wp_nonce_field('obm_staff_action'); ?>
                <input type="submit" class="button" value="Delete" onclick="return confirm('Delete <?php echo esc_js($s->name); ?>? They will be unassigned from any bookings.');" style="color:#d63638;">
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody></table>

        <hr>
        <?php
        $digest_enabled = get_option('obm_digest_enabled', 1);
        $send_admin = get_option('obm_digest_send_admin', 1);
        $digest_day = get_option('obm_digest_day', 'sunday');
        $digest_time = get_option('obm_digest_time', '18:00');
        $next = wp_next_scheduled('obm_weekly_digest');
        $days = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
        ?>
        <h2>Weekly Digest</h2>
        <p>A branded email summary of upcoming bookings, pending leads<?php
            $integrations = class_exists('OBM_Integrations') ? OBM_Integrations::get_instance() : null;
            if ($integrations && $integrations->is_active('waivers')) echo ', waiver status';
            if ($integrations && $integrations->is_active('stripe')) echo ', payment status';
        ?>.</p>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="obm_save_digest_settings">
            <?php wp_nonce_field('obm_digest_settings_action'); ?>
            <table class="form-table">
            <tr><th>Enable</th>
                <td><label><input type="checkbox" name="digest_enabled" <?php checked($digest_enabled); ?>> Send weekly digest</label></td></tr>
            <tr><th>Day / Time</th>
                <td>
                <select name="digest_day">
                    <?php foreach ($days as $d): ?>
                    <option value="<?php echo $d; ?>" <?php selected($digest_day, $d); ?>><?php echo ucfirst($d); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="time" name="digest_time" value="<?php echo esc_attr($digest_time); ?>">
                </td></tr>
            <tr><th>Send to Admin</th>
                <td><label><input type="checkbox" name="send_admin" <?php checked($send_admin); ?>> <?php echo esc_html(get_option('admin_email')); ?></label></td></tr>
            </table>
            <?php if ($next): ?>
            <p style="color:#666;">Next digest: <strong><?php echo date('l, M j, Y \a\t g:i A', $next); ?></strong></p>
            <?php endif; ?>
            <p><input type="submit" class="button button-primary" value="Save Digest Settings"></p>
        </form>
        </div>
        <?php
    }
}
