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
    }

    public function add_schedule($schedules) {
        $schedules['obm_weekly'] = ['interval' => 604800, 'display' => 'Once Weekly'];
        return $schedules;
    }

    public static function schedule_digest() {
        if (!wp_next_scheduled('obm_weekly_digest')) {
            $next_sunday = strtotime('next sunday 6:00 PM');
            wp_schedule_event($next_sunday, 'obm_weekly', 'obm_weekly_digest');
        }
    }

    public static function unschedule_digest() {
        wp_clear_scheduled_hook('obm_weekly_digest');
    }

    public function send_digest() {
        $admin_email = get_option('admin_email');
        $booked = OBM_DB::get_leads(['status' => 'booked']);
        $proposed = OBM_DB::get_leads(['status' => 'proposed']);
        $next_week = [];
        $today = date('Y-m-d');
        $week_end = date('Y-m-d', strtotime('+7 days'));
        foreach ($booked as $b) {
            if ($b->requested_date >= $today && $b->requested_date <= $week_end) {
                $next_week[] = $b;
            }
        }
        $biz = obm_get('business_name', get_bloginfo('name'));
        $staff_label = obm_get('staff_label', 'Staff');
        $brand_color = obm_get('brand_color', '#2c5f2d');
        $subject = "{$biz} Weekly Digest - " . date('M j, Y');
        ob_start();
        include OBM_PLUGIN_DIR . 'templates/email-digest.php';
        $body = ob_get_clean();
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        wp_mail($admin_email, $subject, $body, $headers);
    }
}
