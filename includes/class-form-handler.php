<?php
class OBM_Form_Handler {
    private static $instance = null;
    public static function get_instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {
        add_action('elementor_pro/forms/new_record', [$this, 'handle_submission'], 10, 2);
    }

    public function handle_submission($record, $handler) {
        if (!obm_is_setup_complete()) return;

        $form_id = obm_get('elementor_form_id', '');
        // If a specific form is configured, only capture that form
        if ($form_id) {
            $form_meta = $record->get_form_settings('id');
            if ($form_meta !== $form_id) return;
        }

        $fields = $record->get('fields');
        $map = [];
        foreach ($fields as $field) {
            $map[strtolower($field['id'])] = $field['value'];
            $map[strtolower(str_replace(' ', '_', $field['title']))] = $field['value'];
        }

        // Get field mapping from settings
        $field_map = obm_get('field_mapping', []);
        $data = [
            'name' => $map[$field_map['name'] ?? 'name'] ?? '',
            'email' => $map[$field_map['email'] ?? 'email'] ?? '',
            'phone' => $map[$field_map['phone'] ?? 'phone'] ?? '',
            'requested_date' => $map[$field_map['date'] ?? 'date'] ?? '',
            'backup_date' => $map[$field_map['backup_date'] ?? 'backup_date'] ?? '',
            'guests' => intval($map[$field_map['guests'] ?? 'guests'] ?? 0),
            'guests_under_6' => intval($map[$field_map['guests_under_6'] ?? 'guests_under_6'] ?? 0),
            'message' => $map[$field_map['message'] ?? 'message'] ?? '',
            'status' => 'proposed',
            'payment_status' => 'none',
        ];

        if (empty($data['name'])) return;

        $lead_id = OBM_DB::insert_lead($data);
        if (!$lead_id) return;

        $lead = OBM_DB::get_lead($lead_id);
        $gcal = OBM_Google_Calendar::get_instance();
        if ($gcal->is_connected()) {
            $event = $gcal->create_event($lead);
            if ($event && isset($event['id'])) {
                OBM_DB::update_lead($lead_id, ['google_event_id' => $event['id']]);
            }
        }

        if (OBM_DB::is_date_blocked($data['requested_date'])) {
            $admin_email = get_option('admin_email');
            wp_mail($admin_email, 'Omni Booking Manager: Blocked Date Request',
                "A lead requested a blocked date: {$data['requested_date']}\nName: {$data['name']}");
        }

        $admin_email = get_option('admin_email');
        $biz = obm_get('business_name', get_bloginfo('name'));
        $subj = "{$biz} - New Inquiry: {$data['name']}";
        $body = "Name: {$data['name']}\nEmail: {$data['email']}\nPhone: {$data['phone']}\n";
        $body .= "Date: {$data['requested_date']}\nGuests: {$data['guests']}\n";
        wp_mail($admin_email, $subj, $body);
    }
}
