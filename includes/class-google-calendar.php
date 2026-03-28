<?php
class OBM_Google_Calendar {
    private static $instance = null;
    public static function get_instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function get_client_id() { return get_option('obm_google_client_id', ''); }
    private function get_client_secret() { return get_option('obm_google_client_secret', ''); }
    private function get_access_token() { return get_option('obm_google_access_token', ''); }
    private function get_refresh_token() { return get_option('obm_google_refresh_token', ''); }
    private function get_calendar_id() { return get_option('obm_google_calendar_id', 'primary'); }

    public function get_auth_url() {
        $redirect = admin_url('admin.php?page=obm-settings&obm_oauth=1');
        $params = http_build_query([
            'client_id' => $this->get_client_id(),
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/calendar',
            'access_type' => 'offline',
            'prompt' => 'consent'
        ]);
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . $params;
    }

    public function handle_oauth_callback($code) {
        $redirect = admin_url('admin.php?page=obm-settings&obm_oauth=1');
        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'body' => [
                'code' => $code,
                'client_id' => $this->get_client_id(),
                'client_secret' => $this->get_client_secret(),
                'redirect_uri' => $redirect,
                'grant_type' => 'authorization_code'
            ]
        ]);
        if (is_wp_error($response)) return false;
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($body['access_token'])) {
            update_option('obm_google_access_token', $body['access_token']);
            if (isset($body['refresh_token'])) {
                update_option('obm_google_refresh_token', $body['refresh_token']);
            }
            update_option('obm_google_token_expires', time() + $body['expires_in']);
            return true;
        }
        return false;
    }

    private function refresh_token_if_needed() {
        $expires = get_option('obm_google_token_expires', 0);
        if (time() < $expires - 60) return true;
        $refresh = $this->get_refresh_token();
        if (empty($refresh)) return false;
        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'body' => [
                'client_id' => $this->get_client_id(),
                'client_secret' => $this->get_client_secret(),
                'refresh_token' => $refresh,
                'grant_type' => 'refresh_token'
            ]
        ]);
        if (is_wp_error($response)) return false;
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($body['access_token'])) {
            update_option('obm_google_access_token', $body['access_token']);
            update_option('obm_google_token_expires', time() + $body['expires_in']);
            return true;
        }
        return false;
    }

    private function api_request($method, $endpoint, $body = null) {
        if (!$this->refresh_token_if_needed()) return false;
        $url = 'https://www.googleapis.com/calendar/v3/' . $endpoint;
        $args = [
            'method' => $method,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->get_access_token(),
                'Content-Type' => 'application/json'
            ]
        ];
        if ($body) $args['body'] = json_encode($body);
        $r = wp_remote_request($url, $args);
        if (is_wp_error($r)) return false;
        return json_decode(wp_remote_retrieve_body($r), true);
    }

    private function build_time_slots($lead) {
        if (!empty($lead->start_time) && !empty($lead->requested_date)) {
            $tz = wp_timezone_string() ?: 'America/New_York';
            $start_dt = $lead->requested_date . 'T' . $lead->start_time . ':00';
            $dur_hours = floatval(preg_replace('/[^0-9.]/', '', $lead->service_duration ?: '2'));
            $end_ts = strtotime($start_dt) + ($dur_hours * 3600);
            $end_dt = date('Y-m-d', $end_ts) . 'T' . date('H:i:s', $end_ts);
            return [
                'start' => ['dateTime' => $start_dt, 'timeZone' => $tz],
                'end' => ['dateTime' => $end_dt, 'timeZone' => $tz]
            ];
        }
        return [
            'start' => ['date' => $lead->requested_date],
            'end' => ['date' => $lead->requested_date]
        ];
    }

    public function create_event($lead) {
        $cal = $this->get_calendar_id();
        $biz = obm_get('business_name', 'Booking');
        $desc = "Phone: {$lead->phone}\nEmail: {$lead->email}\n";
        $desc .= "Guests: {$lead->guests}\nKids under 6: {$lead->guests_under_6}\n";
        if (!empty($lead->start_time)) $desc .= "Time: {$lead->start_time}\n";
        if (!empty($lead->backup_date)) $desc .= "Backup Date: {$lead->backup_date}\n";
        if (!empty($lead->message)) $desc .= "Message: {$lead->message}\n";
        $times = $this->build_time_slots($lead);
        $event = array_merge([
            'summary' => "PROPOSED: {$lead->name}",
            'description' => $desc,
            'colorId' => '5'
        ], $times);
        return $this->api_request('POST', "calendars/{$cal}/events", $event);
    }

    public function update_event_booked($event_id, $lead, $staff = null) {
        $cal = $this->get_calendar_id();
        $staff_label = obm_get('staff_label', 'Staff');
        $desc = "Phone: {$lead->phone}\nEmail: {$lead->email}\n";
        $desc .= "Guests: {$lead->guests}\nKids under 6: {$lead->guests_under_6}\n";
        $desc .= "Duration: {$lead->service_duration}\n";
        $pay = ucfirst($lead->payment_status ?: 'none');
        $desc .= "Payment: {$pay}\n";
        if ($staff) $desc .= "{$staff_label}: {$staff->name} ({$staff->phone})\n";
        if (!empty($lead->message)) $desc .= "Message: {$lead->message}\n";
        $times = $this->build_time_slots($lead);
        $event = array_merge([
            'summary' => "BOOKED: {$lead->name}",
            'description' => $desc,
            'colorId' => '2'
        ], $times);
        return $this->api_request('PATCH', "calendars/{$cal}/events/{$event_id}", $event);
    }

    public function update_event_completed($event_id, $lead) {
        $cal = $this->get_calendar_id();
        return $this->api_request('PATCH', "calendars/{$cal}/events/{$event_id}", [
            'summary' => "COMPLETED: {$lead->name}",
            'colorId' => '8'
        ]);
    }

    public function delete_event($event_id) {
        $cal = $this->get_calendar_id();
        return $this->api_request('DELETE', "calendars/{$cal}/events/{$event_id}");
    }

    public function is_connected() {
        return !empty($this->get_access_token()) && !empty($this->get_refresh_token());
    }

    public function get_calendars() {
        $r = $this->api_request('GET', 'users/me/calendarList');
        return $r ? ($r['items'] ?? []) : [];
    }
}
