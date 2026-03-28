<?php
/**
 * Plugin Name: Omni Booking Manager
 * Plugin URI: https://omnisolutionsagency.com/omni-booking-manager
 * Description: Lead management with Google Calendar, payments, waivers, email sequences, and mobile PWA.
 * Version: 2.0.0
 * Author: Omni Solutions Agency LLC
 * Author URI: https://omnisolutionsagency.com
 * License: GPL v2 or later
 * Text Domain: omni-booking-manager
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) exit;

define('OBM_VERSION', '2.0.0');
define('OBM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('OBM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('OBM_PLUGIN_FILE', __FILE__);

$inc = ['db','db-v2','role','google-calendar','form-handler','integrations','rest-api','admin-dashboard',
    'admin-add-booking','admin-import','admin-staff','admin-blocked-dates','admin-settings','admin-wizard',
    'ajax-handler','digest-email','pwa'];
foreach ($inc as $i) require_once OBM_PLUGIN_DIR . "includes/class-{$i}.php";

class OBM_Plugin {
    private static $instance = null;
    public static function get_instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {
        register_activation_hook(OBM_PLUGIN_FILE, [$this, 'activate']);
        register_deactivation_hook(OBM_PLUGIN_FILE, ['OBM_Digest_Email', 'unschedule_digest']);
        register_deactivation_hook(OBM_PLUGIN_FILE, ['OBM_Role', 'teardown']);
        OBM_PWA::get_instance();
        add_action('init', [$this, 'init']);
        add_action('admin_init', [$this, 'maybe_upgrade']);
    }
    public function activate() {
        OBM_DB::create_tables();
        OBM_DB_V2::upgrade();
        OBM_Role::setup();
        OBM_PWA::activate();
        OBM_Digest_Email::schedule_digest();
    }
    public function maybe_upgrade() {
        $db_ver = get_option('obm_db_version', '1.0.0');
        if (version_compare($db_ver, '2.1.0', '<')) {
            OBM_DB_V2::upgrade();
        }
    }
    public function init() {
        OBM_Role::setup();
        OBM_Form_Handler::get_instance();
        OBM_Ajax_Handler::get_instance();
        OBM_Digest_Email::get_instance();
        OBM_REST_API::get_instance();
        OBM_Integrations::get_instance();
        if (is_admin()) {
            OBM_Admin_Wizard::get_instance();
            OBM_Admin_Dashboard::get_instance();
            OBM_Admin_Add_Booking::get_instance();
            OBM_Admin_Import::get_instance();
            OBM_Admin_Staff::get_instance();
            OBM_Admin_Blocked_Dates::get_instance();
            OBM_Admin_Settings::get_instance();
        }
    }
}

// Helper to get plugin settings
function obm_get_settings() {
    static $settings = null;
    if ($settings === null) {
        $settings = get_option('obm_settings', []);
    }
    return $settings;
}

function obm_get($key, $default = '') {
    $s = obm_get_settings();
    return $s[$key] ?? $default;
}

function obm_is_setup_complete() {
    return (bool) get_option('obm_setup_complete', false);
}

OBM_Plugin::get_instance();
