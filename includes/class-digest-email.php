<?php
class OBM_Digest_Email {
    private static $instance = null;
    public static function get_instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {
        add_action('obm_weekly_digest', [$this, 'send_digest']);
        add_filter('cron_schedules', [$this, 'add_schedule']);
        add_action('admin_menu', [$this, 'add_menu'], 99);
        add_action('admin_post_obm_save_digest_settings', [$this, 'save_settings']);
    }

    public function add_schedule($schedules) {
        $schedules['obm_weekly'] = ['interval' => 604800, 'display' => 'Once Weekly'];
        return $schedules;
    }

    public static function schedule_digest() {
        if (!wp_next_scheduled('obm_weekly_digest')) {
            $day = get_option('obm_digest_day', 'sunday');
            $time = get_option('obm_digest_time', '18:00');
            $next = strtotime("next {$day} {$time}");
            wp_schedule_event($next, 'obm_weekly', 'obm_weekly_digest');
        }
    }

    public static function unschedule_digest() {
        wp_clear_scheduled_hook('obm_weekly_digest');
    }

    private function get_recipients() {
        $recipients = [];
        if (get_option('obm_digest_send_admin', 1)) {
            $recipients[] = get_option('admin_email');
        }
        $staff = OBM_DB::get_staff(false);
        foreach ($staff as $s) {
            if (!empty($s->email) && $s->active && (!isset($s->receive_digest) || $s->receive_digest)) {
                $recipients[] = $s->email;
            }
        }
        return array_unique($recipients);
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

    public function send_digest() {
        if (!get_option('obm_digest_enabled', 1)) return;

        $recipients = $this->get_recipients();
        if (empty($recipients)) return;

        $booked = OBM_DB::get_leads(['status' => 'booked']);
        $proposed = OBM_DB::get_leads(['status' => 'proposed']);
        $today = date('Y-m-d');
        $week_end = date('Y-m-d', strtotime('+7 days'));
        $next_week = [];
        foreach ($booked as $b) {
            if ($b->requested_date >= $today && $b->requested_date <= $week_end) {
                $next_week[] = $b;
            }
        }

        $biz = obm_get('business_name', get_bloginfo('name'));
        $staff_label = obm_get('staff_label', 'Staff');
        $colors = $this->get_theme_colors();
        $logo_url = $this->get_logo_url();
        $integrations = class_exists('OBM_Integrations') ? OBM_Integrations::get_instance() : null;
        $show_waivers = $integrations && $integrations->is_active('waivers');
        $show_payments = $integrations && $integrations->is_active('stripe');

        $subject = "{$biz} Weekly Digest - " . date('M j, Y');

        // Build body
        $body = $this->build_digest_html($biz, $next_week, $proposed, $booked, $staff_label, $colors, $logo_url, $show_waivers, $show_payments);

        $headers = ['Content-Type: text/html; charset=UTF-8'];
        foreach ($recipients as $email) {
            wp_mail($email, $subject, $body, $headers);
        }
    }

    private function build_digest_html($biz, $next_week, $proposed, $booked, $staff_label, $colors, $logo_url, $show_waivers, $show_payments) {
        $logo_block = '';
        if ($logo_url) {
            $logo_block = '<img src="' . esc_url($logo_url) . '" alt="' . esc_attr($biz) . '" style="max-width:160px;max-height:60px;margin-bottom:8px;">';
        }

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>';
        $html .= '<body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,Helvetica,sans-serif;">';
        $html .= '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:20px 0;"><tr><td align="center">';
        $html .= '<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">';

        // Header
        $html .= '<tr><td style="background:' . esc_attr($colors['brand']) . ';padding:20px 30px;text-align:center;">';
        $html .= $logo_block;
        $html .= '<h1 style="margin:0;font-size:18px;color:' . esc_attr($colors['text_on_brand']) . ';">Weekly Digest</h1>';
        $html .= '<p style="margin:4px 0 0;font-size:13px;color:' . esc_attr($colors['text_on_brand']) . ';opacity:.8;">' . date('M j, Y') . '</p>';
        $html .= '</td></tr>';

        // Stats bar
        $html .= '<tr><td style="padding:15px 30px;background:' . esc_attr($colors['light']) . ';text-align:center;">';
        $html .= '<table width="100%" cellpadding="0" cellspacing="0"><tr>';
        $html .= '<td style="text-align:center;"><strong style="font-size:22px;">' . count($next_week) . '</strong><br><span style="font-size:11px;color:#666;">This Week</span></td>';
        $html .= '<td style="text-align:center;"><strong style="font-size:22px;">' . count($proposed) . '</strong><br><span style="font-size:11px;color:#666;">Pending</span></td>';
        $html .= '<td style="text-align:center;"><strong style="font-size:22px;">' . count($booked) . '</strong><br><span style="font-size:11px;color:#666;">Booked</span></td>';
        $html .= '</tr></table></td></tr>';

        // Upcoming bookings
        $html .= '<tr><td style="padding:20px 30px;">';
        $html .= '<h2 style="margin:0 0 12px;font-size:16px;color:' . esc_attr($colors['brand']) . ';">Upcoming Bookings (Next 7 Days)</h2>';
        if (empty($next_week)) {
            $html .= '<p style="color:#888;">No bookings this week.</p>';
        } else {
            $html .= '<table width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;">';
            $html .= '<tr style="background:' . esc_attr($colors['brand']) . ';color:' . esc_attr($colors['text_on_brand']) . ';">';
            $html .= '<th style="padding:8px;text-align:left;">Name</th><th style="padding:8px;">Date</th><th style="padding:8px;">Guests</th><th style="padding:8px;">' . esc_html($staff_label) . '</th>';
            if ($show_waivers) $html .= '<th style="padding:8px;">Waiver</th>';
            if ($show_payments) $html .= '<th style="padding:8px;">Payment</th>';
            $html .= '</tr>';
            foreach ($next_week as $b) {
                $s = $b->staff_id ? OBM_DB::get_staff_member($b->staff_id) : null;
                $html .= '<tr style="border-bottom:1px solid #eee;">';
                $html .= '<td style="padding:8px;">' . esc_html($b->name) . '</td>';
                $html .= '<td style="padding:8px;text-align:center;">' . esc_html($b->requested_date) . '</td>';
                $html .= '<td style="padding:8px;text-align:center;">' . $b->guests . '</td>';
                $html .= '<td style="padding:8px;text-align:center;">' . ($s ? esc_html($s->name) : 'Unassigned') . '</td>';
                if ($show_waivers) {
                    $ws = isset($b->waiver_status) ? $b->waiver_status : '';
                    $wc = $ws === 'signed' ? 'color:green;' : 'color:#d63638;';
                    $wt = $ws === 'signed' ? 'Signed' : 'Pending';
                    $html .= '<td style="padding:8px;text-align:center;' . $wc . 'font-weight:600;">' . $wt . '</td>';
                }
                if ($show_payments) {
                    $ps = $b->payment_status ?: 'none';
                    $pc = $ps === 'full' ? 'color:green;' : ($ps === 'deposit' ? 'color:#856404;' : 'color:#d63638;');
                    $html .= '<td style="padding:8px;text-align:center;' . $pc . 'font-weight:600;">' . ucfirst($ps) . '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</table>';
        }
        $html .= '</td></tr>';

        // Pending proposed
        if (!empty($proposed)) {
            $html .= '<tr><td style="padding:0 30px 20px;">';
            $html .= '<h2 style="margin:0 0 12px;font-size:16px;color:' . esc_attr($colors['brand']) . ';">Pending Leads (' . count($proposed) . ')</h2>';
            $html .= '<table width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;">';
            foreach (array_slice($proposed, 0, 10) as $p) {
                $html .= '<tr style="border-bottom:1px solid #eee;">';
                $html .= '<td style="padding:6px 0;"><strong>' . esc_html($p->name) . '</strong><br><span style="color:#888;font-size:12px;">' . esc_html($p->email) . ' | ' . esc_html($p->phone) . '</span></td>';
                $html .= '<td style="padding:6px 0;text-align:right;">' . esc_html($p->requested_date) . '<br><span style="color:#888;font-size:12px;">' . $p->guests . ' guests</span></td>';
                $html .= '</tr>';
            }
            if (count($proposed) > 10) {
                $html .= '<tr><td colspan="2" style="padding:6px 0;color:#888;font-size:12px;">+ ' . (count($proposed) - 10) . ' more...</td></tr>';
            }
            $html .= '</table></td></tr>';
        }

        // Footer
        $html .= '<tr><td style="background:' . esc_attr($colors['light']) . ';padding:15px 30px;text-align:center;font-size:12px;color:#666;">';
        $html .= '<p style="margin:0;">' . esc_html($biz) . '</p>';
        $html .= '<p style="margin:4px 0 0;color:#999;">Powered by Omni Booking Manager</p>';
        $html .= '</td></tr>';

        $html .= '</table></td></tr></table></body></html>';
        return $html;
    }

    public function add_menu() {
        // Digest settings are rendered within the Staff page, no separate submenu
    }

    public function save_settings() {
        check_admin_referer('obm_digest_settings_action');
        update_option('obm_digest_enabled', isset($_POST['digest_enabled']) ? 1 : 0);
        update_option('obm_digest_send_admin', isset($_POST['send_admin']) ? 1 : 0);
        update_option('obm_digest_day', sanitize_text_field($_POST['digest_day']));
        update_option('obm_digest_time', sanitize_text_field($_POST['digest_time']));

        // Reschedule with new day/time
        self::unschedule_digest();
        if (isset($_POST['digest_enabled'])) {
            self::schedule_digest();
        }

        wp_redirect(admin_url('admin.php?page=obm-staff&msg=digest_saved'));
        exit;
    }

    public function render_settings() {
        $enabled = get_option('obm_digest_enabled', 1);
        $send_admin = get_option('obm_digest_send_admin', 1);
        $day = get_option('obm_digest_day', 'sunday');
        $time = get_option('obm_digest_time', '18:00');
        $next = wp_next_scheduled('obm_weekly_digest');
        $staff = OBM_DB::get_staff(false);
        $days = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
        ?>
        <div class="wrap obm-wrap">
        <h1>Weekly Digest</h1>
        <?php if (isset($_GET['msg'])): ?>
        <div class="notice notice-success"><p>Digest settings saved.</p></div>
        <?php endif; ?>

        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="obm_save_digest_settings">
            <?php wp_nonce_field('obm_digest_settings_action'); ?>

            <h2>Settings</h2>
            <table class="form-table">
            <tr><th>Enable Digest</th>
                <td><label><input type="checkbox" name="digest_enabled" <?php checked($enabled); ?>> Send weekly digest email</label></td></tr>
            <tr><th>Day</th>
                <td><select name="digest_day">
                    <?php foreach ($days as $d): ?>
                    <option value="<?php echo $d; ?>" <?php selected($day, $d); ?>><?php echo ucfirst($d); ?></option>
                    <?php endforeach; ?>
                </select></td></tr>
            <tr><th>Time</th>
                <td><input type="time" name="digest_time" value="<?php echo esc_attr($time); ?>"></td></tr>
            <tr><th>Send to Admin</th>
                <td><label><input type="checkbox" name="send_admin" <?php checked($send_admin); ?>> <?php echo esc_html(get_option('admin_email')); ?></label></td></tr>
            </table>

            <h2>Staff Recipients</h2>
            <p>Staff members with "Digest" checked on the <a href="<?php echo admin_url('admin.php?page=obm-staff'); ?>">Staff page</a> will also receive the digest. Only staff with an email address are included.</p>
            <table class="widefat" style="max-width:500px;">
            <thead><tr><th>Name</th><th>Email</th><th style="text-align:center;">Receives Digest</th></tr></thead>
            <tbody>
            <?php foreach ($staff as $s): ?>
            <tr>
                <td><?php echo esc_html($s->name); ?><?php if (!$s->active): ?> <span style="color:#999;">(inactive)</span><?php endif; ?></td>
                <td><?php echo esc_html($s->email ?: '—'); ?></td>
                <td style="text-align:center;">
                    <?php if (!empty($s->email) && $s->active): ?>
                    <?php echo (isset($s->receive_digest) ? $s->receive_digest : 1) ? '<span style="color:green;">Yes</span>' : '<span style="color:#999;">No</span>'; ?>
                    <?php else: ?>
                    <span style="color:#999;">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            </table>

            <?php if ($next): ?>
            <p style="margin-top:15px;color:#666;">Next digest scheduled: <strong><?php echo date('l, M j, Y \a\t g:i A', $next); ?></strong></p>
            <?php elseif ($enabled): ?>
            <p style="margin-top:15px;color:#d63638;">Digest is enabled but not scheduled. Save settings to schedule it.</p>
            <?php endif; ?>

            <p style="margin-top:15px;"><input type="submit" class="button button-primary" value="Save Digest Settings"></p>
        </form>

        <hr>
        <h2>Content</h2>
        <p>The digest includes:</p>
        <ul style="list-style:disc;padding-left:20px;">
            <li>Stats summary (bookings this week, pending leads, total booked)</li>
            <li>Upcoming bookings for the next 7 days with name, date, guests, staff</li>
            <?php if (class_exists('OBM_Integrations') && OBM_Integrations::get_instance()->is_active('waivers')): ?>
            <li>Waiver status per booking (signed/pending)</li>
            <?php endif; ?>
            <?php if (class_exists('OBM_Integrations') && OBM_Integrations::get_instance()->is_active('stripe')): ?>
            <li>Payment status per booking (none/deposit/full)</li>
            <?php endif; ?>
            <li>Pending proposed leads (up to 10)</li>
        </ul>
        <p>The email uses your site logo and brand colors automatically.</p>
        </div>
        <?php
    }
}
