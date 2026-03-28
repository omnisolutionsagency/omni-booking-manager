<?php
class OBM_Integration_Waivers {
    private static $instance = null;
    public static function get_instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'add_menu'], 99);
        add_action('admin_post_obm_waiver_settings', [$this, 'save_settings']);
        add_rewrite_rule('^waiver/([a-zA-Z0-9]+)/?$', 'index.php?obm_waiver_token=$matches[1]', 'top');
        add_filter('query_vars', [$this, 'query_vars']);
        add_action('template_redirect', [$this, 'serve_waiver']);
        add_action('wp_ajax_nopriv_obm_sign_waiver', [$this, 'handle_sign']);
        add_action('wp_ajax_obm_sign_waiver', [$this, 'handle_sign']);
        add_action('wp_ajax_obm_send_waiver', [$this, 'ajax_send_waiver']);
    }

    public function query_vars($vars) {
        $vars[] = 'obm_waiver_token';
        return $vars;
    }

    public function generate_token($lead_id) {
        $token = wp_generate_password(32, false);
        OBM_DB::update_lead($lead_id, ['waiver_token' => $token, 'waiver_status' => 'pending']);
        return $token;
    }

    public function send_waiver_email($lead_id) {
        $lead = OBM_DB::get_lead($lead_id);
        if (!$lead || empty($lead->email)) return false;

        $token = $lead->waiver_token;
        if (empty($token)) {
            $token = $this->generate_token($lead_id);
        }

        $url = home_url('/waiver/' . $token);
        $biz = obm_get('business_name', get_bloginfo('name'));
        $subject = "{$biz} - Please Sign Your Waiver";
        $body = "Hi {$lead->name},\n\nBefore your booking on {$lead->requested_date}, please review and sign the liability waiver:\n\n{$url}\n\nThank you!\n{$biz}";
        return wp_mail($lead->email, $subject, $body);
    }

    public function ajax_send_waiver() {
        check_ajax_referer('obm_nonce', 'nonce');
        if (!current_user_can('obm_manage_bookings')) wp_send_json_error('Unauthorized');
        $lead_id = intval($_POST['lead_id']);
        if ($this->send_waiver_email($lead_id)) {
            wp_send_json_success();
        } else {
            wp_send_json_error('Failed to send');
        }
    }

    public function serve_waiver() {
        $token = get_query_var('obm_waiver_token');
        if (!$token) return;

        global $wpdb;
        $lead = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . OBM_DB::get_prefix() . "leads WHERE waiver_token=%s", $token
        ));

        if (!$lead) {
            wp_die('Invalid or expired waiver link.');
        }

        $waiver_text = get_option('obm_waiver_text', $this->default_waiver_text());
        $biz = obm_get('business_name', get_bloginfo('name'));
        $brand = obm_get('brand_color', '#2c5f2d');
        $signed = ($lead->waiver_status === 'signed');
        $ajax_url = admin_url('admin-ajax.php');

        include OBM_PLUGIN_DIR . 'templates/waiver-form.php';
        exit;
    }

    public function handle_sign() {
        $token = sanitize_text_field($_POST['token'] ?? '');
        $signature = $_POST['signature'] ?? '';
        $signed_name = sanitize_text_field($_POST['signed_name'] ?? '');

        if (empty($token) || empty($signature) || empty($signed_name)) {
            wp_send_json_error('Missing required fields');
        }

        global $wpdb;
        $p = OBM_DB::get_prefix();
        $lead = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}leads WHERE waiver_token=%s", $token));
        if (!$lead) wp_send_json_error('Invalid token');

        $wpdb->insert($p . 'waivers', [
            'lead_id' => $lead->id,
            'signature_data' => $signature,
            'signed_name' => $signed_name,
            'signed_at' => current_time('mysql'),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
            'waiver_text' => get_option('obm_waiver_text', ''),
            'created_at' => current_time('mysql'),
        ]);

        OBM_DB::update_lead($lead->id, ['waiver_status' => 'signed']);

        $admin_email = get_option('admin_email');
        $biz = obm_get('business_name', get_bloginfo('name'));
        wp_mail($admin_email, "{$biz} - Waiver Signed: {$lead->name}", "{$lead->name} has signed their waiver for {$lead->requested_date}.");

        wp_send_json_success(['message' => 'Waiver signed successfully']);
    }

    public function get_waiver($lead_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . OBM_DB::get_prefix() . "waivers WHERE lead_id=%d ORDER BY id DESC LIMIT 1",
            $lead_id
        ));
    }

    private function default_waiver_text() {
        $biz = obm_get('business_name', get_bloginfo('name'));
        return "ASSUMPTION OF RISK AND WAIVER OF LIABILITY\n\nI, the undersigned, acknowledge that participation in activities provided by {$biz} involves inherent risks including but not limited to: physical injury, property damage, and other hazards."
            . "\n\nI voluntarily assume all risks associated with participation and hereby release, waive, and discharge {$biz}, its owners, employees, agents, and representatives from any and all claims, liabilities, demands, or causes of action arising from my participation."
            . "\n\nI confirm that I am physically able to participate. I agree to follow all safety instructions provided by staff."
            . "\n\nI understand this waiver applies to myself and any minors in my party for whom I am responsible."
            . "\n\nThis agreement shall be binding upon my heirs, executors, and assigns.";
    }

    public function add_menu() {
        add_submenu_page('obm-dashboard', 'Waiver Settings', 'Waivers', 'manage_options', 'obm-int-waivers', [$this, 'render_settings']);
    }

    public function save_settings() {
        check_admin_referer('obm_waiver_settings_action');
        update_option('obm_waiver_text', sanitize_textarea_field($_POST['waiver_text']));
        flush_rewrite_rules();
        wp_redirect(admin_url('admin.php?page=obm-int-waivers&msg=saved'));
        exit;
    }

    public function render_settings() {
        $text = get_option('obm_waiver_text', $this->default_waiver_text());
        ?>
        <div class="wrap obm-wrap">
        <h1>Liability Waiver Settings</h1>
        <?php if (isset($_GET['msg'])): ?>
        <div class="notice notice-success"><p>Settings saved.</p></div>
        <?php endif; ?>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="obm_waiver_settings">
            <?php wp_nonce_field('obm_waiver_settings_action'); ?>
            <table class="form-table">
            <tr><th>Waiver Text</th><td>
                <textarea name="waiver_text" rows="15" class="large-text"><?php echo esc_textarea($text); ?></textarea>
                <p class="description">This text will be displayed to clients before they sign.</p>
            </td></tr>
            </table>
            <p><input type="submit" class="button button-primary" value="Save Waiver Text"></p>
        </form>
        <h2>Preview</h2>
        <p>Waiver signing page: <code><?php echo home_url('/waiver/{token}'); ?></code></p>
        <p>Waivers are automatically sent when a lead is booked (if Email Sequences integration is also active).</p>
        </div>
        <?php
    }
}
