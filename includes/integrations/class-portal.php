<?php
class OBM_Integration_Portal {
    private static $instance = null;
    public static function get_instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('init', [$this, 'rewrite_rules'], 1);
        add_filter('query_vars', [$this, 'query_vars']);
        add_action('template_redirect', [$this, 'serve_portal']);
    }

    public function rewrite_rules() {
        add_rewrite_rule('^my-booking/([a-zA-Z0-9]+)/?$', 'index.php?obm_portal_token=$matches[1]', 'top');
    }

    public function query_vars($vars) {
        $vars[] = 'obm_portal_token';
        return $vars;
    }

    public function generate_token($lead_id) {
        $token = wp_generate_password(32, false);
        OBM_DB::update_lead($lead_id, ['portal_token' => $token]);
        return $token;
    }

    public function serve_portal() {
        $token = get_query_var('obm_portal_token');
        if (!$token) return;

        global $wpdb;
        $lead = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . OBM_DB::get_prefix() . "leads WHERE portal_token=%s", $token
        ));

        if (!$lead) {
            wp_die('Invalid booking link.');
        }

        $biz = obm_get('business_name', get_bloginfo('name'));
        $brand = obm_get('brand_color', '#2c5f2d');
        $staff_label = obm_get('staff_label', 'Staff');
        $staff = $lead->staff_id ? OBM_DB::get_staff_member($lead->staff_id) : null;
        $integrations = OBM_Integrations::get_instance();

        $waiver_signed = ($lead->waiver_status === 'signed');
        $waiver_url = '';
        if ($integrations->is_active('waivers') && !empty($lead->waiver_token)) {
            $waiver_url = home_url('/waiver/' . $lead->waiver_token);
        }

        $payments = [];
        if ($integrations->is_active('stripe')) {
            $payments = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM " . OBM_DB::get_prefix() . "payments WHERE lead_id=%d ORDER BY created_at DESC", $lead->id
            ));
        }

        include OBM_PLUGIN_DIR . 'templates/client-portal.php';
        exit;
    }
}
