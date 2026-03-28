<?php
class OBM_REST_API {
    private static $instance = null;
    public static function get_instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function check_permission() {
        return current_user_can('obm_manage_bookings');
    }

    public function register_routes() {
        $ns = 'obm/v1';
        $perm = ['permission_callback' => [$this, 'check_permission']];
        register_rest_route($ns, '/leads', array_merge(['methods' => 'GET', 'callback' => [$this, 'get_leads']], $perm));
        register_rest_route($ns, '/leads/(?P<id>\d+)', array_merge(['methods' => 'GET', 'callback' => [$this, 'get_lead']], $perm));
        register_rest_route($ns, '/leads/(?P<id>\d+)', array_merge(['methods' => 'PATCH', 'callback' => [$this, 'update_lead']], $perm));
        register_rest_route($ns, '/leads/(?P<id>\d+)/book', array_merge(['methods' => 'POST', 'callback' => [$this, 'book_lead']], $perm));
        register_rest_route($ns, '/leads/(?P<id>\d+)/decline', array_merge(['methods' => 'POST', 'callback' => [$this, 'decline_lead']], $perm));
        register_rest_route($ns, '/leads/(?P<id>\d+)/complete', array_merge(['methods' => 'POST', 'callback' => [$this, 'complete_lead']], $perm));
        register_rest_route($ns, '/leads/(?P<id>\d+)/use-backup', array_merge(['methods' => 'POST', 'callback' => [$this, 'use_backup']], $perm));
        register_rest_route($ns, '/staff', array_merge(['methods' => 'GET', 'callback' => [$this, 'get_staff']], $perm));
        register_rest_route($ns, '/calendar', array_merge(['methods' => 'GET', 'callback' => [$this, 'get_calendar']], $perm));
        register_rest_route($ns, '/stats', array_merge(['methods' => 'GET', 'callback' => [$this, 'get_stats']], $perm));
        register_rest_route($ns, '/config', array_merge(['methods' => 'GET', 'callback' => [$this, 'get_config']], $perm));
    }

    public function get_leads($req) {
        $args = [];
        if ($req->get_param('status')) $args['status'] = sanitize_text_field($req->get_param('status'));
        if ($req->get_param('month')) $args['month'] = sanitize_text_field($req->get_param('month'));
        $leads = OBM_DB::get_leads($args);
        $out = [];
        foreach ($leads as $l) {
            $s = $l->staff_id ? OBM_DB::get_staff_member($l->staff_id) : null;
            $r = (array) $l;
            $r['staff_name'] = $s ? $s->name : '';
            $out[] = $r;
        }
        return rest_ensure_response($out);
    }

    public function get_lead($req) {
        $l = OBM_DB::get_lead($req['id']);
        if (!$l) return new WP_Error('not_found', 'Not found', ['status' => 404]);
        $r = (array) $l;
        $s = $l->staff_id ? OBM_DB::get_staff_member($l->staff_id) : null;
        $r['staff_name'] = $s ? $s->name : '';
        return rest_ensure_response($r);
    }

    public function update_lead($req) {
        $id = $req['id'];
        $data = [];
        foreach (['notes', 'start_time', 'service_duration', 'staff_id', 'payment_status'] as $f) {
            $v = $req->get_param($f);
            if ($v !== null) $data[$f] = sanitize_text_field($v);
        }
        if (!empty($data)) OBM_DB::update_lead($id, $data);
        return $this->get_lead($req);
    }

    public function book_lead($req) {
        $id = $req['id'];
        $lead = OBM_DB::get_lead($id);
        if (!$lead) return new WP_Error('not_found', 'Not found', ['status' => 404]);
        $data = ['status' => 'booked'];
        foreach (['service_duration', 'staff_id', 'start_time', 'payment_status'] as $f) {
            $v = $req->get_param($f);
            if ($v !== null) $data[$f] = sanitize_text_field($v);
        }
        OBM_DB::update_lead($id, $data);
        $lead = OBM_DB::get_lead($id);
        $gcal = OBM_Google_Calendar::get_instance();
        if ($gcal->is_connected() && $lead->google_event_id) {
            $staff = $lead->staff_id ? OBM_DB::get_staff_member($lead->staff_id) : null;
            $gcal->update_event_booked($lead->google_event_id, $lead, $staff);
        }
        return rest_ensure_response(['status' => 'booked']);
    }

    public function decline_lead($req) {
        $id = $req['id'];
        $lead = OBM_DB::get_lead($id);
        if (!$lead) return new WP_Error('not_found', 'Not found', ['status' => 404]);
        OBM_DB::update_lead($id, ['status' => 'declined']);
        $gcal = OBM_Google_Calendar::get_instance();
        if ($gcal->is_connected() && $lead->google_event_id) {
            $gcal->delete_event($lead->google_event_id);
            OBM_DB::update_lead($id, ['google_event_id' => '']);
        }
        return rest_ensure_response(['status' => 'declined']);
    }

    public function complete_lead($req) {
        $id = $req['id'];
        $lead = OBM_DB::get_lead($id);
        if (!$lead) return new WP_Error('not_found', 'Not found', ['status' => 404]);
        OBM_DB::update_lead($id, ['status' => 'completed']);
        $gcal = OBM_Google_Calendar::get_instance();
        if ($gcal->is_connected() && $lead->google_event_id) {
            $gcal->update_event_completed($lead->google_event_id, $lead);
        }
        return rest_ensure_response(['status' => 'completed']);
    }

    public function use_backup($req) {
        $id = $req['id'];
        $lead = OBM_DB::get_lead($id);
        if (!$lead || empty($lead->backup_date)) return new WP_Error('no_backup', 'No backup date', ['status' => 400]);
        $old = $lead->requested_date;
        OBM_DB::update_lead($id, ['requested_date' => $lead->backup_date, 'backup_date' => $old]);
        $lead = OBM_DB::get_lead($id);
        $gcal = OBM_Google_Calendar::get_instance();
        if ($gcal->is_connected() && $lead->google_event_id && $lead->status === 'booked') {
            $staff = $lead->staff_id ? OBM_DB::get_staff_member($lead->staff_id) : null;
            $gcal->update_event_booked($lead->google_event_id, $lead, $staff);
        }
        return rest_ensure_response(['new_date' => $lead->requested_date]);
    }

    public function get_staff($req) {
        return rest_ensure_response(OBM_DB::get_staff());
    }

    public function get_calendar($req) {
        $month = $req->get_param('month') ?: date('Y-m');
        $start = $month . '-01';
        $end = date('Y-m-t', strtotime($start));
        return rest_ensure_response([
            'leads' => OBM_DB::get_leads_by_date_range($start, $end),
            'blocked' => OBM_DB::get_blocked_dates(),
            'month' => $month,
        ]);
    }

    public function get_stats($req) {
        return rest_ensure_response([
            'proposed' => count(OBM_DB::get_leads(['status' => 'proposed'])),
            'booked' => count(OBM_DB::get_leads(['status' => 'booked'])),
            'declined' => count(OBM_DB::get_leads(['status' => 'declined'])),
            'completed' => count(OBM_DB::get_leads(['status' => 'completed'])),
        ]);
    }

    public function get_config($req) {
        return rest_ensure_response([
            'business_name' => obm_get('business_name', get_bloginfo('name')),
            'brand_color' => obm_get('brand_color', '#2c5f2d'),
            'staff_label' => obm_get('staff_label', 'Staff'),
            'duration_options' => array_map('trim', explode(',', obm_get('duration_options', ''))),
        ]);
    }
}
