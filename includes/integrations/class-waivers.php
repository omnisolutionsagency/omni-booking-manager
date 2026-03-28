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
        add_action('wp_ajax_obm_download_waiver', [$this, 'download_waiver']);
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

    public function download_waiver() {
        if (!current_user_can('obm_manage_bookings')) wp_die('Unauthorized');
        $lead_id = intval($_GET['lead_id'] ?? 0);
        $lead = OBM_DB::get_lead($lead_id);
        if (!$lead) wp_die('Lead not found');

        $waiver = $this->get_waiver($lead_id);
        if (!$waiver) wp_die('No signed waiver found for this lead');

        $biz = obm_get('business_name', get_bloginfo('name'));
        $brand = obm_get('brand_color', '#2c5f2d');
        $logo_url = '';
        $custom_logo_id = get_theme_mod('custom_logo');
        if ($custom_logo_id) {
            $logo = wp_get_attachment_image_src($custom_logo_id, 'medium');
            if ($logo) $logo_url = $logo[0];
        }

        ?><!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Waiver - <?php echo esc_html($lead->name); ?> - <?php echo esc_html($biz); ?></title>
<style>
@media print { body{margin:0;} .no-print{display:none!important;} @page{margin:0.75in;} }
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:Georgia,"Times New Roman",serif;color:#333;max-width:800px;margin:0 auto;padding:40px 20px;}
.print-bar{background:#f5f5f5;padding:12px 20px;border-radius:8px;margin-bottom:30px;display:flex;justify-content:space-between;align-items:center;}
.print-bar button{padding:10px 24px;border:none;border-radius:6px;font-size:14px;font-weight:600;cursor:pointer;color:#fff;background:<?php echo esc_attr($brand); ?>;}
.header{text-align:center;padding-bottom:20px;border-bottom:2px solid <?php echo esc_attr($brand); ?>;margin-bottom:25px;}
.header img{max-width:200px;max-height:80px;margin-bottom:10px;}
.header h1{font-size:22px;color:<?php echo esc_attr($brand); ?>;margin-bottom:4px;}
.header p{color:#666;font-size:14px;}
.section{margin-bottom:20px;}
.section h2{font-size:16px;color:<?php echo esc_attr($brand); ?>;border-bottom:1px solid #ddd;padding-bottom:6px;margin-bottom:10px;}
.info-table{width:100%;font-size:14px;margin-bottom:15px;}
.info-table td{padding:5px 10px;vertical-align:top;}
.info-table td:first-child{font-weight:700;width:140px;color:#555;}
.waiver-text{font-size:13px;line-height:1.8;white-space:pre-wrap;padding:15px;background:#fafafa;border:1px solid #eee;border-radius:6px;}
.signature-block{margin-top:20px;padding:20px;border:1px solid #ddd;border-radius:6px;}
.signature-block img{max-width:300px;max-height:120px;display:block;margin:10px 0;}
.signature-line{border-bottom:1px solid #333;display:inline-block;min-width:200px;margin-bottom:4px;}
.meta{font-size:11px;color:#888;margin-top:20px;padding-top:10px;border-top:1px solid #eee;text-align:center;}
</style>
</head>
<body>
<div class="print-bar no-print">
    <span>Waiver for <strong><?php echo esc_html($lead->name); ?></strong></span>
    <div>
        <button onclick="window.print()">Print / Save as PDF</button>
    </div>
</div>

<div class="header">
    <?php if ($logo_url): ?><img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr($biz); ?>"><?php endif; ?>
    <h1><?php echo esc_html($biz); ?></h1>
    <p>Liability & Consent Waiver</p>
</div>

<div class="section">
    <h2>Client Information</h2>
    <table class="info-table">
    <tr><td>Name</td><td><?php echo esc_html($lead->name); ?></td></tr>
    <tr><td>Email</td><td><?php echo esc_html($lead->email); ?></td></tr>
    <tr><td>Phone</td><td><?php echo esc_html($lead->phone); ?></td></tr>
    <tr><td>Booking Date</td><td><?php echo esc_html($lead->requested_date); ?></td></tr>
    <tr><td>Guests</td><td><?php echo $lead->guests; ?><?php if ($lead->guests_under_6): ?> (<?php echo $lead->guests_under_6; ?> under 6)<?php endif; ?></td></tr>
    </table>
</div>

<div class="section">
    <h2>Waiver Agreement</h2>
    <div class="waiver-text"><?php echo esc_html($waiver->waiver_text); ?></div>
</div>

<div class="section">
    <h2>Signature</h2>
    <div class="signature-block">
        <table class="info-table">
        <tr><td>Signed By</td><td><?php echo esc_html($waiver->signed_name); ?></td></tr>
        <tr><td>Date Signed</td><td><?php echo date('F j, Y \a\t g:i A', strtotime($waiver->signed_at)); ?></td></tr>
        <tr><td>IP Address</td><td><?php echo esc_html($waiver->ip_address); ?></td></tr>
        </table>
        <p style="font-weight:700;margin-top:10px;">Signature:</p>
        <img src="<?php echo $waiver->signature_data; ?>" alt="Signature">
    </div>
</div>

<div class="meta">
    Document generated <?php echo date('F j, Y \a\t g:i A'); ?> | <?php echo esc_html($biz); ?> | Powered by Omni Booking Manager
</div>
</body>
</html><?php
        exit;
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
        // Settings rendered as tab under Settings page
    }

    public function save_settings() {
        check_admin_referer('obm_waiver_settings_action');
        update_option('obm_waiver_text', sanitize_textarea_field($_POST['waiver_text']));
        flush_rewrite_rules();
        wp_redirect(admin_url('admin.php?page=obm-settings&tab=waivers&msg=saved'));
        exit;
    }

    public function render_settings() {
        $text = get_option('obm_waiver_text', $this->default_waiver_text());
        ?>
        <div>
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
