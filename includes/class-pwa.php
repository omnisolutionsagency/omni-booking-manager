<?php
class OBM_PWA {
    private static $instance = null;
    public static function get_instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {
        add_action('init', [$this, 'rewrite_rules'], 1);
        add_action('template_redirect', [$this, 'serve_app']);
        add_filter('query_vars', [$this, 'query_vars']);
    }

    public function rewrite_rules() {
        add_rewrite_rule('^booking-app/?$', 'index.php?obm_app=1', 'top');
    }

    public function query_vars($vars) {
        $vars[] = 'obm_app';
        return $vars;
    }

    public function serve_app() {
        if (!get_query_var('obm_app')) return;
        if (!is_user_logged_in()) {
            wp_redirect(wp_login_url(home_url('/booking-app/')));
            exit;
        }
        if (!current_user_can('obm_manage_bookings')) {
            wp_die('Access denied.');
        }
        $nonce = wp_create_nonce('wp_rest');
        $api = rest_url('obm/v1/');
        $user = wp_get_current_user()->display_name;
        $brand_color = obm_get('brand_color', '#2c5f2d');
        $biz_name = obm_get('business_name', get_bloginfo('name'));
        $staff_label = obm_get('staff_label', 'Staff');
        $duration_opts = array_map('trim', explode(',', obm_get('duration_options', '')));
        include OBM_PLUGIN_DIR . 'app/index.php';
        exit;
    }

    public static function activate() {
        $pwa = self::get_instance();
        $pwa->rewrite_rules();
        flush_rewrite_rules();
    }
}
