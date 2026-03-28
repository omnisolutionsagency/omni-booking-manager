<?php
class OBM_Integration_Reviews {
    private static $instance = null;
    public static function get_instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'add_menu'], 99);
        add_action('admin_post_obm_reviews_settings', [$this, 'save_settings']);
        add_action('wp_ajax_obm_send_review_request', [$this, 'ajax_send']);
        add_action('obm_lead_status_changed', [$this, 'on_status_change'], 10, 3);
    }

    public function on_status_change($lead_id, $old_status, $new_status) {
        if ($new_status !== 'completed') return;
        if (!get_option('obm_review_auto_send', 0)) return;
        $delay = intval(get_option('obm_review_delay_days', 1));
        wp_schedule_single_event(time() + ($delay * 86400), 'obm_send_review_email', [$lead_id]);
    }

    private function get_default_subject() {
        return 'How was your experience with {business_name}?';
    }

    private function get_default_body() {
        return "Thank you, {client_name}!\n\nWe hope you enjoyed your experience with {business_name}.\n\nIf you have a moment, we'd really appreciate a review. It helps others discover us!\n\n{review_button}\n\nThank you for choosing {business_name}!";
    }

    private function get_merge_tags($lead) {
        $biz = obm_get('business_name', get_bloginfo('name'));
        $staff = $lead->staff_id ? OBM_DB::get_staff_member($lead->staff_id) : null;
        $staff_label = obm_get('staff_label', 'Staff');
        return [
            '{client_name}' => $lead->name,
            '{email}' => $lead->email,
            '{phone}' => $lead->phone,
            '{date}' => $lead->requested_date,
            '{guests}' => $lead->guests,
            '{staff_name}' => $staff ? $staff->name : 'TBD',
            '{staff_label}' => $staff_label,
            '{business_name}' => $biz,
            '{duration}' => $lead->service_duration ?: 'TBD',
        ];
    }

    private function get_logo_url() {
        $custom_logo_id = get_theme_mod('custom_logo');
        if ($custom_logo_id) {
            $logo = wp_get_attachment_image_src($custom_logo_id, 'medium');
            if ($logo) return $logo[0];
        }
        return '';
    }

    private function get_theme_colors() {
        $brand = obm_get('brand_color', '#2c5f2d');
        $r = hexdec(substr($brand, 1, 2));
        $g = hexdec(substr($brand, 3, 2));
        $b = hexdec(substr($brand, 5, 2));
        $light = sprintf('#%02x%02x%02x', min(255, $r + 200), min(255, $g + 200), min(255, $b + 200));
        $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
        $text_on_brand = $luminance < 0.5 ? '#ffffff' : '#333333';
        return ['brand' => $brand, 'light' => $light, 'text_on_brand' => $text_on_brand];
    }

    private function build_review_email($lead) {
        $biz = obm_get('business_name', get_bloginfo('name'));
        $colors = $this->get_theme_colors();
        $logo_url = $this->get_logo_url();
        $tags = $this->get_merge_tags($lead);
        $platforms = $this->get_platforms();

        $subject_tpl = get_option('obm_review_subject', $this->get_default_subject());
        $body_tpl = get_option('obm_review_body', $this->get_default_body());

        $subject = str_replace(array_keys($tags), array_values($tags), $subject_tpl);
        $body_text = str_replace(array_keys($tags), array_values($tags), $body_tpl);

        // Build review buttons for each enabled platform
        $button_html = '<table cellpadding="0" cellspacing="0" style="margin:25px auto;"><tr>';
        $has_buttons = false;
        foreach ($platforms as $key => $p) {
            if (empty($p['enabled']) || empty($p['url'])) continue;
            $has_buttons = true;
            $button_html .= '<td style="padding:0 6px;">'
                . '<a href="' . esc_url($p['url']) . '" style="display:inline-block;padding:12px 24px;background:' . esc_attr($p['color']) . ';color:#ffffff;text-decoration:none;border-radius:8px;font-size:14px;font-weight:600;">'
                . esc_html($p['label']) . '</a></td>';
        }
        $button_html .= '</tr></table>';
        if (!$has_buttons) $button_html = '';

        // Replace {review_button} tag, then convert remaining text
        $body_parts = explode('{review_button}', $body_text);
        $body_html = nl2br(esc_html($body_parts[0])) . $button_html;
        if (isset($body_parts[1])) {
            $body_html .= nl2br(esc_html($body_parts[1]));
        }

        $logo_block = '';
        if ($logo_url) {
            $logo_block = '<img src="' . esc_url($logo_url) . '" alt="' . esc_attr($biz) . '" style="max-width:180px;max-height:80px;margin-bottom:10px;">';
        }

        $html = '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:20px 0;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">
<tr><td style="background:' . esc_attr($colors['brand']) . ';padding:25px 30px;text-align:center;">
' . $logo_block . '
<h1 style="margin:0;font-size:20px;color:' . esc_attr($colors['text_on_brand']) . ';font-weight:600;">' . esc_html($biz) . '</h1>
</td></tr>
<tr><td style="padding:30px;font-size:15px;line-height:1.7;color:#333333;">
' . $body_html . '
</td></tr>
<tr><td style="background:' . esc_attr($colors['light']) . ';padding:20px 30px;text-align:center;font-size:12px;color:#666;">
<p style="margin:0;">' . esc_html($biz) . '</p>
<p style="margin:5px 0 0;color:#999;">Powered by Omni Booking Manager</p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>';

        return ['subject' => $subject, 'html' => $html];
    }

    public function send_review_request($lead_id) {
        $lead = OBM_DB::get_lead($lead_id);
        if (!$lead || empty($lead->email)) return false;

        // Check if at least one platform is enabled
        $platforms = $this->get_platforms();
        $has_platform = false;
        foreach ($platforms as $p) {
            if (!empty($p['enabled']) && !empty($p['url'])) { $has_platform = true; break; }
        }
        if (!$has_platform) return false;

        $email = $this->build_review_email($lead);
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $sent = wp_mail($lead->email, $email['subject'], $email['html'], $headers);

        global $wpdb;
        $wpdb->insert(OBM_DB::get_prefix() . 'email_log', [
            'lead_id' => $lead_id,
            'template_slug' => 'review_request',
            'subject' => $email['subject'],
            'recipient' => $lead->email,
            'status' => $sent ? 'sent' : 'failed',
            'sent_at' => current_time('mysql'),
        ]);

        return $sent;
    }

    public function ajax_send() {
        check_ajax_referer('obm_nonce', 'nonce');
        if (!current_user_can('obm_manage_bookings')) wp_send_json_error('Unauthorized');
        $lead_id = intval($_POST['lead_id']);
        if ($this->send_review_request($lead_id)) {
            wp_send_json_success();
        } else {
            wp_send_json_error('Failed to send or no review URL configured');
        }
    }

    public function add_menu() {
        add_submenu_page('obm-dashboard', 'Review Collection', 'Reviews', 'manage_options', 'obm-int-reviews', [$this, 'render_settings']);
    }

    private function get_platforms() {
        return get_option('obm_review_platforms', [
            'google' => ['enabled' => 0, 'url' => '', 'label' => 'Google', 'color' => '#4285F4', 'icon' => '&#9733;'],
            'facebook' => ['enabled' => 0, 'url' => '', 'label' => 'Facebook', 'color' => '#1877F2', 'icon' => '&#9733;'],
        ]);
    }

    public function save_settings() {
        check_admin_referer('obm_reviews_settings_action');
        update_option('obm_review_auto_send', isset($_POST['auto_send']) ? 1 : 0);
        update_option('obm_review_delay_days', intval($_POST['delay_days'] ?? 1));
        update_option('obm_review_subject', sanitize_text_field($_POST['review_subject']));
        update_option('obm_review_body', sanitize_textarea_field($_POST['review_body']));

        $platforms = [];
        $platform_keys = $_POST['platform_key'] ?? [];
        $platform_labels = $_POST['platform_label'] ?? [];
        $platform_urls = $_POST['platform_url'] ?? [];
        $platform_enabled = $_POST['platform_enabled'] ?? [];
        $platform_colors = $_POST['platform_color'] ?? [];

        foreach ($platform_keys as $i => $key) {
            $key = sanitize_key($key);
            if (empty($key)) continue;
            $platforms[$key] = [
                'enabled' => in_array($key, $platform_enabled) ? 1 : 0,
                'url' => esc_url_raw($platform_urls[$i] ?? ''),
                'label' => sanitize_text_field($platform_labels[$i] ?? $key),
                'color' => sanitize_hex_color($platform_colors[$i] ?? '#333333'),
                'icon' => '&#9733;',
            ];
        }
        update_option('obm_review_platforms', $platforms);

        // Keep legacy option in sync for merge tags
        $google = $platforms['google'] ?? null;
        update_option('obm_review_url', $google && $google['enabled'] ? $google['url'] : '');

        wp_redirect(admin_url('admin.php?page=obm-int-reviews&msg=saved'));
        exit;
    }

    public function render_settings() {
        $review_subject = get_option('obm_review_subject', $this->get_default_subject());
        $review_body = get_option('obm_review_body', $this->get_default_body());
        $platforms = $this->get_platforms();
        ?>
        <div class="wrap obm-wrap">
        <h1>Review Collection</h1>
        <?php if (isset($_GET['msg'])): ?>
        <div class="notice notice-success"><p>Settings saved.</p></div>
        <?php endif; ?>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="obm_reviews_settings">
            <?php wp_nonce_field('obm_reviews_settings_action'); ?>

            <h2>Review Platforms</h2>
            <p>Enable the platforms where you'd like clients to leave reviews. Each enabled platform gets its own button in the review email.</p>
            <table class="wp-list-table widefat fixed" style="max-width:800px;">
            <thead><tr>
                <th style="width:60px;text-align:center;">Enable</th>
                <th style="width:120px;">Platform</th>
                <th>Review URL</th>
                <th style="width:80px;">Button Color</th>
            </tr></thead>
            <tbody>
            <?php
            $default_platforms = [
                'google' => ['label' => 'Google', 'color' => '#4285F4', 'placeholder' => 'https://g.page/r/...'],
                'facebook' => ['label' => 'Facebook', 'color' => '#1877F2', 'placeholder' => 'https://facebook.com/yourpage/reviews'],
            ];
            foreach ($default_platforms as $key => $defaults):
                $p = $platforms[$key] ?? ['enabled' => 0, 'url' => '', 'label' => $defaults['label'], 'color' => $defaults['color']];
            ?>
            <tr>
                <td style="text-align:center;">
                    <input type="hidden" name="platform_key[]" value="<?php echo esc_attr($key); ?>">
                    <input type="checkbox" name="platform_enabled[]" value="<?php echo esc_attr($key); ?>" <?php checked(!empty($p['enabled'])); ?>>
                </td>
                <td>
                    <input type="text" name="platform_label[]" value="<?php echo esc_attr($p['label']); ?>" style="width:100%;">
                </td>
                <td>
                    <input type="url" name="platform_url[]" value="<?php echo esc_attr($p['url']); ?>" class="large-text" placeholder="<?php echo esc_attr($defaults['placeholder']); ?>">
                </td>
                <td>
                    <input type="color" name="platform_color[]" value="<?php echo esc_attr($p['color']); ?>">
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            </table>

            <hr>
            <h2>Automation</h2>
            <table class="form-table">
            <tr><th>Auto-Send After Trip</th>
                <td><label><input type="checkbox" name="auto_send" <?php checked(get_option('obm_review_auto_send', 0)); ?>> Automatically send review request after trip is completed</label></td></tr>
            <tr><th>Delay (days)</th>
                <td><input type="number" name="delay_days" value="<?php echo esc_attr(get_option('obm_review_delay_days', 1)); ?>" min="0" max="14" style="width:80px;"> days after marking as completed</td></tr>
            </table>

            <hr>
            <h2>Review Request Email</h2>
            <p>Customize the email sent to clients. Use <code>{review_button}</code> where you want the platform buttons to appear.</p>
            <p>Merge tags: <code>{client_name}</code> <code>{email}</code> <code>{phone}</code> <code>{date}</code> <code>{guests}</code> <code>{staff_name}</code> <code>{staff_label}</code> <code>{business_name}</code> <code>{duration}</code> <code>{review_button}</code></p>
            <table class="form-table">
            <tr><th>Subject</th>
                <td><input type="text" name="review_subject" value="<?php echo esc_attr($review_subject); ?>" class="large-text"></td></tr>
            <tr><th>Body</th>
                <td><textarea name="review_body" rows="10" class="large-text"><?php echo esc_textarea($review_body); ?></textarea>
                <p class="description">Includes your site logo and brand colors automatically. Each enabled platform above gets a styled button where <code>{review_button}</code> appears.</p></td></tr>
            </table>

            <p><input type="submit" class="button button-primary" value="Save Review Settings"></p>
        </form>
        </div>
        <?php
    }
}

add_action('obm_send_review_email', function($lead_id) {
    if (class_exists('OBM_Integration_Reviews')) {
        OBM_Integration_Reviews::get_instance()->send_review_request($lead_id);
    }
});
