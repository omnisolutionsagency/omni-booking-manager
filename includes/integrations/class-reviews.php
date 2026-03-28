<?php
class OBM_Integration_Reviews {
    private static $instance = null;
    public static function get_instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_post_obm_reviews_settings', [$this, 'save_settings']);
        add_action('wp_ajax_obm_send_review_request', [$this, 'ajax_send']);
    }

    public function send_review_request($lead_id) {
        $lead = OBM_DB::get_lead($lead_id);
        if (!$lead || empty($lead->email)) return false;

        $url = get_option('obm_review_url', '');
        if (empty($url)) return false;

        $biz = obm_get('business_name', get_bloginfo('name'));
        $brand = obm_get('brand_color', '#2c5f2d');
        $subject = "How was your experience with {$biz}?";
        $body = "<div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;'>";
        $body .= "<h2>Thank you, {$lead->name}!</h2>";
        $body .= "<p>We hope you enjoyed your experience with {$biz}.</p>";
        $body .= "<p>If you have a moment, we'd really appreciate a review. It helps others discover us!</p>";
        $body .= "<p style='text-align:center;margin:25px 0;'>";
        $body .= "<a href='" . esc_url($url) . "' style='display:inline-block;padding:14px 30px;background:{$brand};color:#fff;text-decoration:none;border-radius:8px;font-size:16px;font-weight:600;'>Leave a Review</a>";
        $body .= "</p>";
        $body .= "<p>Thank you for choosing {$biz}!</p></div>";

        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $sent = wp_mail($lead->email, $subject, $body, $headers);

        if (class_exists('OBM_Integration_Emails')) {
            global $wpdb;
            $wpdb->insert(OBM_DB::get_prefix() . 'email_log', [
                'lead_id' => $lead_id,
                'template_slug' => 'review_request',
                'subject' => $subject,
                'recipient' => $lead->email,
                'status' => $sent ? 'sent' : 'failed',
                'sent_at' => current_time('mysql'),
            ]);
        }

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
        add_submenu_page('obm-dashboard', 'Review Settings', null, 'manage_options', 'obm-int-reviews', [$this, 'render_settings']);
    }

    public function save_settings() {
        check_admin_referer('obm_reviews_settings_action');
        update_option('obm_review_url', esc_url_raw($_POST['review_url']));
        update_option('obm_review_auto_send', isset($_POST['auto_send']) ? 1 : 0);
        update_option('obm_review_delay_days', intval($_POST['delay_days'] ?? 1));
        wp_redirect(admin_url('admin.php?page=obm-int-reviews&msg=saved'));
        exit;
    }

    public function render_settings() {
        ?>
        <div class="wrap obm-wrap">
        <h1>Review Collection Settings</h1>
        <?php if (isset($_GET['msg'])): ?>
        <div class="notice notice-success"><p>Settings saved.</p></div>
        <?php endif; ?>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="obm_reviews_settings">
            <?php wp_nonce_field('obm_reviews_settings_action'); ?>
            <table class="form-table">
            <tr><th>Google Reviews URL</th>
                <td><input type="url" name="review_url" value="<?php echo esc_attr(get_option('obm_review_url', '')); ?>" class="large-text" placeholder="https://g.page/r/...">
                <p class="description">Go to your Google Business Profile > Share > Copy review link</p></td></tr>
            <tr><th>Auto-Send After Trip</th>
                <td><label><input type="checkbox" name="auto_send" <?php checked(get_option('obm_review_auto_send', 0)); ?>> Automatically send review request after trip is completed</label></td></tr>
            <tr><th>Delay (days)</th>
                <td><input type="number" name="delay_days" value="<?php echo esc_attr(get_option('obm_review_delay_days', 1)); ?>" min="0" max="14" style="width:80px;"> days after marking as completed</td></tr>
            </table>
            <p><input type="submit" class="button button-primary" value="Save Review Settings"></p>
        </form>
        </div>
        <?php
    }
}
