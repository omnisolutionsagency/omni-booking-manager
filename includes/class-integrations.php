<?php
class OBM_Integrations {
    private static $instance = null;
    private $integrations = [];
    public static function get_instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {
        $this->register_integrations();
        $this->load_active();
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_post_obm_save_integrations', [$this, 'handle_save']);
    }
    private function register_integrations() {
        $this->integrations = [
            'stripe' => [
                'name' => 'Stripe Payments',
                'desc' => 'Send deposit invoices, track payments, process refunds via Stripe.',
                'class' => 'OBM_Integration_Stripe',
                'file' => 'class-stripe.php',
                'phase' => 1,
            ],
            'emails' => [
                'name' => 'Email Sequences',
                'desc' => 'Automated welcome, reminder, and thank-you emails with customizable templates.',
                'class' => 'OBM_Integration_Emails',
                'file' => 'class-emails.php',
                'phase' => 1,
            ],
            'waivers' => [
                'name' => 'Liability Waivers',
                'desc' => 'Digital waivers sent when a booking is confirmed.',
                'class' => 'OBM_Integration_Waivers',
                'file' => 'class-waivers.php',
                'phase' => 1,
            ],
            'sms' => [
                'name' => 'SMS Notifications',
                'desc' => 'Text confirmations and reminders via Twilio.',
                'class' => 'OBM_Integration_SMS',
                'file' => 'class-sms.php',
                'phase' => 2,
            ],
            'reviews' => [
                'name' => 'Review Collection',
                'desc' => 'Post-trip emails linking to Google Reviews.',
                'class' => 'OBM_Integration_Reviews',
                'file' => 'class-reviews.php',
                'phase' => 2,
            ],
            'portal' => [
                'name' => 'Client Portal',
                'desc' => 'Public page for clients to view booking, sign waiver, pay.',
                'class' => 'OBM_Integration_Portal',
                'file' => 'class-portal.php',
                'phase' => 2,
            ],
        ];
    }
    private function load_active() {
        $active = get_option('obm_active_integrations', []);
        foreach ($active as $key) {
            if (isset($this->integrations[$key])) {
                $info = $this->integrations[$key];
                $file = OBM_PLUGIN_DIR . 'includes/integrations/' . $info['file'];
                if (file_exists($file)) {
                    require_once $file;
                    if (class_exists($info['class'])) {
                        $info['class']::get_instance();
                    }
                }
            }
        }
    }
    public function is_active($key) {
        $active = get_option('obm_active_integrations', []);
        return in_array($key, $active);
    }
    public function get_all() { return $this->integrations; }
    public function add_menu() {
        if (!obm_is_setup_complete()) return;
        add_submenu_page('obm-dashboard', 'Integrations', 'Integrations', 'manage_options', 'obm-integrations', [$this, 'render']);
    }
    public function handle_save() {
        check_admin_referer('obm_integrations_action');
        $active = [];
        if (isset($_POST['integrations']) && is_array($_POST['integrations'])) {
            $active = array_map('sanitize_text_field', $_POST['integrations']);
        }
        update_option('obm_active_integrations', $active);
        wp_redirect(admin_url('admin.php?page=obm-integrations&msg=saved'));
        exit;
    }
    public function render() {
        $active = get_option('obm_active_integrations', []);
        $brand = obm_get('brand_color', '#2c5f2d');
        ?>
        <div class="wrap obm-wrap">
        <h1>Integrations</h1>
        <?php if (isset($_GET['msg'])): ?>
        <div class="notice notice-success"><p>Integrations updated.</p></div>
        <?php endif; ?>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
        <input type="hidden" name="action" value="obm_save_integrations">
        <?php wp_nonce_field('obm_integrations_action'); ?>
        <div class="obm-integrations-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:15px;margin-top:15px;">
        <?php foreach ($this->integrations as $key => $info):
            $on = in_array($key, $active); ?>
        <div class="obm-int-card" style="background:#fff;border:1px solid <?php echo $on ? $brand : '#ddd'; ?>;border-radius:8px;padding:15px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                <label><input type="checkbox" name="integrations[]" value="<?php echo esc_attr($key); ?>" <?php checked($on); ?>>
                <strong><?php echo esc_html($info['name']); ?></strong></label>
                <span style="font-size:11px;color:#999;">Phase <?php echo $info['phase']; ?></span>
            </div>
            <p style="margin:0;font-size:13px;color:#666;"><?php echo esc_html($info['desc']); ?></p>
        </div>
        <?php endforeach; ?>
        </div>
        <p style="margin-top:20px;"><input type="submit" class="button button-primary" value="Save Integrations"></p>
        </form></div>
        <?php
    }
}
