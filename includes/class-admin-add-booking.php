<?php
class OBM_Admin_Add_Booking {
    private static $instance = null;
    public static function get_instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_post_obm_add_booking', [$this, 'handle_add']);
    }
    public function add_menu() {
        if (!obm_is_setup_complete()) return;
        add_submenu_page('obm-dashboard', 'Add Booking', 'Add Booking', 'obm_manage_bookings', 'obm-add-booking', [$this, 'render']);
    }
    public function handle_add() {
        check_admin_referer('obm_add_booking_action');
        $fields = ['name', 'email', 'phone', 'requested_date', 'start_time', 'backup_date', 'message', 'notes'];
        $data = [];
        foreach ($fields as $f) $data[$f] = sanitize_text_field($_POST[$f] ?? '');
        $data['guests'] = intval($_POST['guests'] ?? 0);
        $data['guests_under_6'] = intval($_POST['guests_under_6'] ?? 0);
        $data['status'] = sanitize_text_field($_POST['status'] ?? 'proposed');
        $data['service_duration'] = sanitize_text_field($_POST['service_duration'] ?? '');
        $data['payment_status'] = sanitize_text_field($_POST['payment_status'] ?? 'none');
        $data['staff_id'] = intval($_POST['staff_id'] ?? 0);
        $data['email'] = sanitize_email($_POST['email'] ?? '');
        $data['message'] = sanitize_textarea_field($_POST['message'] ?? '');
        $data['notes'] = sanitize_textarea_field($_POST['notes'] ?? '');
        $lead_id = OBM_DB::insert_lead($data);
        if ($lead_id) {
            $lead = OBM_DB::get_lead($lead_id);
            $gcal = OBM_Google_Calendar::get_instance();
            if ($gcal->is_connected()) {
                $event = $gcal->create_event($lead);
                if ($event && isset($event['id'])) {
                    OBM_DB::update_lead($lead_id, ['google_event_id' => $event['id']]);
                }
                if ($data['status'] === 'booked' && $lead->google_event_id) {
                    $staff = $lead->staff_id ? OBM_DB::get_staff_member($lead->staff_id) : null;
                    $gcal->update_event_booked($lead->google_event_id, $lead, $staff);
                }
            }
            wp_redirect(admin_url('admin.php?page=obm-dashboard&msg=booking_added'));
        } else {
            wp_redirect(admin_url('admin.php?page=obm-add-booking&msg=error'));
        }
        exit;
    }
    public function render() {
        $staff_list = OBM_DB::get_staff();
        $staff_label = obm_get('staff_label', 'Staff');
        $durations = array_map('trim', explode(',', obm_get('duration_options', '1 Hour, 1.5 Hours, 2 Hours, 2.5 Hours, 3 Hours, 3.5 Hours, 4 Hours')));
        ?>
        <div class="wrap obm-wrap">
        <h1>Add Booking</h1>
        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'error'): ?>
        <div class="notice notice-error"><p>Error adding booking.</p></div>
        <?php endif; ?>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
        <input type="hidden" name="action" value="obm_add_booking">
        <?php wp_nonce_field('obm_add_booking_action'); ?>
        <table class="form-table">
        <tr><th>Name *</th><td><input type="text" name="name" class="regular-text" required></td></tr>
        <tr><th>Email</th><td><input type="email" name="email" class="regular-text"></td></tr>
        <tr><th>Phone</th><td><input type="text" name="phone" class="regular-text"></td></tr>
        <tr><th>Date *</th><td><input type="date" name="requested_date" required></td></tr>
        <tr><th>Backup Date</th><td><input type="date" name="backup_date"></td></tr>
        <tr><th>Start Time</th><td><input type="time" name="start_time"></td></tr>
        <tr><th>Guests</th><td><input type="number" name="guests" value="0" min="0"></td></tr>
        <tr><th>Under 6</th><td><input type="number" name="guests_under_6" value="0" min="0"></td></tr>
        <tr><th>Status</th><td><select name="status">
            <option value="proposed">Proposed</option>
            <option value="booked">Booked</option>
        </select></td></tr>
        <tr><th>Duration</th><td><select name="service_duration">
            <option value="">Select</option>
            <?php foreach ($durations as $d): ?>
            <option value="<?php echo esc_attr($d); ?>"><?php echo esc_html($d); ?></option>
            <?php endforeach; ?>
        </select></td></tr>
        <tr><th><?php echo esc_html($staff_label); ?></th><td><select name="staff_id">
            <option value="0">Unassigned</option>
            <?php foreach ($staff_list as $s): ?>
            <option value="<?php echo $s->id; ?>"><?php echo esc_html($s->name); ?></option>
            <?php endforeach; ?>
        </select></td></tr>
        <tr><th>Payment</th><td><select name="payment_status">
            <option value="none">None</option>
            <option value="deposit">Deposit</option>
            <option value="full">Full</option>
        </select></td></tr>
        <tr><th>Message</th><td><textarea name="message" rows="3" class="large-text"></textarea></td></tr>
        <tr><th>Notes</th><td><textarea name="notes" rows="3" class="large-text"></textarea></td></tr>
        </table>
        <p><input type="submit" class="button button-primary button-hero" value="Add Booking"></p>
        </form></div>
        <?php
    }
}
