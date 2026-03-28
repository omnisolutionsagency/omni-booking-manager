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
        // Integration actions
        register_rest_route($ns, '/leads/(?P<id>\d+)/send-waiver', array_merge(['methods' => 'POST', 'callback' => [$this, 'send_waiver']], $perm));
        register_rest_route($ns, '/leads/(?P<id>\d+)/send-email', array_merge(['methods' => 'POST', 'callback' => [$this, 'send_email']], $perm));
        register_rest_route($ns, '/leads/(?P<id>\d+)/send-sms', array_merge(['methods' => 'POST', 'callback' => [$this, 'send_sms']], $perm));
        register_rest_route($ns, '/leads/(?P<id>\d+)/send-invoice', array_merge(['methods' => 'POST', 'callback' => [$this, 'send_invoice']], $perm));
        register_rest_route($ns, '/leads/(?P<id>\d+)/send-portal', array_merge(['methods' => 'POST', 'callback' => [$this, 'send_portal']], $perm));
        register_rest_route($ns, '/leads/(?P<id>\d+)/send-review', array_merge(['methods' => 'POST', 'callback' => [$this, 'send_review']], $perm));
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
        $old_status = $lead->status;
        OBM_DB::update_lead($id, $data);
        $lead = OBM_DB::get_lead($id);
        $gcal = OBM_Google_Calendar::get_instance();
        if ($gcal->is_connected() && $lead->google_event_id) {
            $staff = $lead->staff_id ? OBM_DB::get_staff_member($lead->staff_id) : null;
            $gcal->update_event_booked($lead->google_event_id, $lead, $staff);
        }
        do_action('obm_lead_status_changed', $id, $old_status, 'booked');
        return rest_ensure_response(['status' => 'booked']);
    }

    public function decline_lead($req) {
        $id = $req['id'];
        $lead = OBM_DB::get_lead($id);
        if (!$lead) return new WP_Error('not_found', 'Not found', ['status' => 404]);
        $old_status = $lead->status;
        OBM_DB::update_lead($id, ['status' => 'declined']);
        $gcal = OBM_Google_Calendar::get_instance();
        if ($gcal->is_connected() && $lead->google_event_id) {
            $gcal->delete_event($lead->google_event_id);
            OBM_DB::update_lead($id, ['google_event_id' => '']);
        }
        do_action('obm_lead_status_changed', $id, $old_status, 'declined');
        return rest_ensure_response(['status' => 'declined']);
    }

    public function complete_lead($req) {
        $id = $req['id'];
        $lead = OBM_DB::get_lead($id);
        if (!$lead) return new WP_Error('not_found', 'Not found', ['status' => 404]);
        $old_status = $lead->status;
        OBM_DB::update_lead($id, ['status' => 'completed']);
        $gcal = OBM_Google_Calendar::get_instance();
        if ($gcal->is_connected() && $lead->google_event_id) {
            $gcal->update_event_completed($lead->google_event_id, $lead);
        }
        do_action('obm_lead_status_changed', $id, $old_status, 'completed');
        return rest_ensure_response(['status' => 'completed']);
    }

    // Integration REST actions
    public function send_waiver($req) {
        if (!class_exists('OBM_Integration_Waivers')) return new WP_Error('inactive', 'Waivers not active', ['status' => 400]);
        $r = OBM_Integration_Waivers::get_instance()->send_waiver_email($req['id']);
        return $r ? rest_ensure_response(['sent' => true]) : new WP_Error('failed', 'Failed to send', ['status' => 500]);
    }

    public function send_email($req) {
        if (!class_exists('OBM_Integration_Emails')) return new WP_Error('inactive', 'Emails not active', ['status' => 400]);
        $slug = sanitize_text_field($req->get_param('template'));
        $r = OBM_Integration_Emails::get_instance()->send_template($slug, $req['id']);
        return $r ? rest_ensure_response(['sent' => true]) : new WP_Error('failed', 'Failed to send', ['status' => 500]);
    }

    public function send_sms($req) {
        if (!class_exists('OBM_Integration_SMS')) return new WP_Error('inactive', 'SMS not active', ['status' => 400]);
        $lead = OBM_DB::get_lead($req['id']);
        if (!$lead || empty($lead->phone)) return new WP_Error('no_phone', 'No phone', ['status' => 400]);
        $msg = sanitize_textarea_field($req->get_param('message'));
        $r = OBM_Integration_SMS::get_instance()->send($lead->phone, $msg, $req['id']);
        return $r ? rest_ensure_response(['sent' => true]) : new WP_Error('failed', 'Failed to send', ['status' => 500]);
    }

    public function send_invoice($req) {
        if (!class_exists('OBM_Integration_Stripe')) return new WP_Error('inactive', 'Stripe not active', ['status' => 400]);
        $lead = OBM_DB::get_lead($req['id']);
        if (!$lead) return new WP_Error('not_found', 'Not found', ['status' => 404]);
        $amount = floatval($req->get_param('amount'));
        $type = sanitize_text_field($req->get_param('type') ?: 'deposit');
        $url = OBM_Integration_Stripe::get_instance()->create_payment_link($lead, $amount, $type);
        if ($url) {
            $biz = obm_get('business_name', get_bloginfo('name'));
            wp_mail($lead->email, "{$biz} - " . ucfirst($type) . " Payment", "Hi {$lead->name},\n\nPlease complete your {$type} payment of \${$amount}:\n\n{$url}\n\nThank you!\n{$biz}");
            return rest_ensure_response(['sent' => true, 'url' => $url]);
        }
        return new WP_Error('failed', 'Failed to create payment link', ['status' => 500]);
    }

    public function send_portal($req) {
        if (!class_exists('OBM_Integration_Portal')) return new WP_Error('inactive', 'Portal not active', ['status' => 400]);
        $lead = OBM_DB::get_lead($req['id']);
        if (!$lead || empty($lead->email)) return new WP_Error('no_email', 'No email', ['status' => 400]);
        $token = $lead->portal_token;
        if (empty($token)) $token = OBM_Integration_Portal::get_instance()->generate_token($req['id']);
        $url = home_url('/my-booking/' . $token);
        $biz = obm_get('business_name', get_bloginfo('name'));
        wp_mail($lead->email, "{$biz} — Your Booking Details", "Hi {$lead->name},\n\nView your booking details here:\n\n{$url}\n\nThank you!\n{$biz}");
        return rest_ensure_response(['sent' => true, 'url' => $url]);
    }

    public function send_review($req) {
        if (!class_exists('OBM_Integration_Reviews')) return new WP_Error('inactive', 'Reviews not active', ['status' => 400]);
        $r = OBM_Integration_Reviews::get_instance()->send_review_request($req['id']);
        return $r ? rest_ensure_response(['sent' => true]) : new WP_Error('failed', 'Failed to send', ['status' => 500]);
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
        $integrations = OBM_Integrations::get_instance();
        $active = [];
        foreach (['waivers', 'emails', 'stripe', 'sms', 'reviews', 'portal'] as $k) {
            $active[$k] = $integrations->is_active($k);
        }
        $email_templates = [];
        if ($active['emails'] && class_exists('OBM_Integration_Emails')) {
            foreach (OBM_Integration_Emails::get_instance()->get_templates() as $t) {
                if ($t->active) $email_templates[] = ['slug' => $t->slug, 'name' => $t->name];
            }
        }
        if ($active['reviews']) {
            $email_templates[] = ['slug' => 'review_request', 'name' => 'Review Request'];
        }
        return rest_ensure_response([
            'business_name' => obm_get('business_name', get_bloginfo('name')),
            'brand_color' => obm_get('brand_color', '#2c5f2d'),
            'staff_label' => obm_get('staff_label', 'Staff'),
            'duration_options' => array_map('trim', explode(',', obm_get('duration_options', ''))),
            'integrations' => $active,
            'email_templates' => $email_templates,
            'default_deposit' => floatval(get_option('obm_stripe_deposit_amount', 50)),
        ]);
    }
}
