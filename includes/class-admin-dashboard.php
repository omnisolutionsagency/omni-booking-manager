<?php
class OBM_Admin_Dashboard {
    private static $instance = null;
    public static function get_instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function enqueue_assets($hook) {
        if (strpos($hook, 'obm') === false) return;
        wp_enqueue_style('obm-admin', OBM_PLUGIN_URL . 'assets/css/admin.css', [], OBM_VERSION);
        wp_enqueue_script('obm-admin', OBM_PLUGIN_URL . 'assets/js/admin.js', ['jquery'], OBM_VERSION, true);
        wp_localize_script('obm-admin', 'obm', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('obm_nonce'),
            'brand_color' => obm_get('brand_color', '#2c5f2d'),
        ]);
    }

    public function add_menu() {
        if (!obm_is_setup_complete()) return;
        $cap = 'obm_manage_bookings';
        $staff_label = obm_get('staff_label', 'Staff');
        add_menu_page('Bookings', 'Bookings', $cap, 'obm-dashboard', [$this, 'render_dashboard'], 'dashicons-calendar-alt', 30);
        add_submenu_page('obm-dashboard', 'All Leads', 'All Leads', $cap, 'obm-dashboard', [$this, 'render_dashboard']);
        add_submenu_page('obm-dashboard', 'Add Booking', 'Add Booking', $cap, 'obm-add-booking', [OBM_Admin_Add_Booking::get_instance(), 'render']);
        add_submenu_page('obm-dashboard', 'Import', 'Import', 'manage_options', 'obm-import', [OBM_Admin_Import::get_instance(), 'render']);
        add_submenu_page('obm-dashboard', $staff_label, $staff_label, $cap, 'obm-staff', [OBM_Admin_Staff::get_instance(), 'render']);
        add_submenu_page('obm-dashboard', 'Blocked Dates', 'Blocked Dates', $cap, 'obm-blocked-dates', [OBM_Admin_Blocked_Dates::get_instance(), 'render']);
        add_submenu_page('obm-dashboard', 'Settings', 'Settings', 'manage_options', 'obm-settings', [OBM_Admin_Settings::get_instance(), 'render']);
    }

    private function get_duration_options() {
        $raw = obm_get('duration_options', '1 Hour, 1.5 Hours, 2 Hours, 2.5 Hours, 3 Hours, 3.5 Hours, 4 Hours');
        return array_map('trim', explode(',', $raw));
    }

    private function render_calendar() {
        $month = isset($_GET['cal_month']) ? sanitize_text_field($_GET['cal_month']) : date('Y-m');
        $year = intval(substr($month, 0, 4));
        $mon = intval(substr($month, 5, 2));
        $first_day = mktime(0, 0, 0, $mon, 1, $year);
        $days_in_month = date('t', $first_day);
        $start_dow = date('w', $first_day);
        $prev = date('Y-m', mktime(0, 0, 0, $mon - 1, 1, $year));
        $next = date('Y-m', mktime(0, 0, 0, $mon + 1, 1, $year));
        $start_date = date('Y-m-d', $first_day);
        $end_date = date('Y-m-t', $first_day);
        $leads = OBM_DB::get_leads_by_date_range($start_date, $end_date);
        $blocked = OBM_DB::get_blocked_dates();

        $by_date = [];
        foreach ($leads as $l) {
            $d = $l->requested_date;
            if (!isset($by_date[$d])) $by_date[$d] = [];
            $by_date[$d][] = $l;
        }
        $blocked_days = [];
        foreach ($blocked as $b) {
            $s = strtotime($b->date_start);
            $e = strtotime($b->date_end);
            for ($t = $s; $t <= $e; $t += 86400) {
                $blocked_days[date('Y-m-d', $t)] = $b->reason;
            }
        }
        ?>
        <div class="obm-calendar-wrap">
        <div class="obm-cal-nav">
            <a href="?page=obm-dashboard&cal_month=<?php echo $prev; ?>" class="button">&laquo; Prev</a>
            <h3><?php echo date('F Y', $first_day); ?></h3>
            <a href="?page=obm-dashboard&cal_month=<?php echo $next; ?>" class="button">Next &raquo;</a>
        </div>
        <table class="obm-cal-table">
        <thead><tr><th>Sun</th><th>Mon</th><th>Tue</th><th>Wed</th><th>Thu</th><th>Fri</th><th>Sat</th></tr></thead>
        <tbody><tr>
        <?php
        for ($i = 0; $i < $start_dow; $i++) echo '<td class="obm-cal-empty"></td>';
        for ($day = 1; $day <= $days_in_month; $day++):
            $date_str = sprintf('%04d-%02d-%02d', $year, $mon, $day);
            $is_blocked = isset($blocked_days[$date_str]);
            $has_leads = isset($by_date[$date_str]);
            $cls = 'obm-cal-day';
            if ($is_blocked) $cls .= ' obm-cal-blocked';
            if ($date_str === date('Y-m-d')) $cls .= ' obm-cal-today';
        ?>
            <td class="<?php echo $cls; ?>">
            <span class="obm-cal-num"><?php echo $day; ?></span>
            <?php if ($has_leads): foreach ($by_date[$date_str] as $ev): ?>
            <div class="obm-cal-event obm-cal-<?php echo $ev->status; ?>">
                <?php echo esc_html($ev->name); ?>
                <?php if ($ev->start_time): ?><small><?php echo esc_html($ev->start_time); ?></small><?php endif; ?>
            </div>
            <?php endforeach; endif; ?>
            <?php if ($is_blocked): ?><div class="obm-cal-block-label">Blocked</div><?php endif; ?>
            </td>
        <?php
            if (($start_dow + $day) % 7 === 0 && $day < $days_in_month) echo '</tr><tr>';
        endfor;
        $remaining = (7 - ($start_dow + $days_in_month) % 7) % 7;
        for ($i = 0; $i < $remaining; $i++) echo '<td class="obm-cal-empty"></td>';
        ?>
        </tr></tbody></table></div>
        <?php
    }

    public function render_dashboard() {
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $args = [];
        if ($status) $args['status'] = $status;
        $leads = OBM_DB::get_leads($args);
        $staff_list = OBM_DB::get_staff();
        $staff_label = obm_get('staff_label', 'Staff');
        $durations = $this->get_duration_options();
        $counts = [
            'proposed' => count(OBM_DB::get_leads(['status' => 'proposed'])),
            'booked' => count(OBM_DB::get_leads(['status' => 'booked'])),
            'declined' => count(OBM_DB::get_leads(['status' => 'declined'])),
            'completed' => count(OBM_DB::get_leads(['status' => 'completed']))
        ];
        $biz = obm_get('business_name', get_bloginfo('name'));

        if (isset($_GET['msg']) && $_GET['msg'] === 'booking_added') {
            echo '<div class="notice notice-success"><p>Booking added successfully.</p></div>';
        }
        if (isset($_GET['setup']) && $_GET['setup'] === 'complete') {
            echo '<div class="notice notice-success"><p>Setup complete! Your booking manager is ready.</p></div>';
        }
        ?>
        <div class="wrap obm-wrap">
        <h1><?php echo esc_html($biz); ?></h1>
        <div class="obm-stats">
            <a href="?page=obm-dashboard" class="obm-stat-box"><span class="num"><?php echo array_sum($counts); ?></span><span class="lbl">Total</span></a>
            <a href="?page=obm-dashboard&status=proposed" class="obm-stat-box proposed"><span class="num"><?php echo $counts['proposed']; ?></span><span class="lbl">Proposed</span></a>
            <a href="?page=obm-dashboard&status=booked" class="obm-stat-box booked"><span class="num"><?php echo $counts['booked']; ?></span><span class="lbl">Booked</span></a>
            <a href="?page=obm-dashboard&status=declined" class="obm-stat-box declined"><span class="num"><?php echo $counts['declined']; ?></span><span class="lbl">Declined</span></a>
            <a href="?page=obm-dashboard&status=completed" class="obm-stat-box completed"><span class="num"><?php echo $counts['completed']; ?></span><span class="lbl">Completed</span></a>
        </div>
        <?php $this->render_calendar(); ?>
        <h2 style="margin-top:20px;">Leads<?php if ($status) echo ' - ' . ucfirst($status); ?></h2>
        <table class="wp-list-table widefat fixed striped obm-table">
        <thead><tr>
            <th>Name</th><th>Date</th><th>Time</th><th>Guests</th><th>Status</th><th>Payment</th><th><?php echo esc_html($staff_label); ?></th><th>Actions</th>
        </tr></thead><tbody>
        <?php if (empty($leads)): ?>
            <tr><td colspan="8">No leads found.</td></tr>
        <?php else: foreach ($leads as $l):
            $staff = $l->staff_id ? OBM_DB::get_staff_member($l->staff_id) : null;
            $dup = $l->duplicate_flag ? ' obm-duplicate' : '';
        ?>
            <tr class="obm-lead-row<?php echo $dup; ?>" data-id="<?php echo $l->id; ?>">
            <td>
                <strong><?php echo esc_html($l->name); ?></strong>
                <?php if ($l->duplicate_flag): ?><span class="obm-dup-badge">DUP</span><?php endif; ?>
                <br><small><?php echo esc_html($l->email); ?> | <?php echo esc_html($l->phone); ?></small>
            </td>
            <td><?php echo esc_html($l->requested_date); ?>
                <?php if ($l->backup_date): ?><br><small>Alt: <?php echo esc_html($l->backup_date); ?></small><?php endif; ?>
            </td>
            <td><?php echo $l->start_time ? esc_html($l->start_time) : '-'; ?></td>
            <td><?php echo $l->guests; ?><?php if ($l->guests_under_6): ?> <small>(<?php echo $l->guests_under_6; ?> &lt;6)</small><?php endif; ?></td>
            <td><span class="obm-status obm-status-<?php echo $l->status; ?>"><?php echo ucfirst($l->status); ?></span></td>
            <td><span class="obm-pay obm-pay-<?php echo $l->payment_status; ?>"><?php echo ucfirst($l->payment_status ?: 'none'); ?></span></td>
            <td><?php echo $staff ? esc_html($staff->name) : '-'; ?></td>
            <td><button class="button obm-expand-btn" data-id="<?php echo $l->id; ?>">Details</button></td>
            </tr>
            <tr class="obm-detail-row" id="obm-detail-<?php echo $l->id; ?>" style="display:none;">
            <td colspan="8"><div class="obm-detail-panel">
                <div class="obm-detail-grid">
                <div class="obm-detail-info">
                    <p><strong>Message:</strong> <?php echo esc_html($l->message); ?></p>
                    <p><strong>Submitted:</strong> <?php echo $l->created_at; ?></p>
                    <?php if ($l->backup_date && $l->backup_date !== $l->requested_date): ?>
                    <p><strong>Backup Date:</strong> <?php echo esc_html($l->backup_date); ?>
                    <button class="button button-small obm-use-backup" data-id="<?php echo $l->id; ?>">Use Backup Date</button></p>
                    <?php endif; ?>
                </div>
                <div class="obm-detail-actions">
                    <label>Start Time: <input type="time" class="obm-start-time" data-id="<?php echo $l->id; ?>" value="<?php echo esc_attr($l->start_time); ?>"></label>
                    <label>Duration: <select class="obm-duration" data-id="<?php echo $l->id; ?>">
                        <option value="">Select</option>
                        <?php foreach ($durations as $d): ?>
                        <option value="<?php echo esc_attr($d); ?>" <?php selected($l->service_duration, $d); ?>><?php echo esc_html($d); ?></option>
                        <?php endforeach; ?>
                    </select></label>
                    <label><?php echo esc_html($staff_label); ?>: <select class="obm-staff" data-id="<?php echo $l->id; ?>">
                        <option value="0">Unassigned</option>
                        <?php foreach ($staff_list as $s): ?>
                        <option value="<?php echo $s->id; ?>" <?php selected($l->staff_id, $s->id); ?>><?php echo esc_html($s->name); ?></option>
                        <?php endforeach; ?>
                    </select></label>
                    <label>Payment: <select class="obm-payment" data-id="<?php echo $l->id; ?>">
                        <?php foreach (['none', 'deposit', 'full'] as $ps): ?>
                        <option value="<?php echo $ps; ?>" <?php selected($l->payment_status, $ps); ?>><?php echo ucfirst($ps); ?></option>
                        <?php endforeach; ?>
                    </select></label>
                    <?php if ($l->status === 'proposed'): ?>
                    <div class="obm-btn-group">
                    <button class="button button-primary obm-action-btn" data-id="<?php echo $l->id; ?>" data-action="book">Book</button>
                    <button class="button obm-action-btn" data-id="<?php echo $l->id; ?>" data-action="decline">Decline</button>
                    </div>
                    <?php elseif ($l->status === 'booked'): ?>
                    <div class="obm-btn-group">
                    <button class="button button-primary obm-action-btn" data-id="<?php echo $l->id; ?>" data-action="complete">Completed</button>
                    <button class="button obm-action-btn" data-id="<?php echo $l->id; ?>" data-action="decline">Cancel</button>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="obm-detail-notes">
                    <label><strong>Notes:</strong>
                    <textarea class="obm-notes" data-id="<?php echo $l->id; ?>"><?php echo esc_textarea($l->notes); ?></textarea></label>
                    <button class="button obm-save-notes" data-id="<?php echo $l->id; ?>">Save Notes</button>
                </div>
                </div>
            </div></td></tr>
        <?php endforeach; endif; ?>
        </tbody></table></div>
        <?php
    }
}
