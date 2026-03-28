<?php
class OBM_Ajax_Handler {
    private static $instance = null;
    public static function get_instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {
        $actions = ['update_status', 'save_notes', 'assign_staff', 'set_duration', 'set_start_time', 'set_payment', 'use_backup_date'];
        foreach ($actions as $a) {
            add_action("wp_ajax_obm_{$a}", [$this, $a]);
        }
    }
    private function verify() {
        check_ajax_referer('obm_nonce', 'nonce');
        if (!current_user_can('obm_manage_bookings')) wp_send_json_error('Unauthorized');
    }

    public function update_status() {
        $this->verify();
        $id = intval($_POST['lead_id']);
        $action = sanitize_text_field($_POST['status_action']);
        $lead = OBM_DB::get_lead($id);
        if (!$lead) wp_send_json_error('Lead not found');
        $gcal = OBM_Google_Calendar::get_instance();
        switch ($action) {
            case 'book':
                $data = [
                    'status' => 'booked',
                    'service_duration' => sanitize_text_field($_POST['duration'] ?? ''),
                    'staff_id' => intval($_POST['staff_id'] ?? 0),
                    'start_time' => sanitize_text_field($_POST['start_time'] ?? ''),
                    'payment_status' => sanitize_text_field($_POST['payment'] ?? 'none'),
                ];
                OBM_DB::update_lead($id, $data);
                $lead = OBM_DB::get_lead($id);
                if ($gcal->is_connected() && $lead->google_event_id) {
                    $staff = $lead->staff_id ? OBM_DB::get_staff_member($lead->staff_id) : null;
                    $gcal->update_event_booked($lead->google_event_id, $lead, $staff);
                }
                wp_send_json_success(['status' => 'booked']);
                break;
            case 'decline':
                OBM_DB::update_lead($id, ['status' => 'declined']);
                if ($gcal->is_connected() && $lead->google_event_id) {
                    $gcal->delete_event($lead->google_event_id);
                    OBM_DB::update_lead($id, ['google_event_id' => '']);
                }
                wp_send_json_success(['status' => 'declined']);
                break;
            case 'complete':
                OBM_DB::update_lead($id, ['status' => 'completed']);
                if ($gcal->is_connected() && $lead->google_event_id) {
                    $gcal->update_event_completed($lead->google_event_id, $lead);
                }
                wp_send_json_success(['status' => 'completed']);
                break;
        }
    }

    public function save_notes() {
        $this->verify();
        OBM_DB::update_lead(intval($_POST['lead_id']), ['notes' => sanitize_textarea_field($_POST['notes'])]);
        wp_send_json_success();
    }

    public function assign_staff() {
        $this->verify();
        OBM_DB::update_lead(intval($_POST['lead_id']), ['staff_id' => intval($_POST['staff_id'])]);
        wp_send_json_success();
    }

    public function set_duration() {
        $this->verify();
        OBM_DB::update_lead(intval($_POST['lead_id']), ['service_duration' => sanitize_text_field($_POST['duration'])]);
        wp_send_json_success();
    }

    public function set_start_time() {
        $this->verify();
        OBM_DB::update_lead(intval($_POST['lead_id']), ['start_time' => sanitize_text_field($_POST['start_time'])]);
        wp_send_json_success();
    }

    public function set_payment() {
        $this->verify();
        OBM_DB::update_lead(intval($_POST['lead_id']), ['payment_status' => sanitize_text_field($_POST['payment_status'])]);
        wp_send_json_success();
    }

    public function use_backup_date() {
        $this->verify();
        $id = intval($_POST['lead_id']);
        $lead = OBM_DB::get_lead($id);
        if (!$lead || empty($lead->backup_date)) wp_send_json_error('No backup date');
        $old = $lead->requested_date;
        OBM_DB::update_lead($id, ['requested_date' => $lead->backup_date, 'backup_date' => $old]);
        $lead = OBM_DB::get_lead($id);
        $gcal = OBM_Google_Calendar::get_instance();
        if ($gcal->is_connected() && $lead->google_event_id) {
            if ($lead->status === 'booked') {
                $staff = $lead->staff_id ? OBM_DB::get_staff_member($lead->staff_id) : null;
                $gcal->update_event_booked($lead->google_event_id, $lead, $staff);
            }
        }
        wp_send_json_success(['new_date' => $lead->requested_date]);
    }
}
