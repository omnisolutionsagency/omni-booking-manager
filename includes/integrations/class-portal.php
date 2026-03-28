<?php
class OBM_Integration_Portal {
    private static $instance = null;
    public static function get_instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_rewrite_rule('^my-booking/([a-zA-Z0-9]+)/?$', 'index.php?obm_portal_token=$matches[1]', 'top');
        add_filter('query_vars', [$this, 'query_vars']);
        add_action('template_redirect', [$this, 'serve_portal']);
        add_action('admin_menu', [$this, 'add_menu'], 99);
        add_action('wp_ajax_obm_send_portal_link', [$this, 'ajax_send_portal_link']);
    }

    public function query_vars($vars) {
        $vars[] = 'obm_portal_token';
        return $vars;
    }

    public function add_menu() {
        // Hidden page — portal settings are minimal, accessed via Integrations
    }

    public function ajax_send_portal_link() {
        check_ajax_referer('obm_nonce', 'nonce');
        if (!current_user_can('obm_manage_bookings')) wp_send_json_error('Unauthorized');

        $lead_id = intval($_POST['lead_id']);
        $lead = OBM_DB::get_lead($lead_id);
        if (!$lead || empty($lead->email)) wp_send_json_error('No email');

        $token = $lead->portal_token;
        if (empty($token)) {
            $token = $this->generate_token($lead_id);
        }

        $url = home_url('/my-booking/' . $token);
        $biz = obm_get('business_name', get_bloginfo('name'));
        $subject = "{$biz} — Your Booking Details";
        $body = "Hi {$lead->name},\n\nView your booking details, check payment status, and complete any required steps here:\n\n{$url}\n\nThank you!\n{$biz}";

        if (wp_mail($lead->email, $subject, $body)) {
            wp_send_json_success(['portal_url' => $url]);
        } else {
            wp_send_json_error('Failed to send');
        }
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
