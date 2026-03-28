<?php
class OBM_Admin_Settings {
    private static $instance = null;
    public static function get_instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {
        add_action('admin_init', [$this, 'handle_oauth']);
        add_action('admin_post_obm_save_settings', [$this, 'handle_save']);
        add_action('admin_post_obm_disconnect_google', [$this, 'handle_disconnect']);
        add_action('admin_post_obm_set_calendar', [$this, 'handle_set_calendar']);
    }

    public function handle_oauth() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'obm-settings') return;
        if (!isset($_GET['obm_oauth']) || !isset($_GET['code'])) return;
        $gcal = OBM_Google_Calendar::get_instance();
        if ($gcal->handle_oauth_callback($_GET['code'])) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-success"><p>Google Calendar connected!</p></div>';
            });
        }
    }

    public function handle_save() {
        check_admin_referer('obm_settings_action');
        update_option('obm_google_client_id', sanitize_text_field($_POST['client_id']));
        update_option('obm_google_client_secret', sanitize_text_field($_POST['client_secret']));
        // Also save general settings if present
        if (isset($_POST['business_name'])) {
            $settings = obm_get_settings();
            $settings['business_name'] = sanitize_text_field($_POST['business_name']);
            $settings['brand_color'] = sanitize_hex_color($_POST['brand_color'] ?: '#2c5f2d');
            $settings['staff_label'] = sanitize_text_field($_POST['staff_label']);
            $settings['duration_options'] = sanitize_text_field($_POST['duration_options']);
            update_option('obm_settings', $settings);
        }
        wp_redirect(admin_url('admin.php?page=obm-settings&msg=saved'));
        exit;
    }

    public function handle_disconnect() {
        check_admin_referer('obm_settings_action');
        delete_option('obm_google_access_token');
        delete_option('obm_google_refresh_token');
        delete_option('obm_google_token_expires');
        wp_redirect(admin_url('admin.php?page=obm-settings&msg=disconnected'));
        exit;
    }

    public function handle_set_calendar() {
        check_admin_referer('obm_settings_action');
        update_option('obm_google_calendar_id', sanitize_text_field($_POST['calendar_id']));
        wp_redirect(admin_url('admin.php?page=obm-settings&msg=calendar_set'));
        exit;
    }

    public function render() {
        $gcal = OBM_Google_Calendar::get_instance();
        $connected = $gcal->is_connected();
        $settings = obm_get_settings();
        ?>
        <div class="wrap obm-wrap">
        <h1>Booking Manager Settings</h1>
        <?php if (isset($_GET['msg'])): ?>
        <div class="notice notice-success"><p>Settings <?php echo esc_html($_GET['msg']); ?>.</p></div>
        <?php endif; ?>

        <h2>General</h2>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="obm_save_settings">
            <?php wp_nonce_field('obm_settings_action'); ?>
            <table class="form-table">
            <tr><th>Business Name</th><td><input type="text" name="business_name" value="<?php echo esc_attr($settings['business_name'] ?? ''); ?>" class="regular-text"></td></tr>
            <tr><th>Brand Color</th><td><input type="color" name="brand_color" value="<?php echo esc_attr($settings['brand_color'] ?? '#2c5f2d'); ?>"></td></tr>
            <tr><th>Staff Label</th><td><input type="text" name="staff_label" value="<?php echo esc_attr($settings['staff_label'] ?? 'Staff'); ?>" class="regular-text"></td></tr>
            <tr><th>Duration Options</th><td><input type="text" name="duration_options" value="<?php echo esc_attr($settings['duration_options'] ?? ''); ?>" class="large-text"></td></tr>
            <tr><th>Google Client ID</th><td><input type="text" name="client_id" value="<?php echo esc_attr(get_option('obm_google_client_id', '')); ?>" class="regular-text"></td></tr>
            <tr><th>Google Client Secret</th><td><input type="password" name="client_secret" value="<?php echo esc_attr(get_option('obm_google_client_secret', '')); ?>" class="regular-text"></td></tr>
            </table>
            <p><input type="submit" class="button button-primary" value="Save Settings"></p>
        </form>
        <hr>
        <h2>Google Calendar</h2>
        <?php if ($connected): ?>
            <p style="color:green;"><strong>Connected</strong></p>
            <h3>Select Calendar</h3>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="obm_set_calendar">
            <?php wp_nonce_field('obm_settings_action'); ?>
            <select name="calendar_id">
            <?php $cals = $gcal->get_calendars(); $cur = get_option('obm_google_calendar_id', 'primary');
            foreach ($cals as $cal): ?>
                <option value="<?php echo esc_attr($cal['id']); ?>" <?php selected($cur, $cal['id']); ?>><?php echo esc_html($cal['summary']); ?></option>
            <?php endforeach; ?>
            </select>
            <input type="submit" class="button" value="Set Calendar">
            </form>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="obm_disconnect_google">
            <?php wp_nonce_field('obm_settings_action'); ?>
            <p><input type="submit" class="button" value="Disconnect"></p>
            </form>
        <?php else: ?>
            <p>Not connected.</p>
            <?php if (get_option('obm_google_client_id')): ?>
            <p><a href="<?php echo esc_url($gcal->get_auth_url()); ?>" class="button button-primary">Connect to Google Calendar</a></p>
            <?php else: ?>
            <p>Enter API credentials above first.</p>
            <?php endif; ?>
        <?php endif; ?>
        <hr>
        <h2>Setup</h2>
        <p>Authorized redirect URI:<br><code><?php echo admin_url('admin.php?page=obm-settings&obm_oauth=1'); ?></code></p>
        <p>Mobile App URL:<br><code><?php echo home_url('/booking-app/'); ?></code></p>
        <p><a href="<?php echo admin_url('admin.php?page=obm-wizard'); ?>" class="button">Re-run Setup Wizard</a></p>
        </div>
        <?php
    }
}
