<?php
class OBM_DB {
    public static function get_prefix() {
        global $wpdb;
        return $wpdb->prefix . 'obm_';
    }

    public static function create_tables() {
        global $wpdb;
        $p = self::get_prefix();
        $c = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE {$p}leads (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            phone VARCHAR(100),
            requested_date DATE,
            start_time VARCHAR(10) DEFAULT '',
            backup_date DATE,
            guests INT DEFAULT 0,
            guests_under_6 INT DEFAULT 0,
            message TEXT,
            status VARCHAR(20) DEFAULT 'proposed',
            service_duration VARCHAR(50) DEFAULT '',
            payment_status VARCHAR(20) DEFAULT 'none',
            staff_id INT DEFAULT 0,
            google_event_id VARCHAR(255) DEFAULT '',
            notes TEXT,
            duplicate_flag TINYINT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $c");

        dbDelta("CREATE TABLE {$p}staff (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            phone VARCHAR(100),
            email VARCHAR(255),
            active TINYINT DEFAULT 1
        ) $c");

        dbDelta("CREATE TABLE {$p}blocked_dates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            date_start DATE NOT NULL,
            date_end DATE NOT NULL,
            reason VARCHAR(255) DEFAULT ''
        ) $c");
    }

    public static function insert_lead($data) {
        global $wpdb;
        $dup = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::get_prefix() . "leads WHERE email=%s OR phone=%s",
            $data['email'], $data['phone']
        ));
        $data['duplicate_flag'] = $dup > 0 ? 1 : 0;
        $data['created_at'] = current_time('mysql');
        $data['updated_at'] = current_time('mysql');
        $wpdb->insert(self::get_prefix() . 'leads', $data);
        return $wpdb->insert_id;
    }

    public static function get_leads($args = []) {
        global $wpdb;
        $t = self::get_prefix() . 'leads';
        $sql = "SELECT * FROM $t";
        $where = [];
        if (!empty($args['status'])) {
            $where[] = $wpdb->prepare("status=%s", $args['status']);
        }
        if (!empty($args['month'])) {
            $where[] = $wpdb->prepare("DATE_FORMAT(requested_date,'%%Y-%%m')=%s", $args['month']);
        }
        if (!empty($where)) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY requested_date ASC, start_time ASC';
        if (!empty($args['limit'])) $sql .= $wpdb->prepare(' LIMIT %d', $args['limit']);
        return $wpdb->get_results($sql);
    }

    public static function get_lead($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::get_prefix() . "leads WHERE id=%d", $id));
    }

    public static function update_lead($id, $data) {
        global $wpdb;
        $data['updated_at'] = current_time('mysql');
        return $wpdb->update(self::get_prefix() . 'leads', $data, ['id' => $id]);
    }

    public static function get_staff($active_only = true) {
        global $wpdb;
        $sql = "SELECT * FROM " . self::get_prefix() . "staff";
        if ($active_only) $sql .= ' WHERE active=1';
        return $wpdb->get_results($sql . ' ORDER BY name ASC');
    }

    public static function insert_staff($data) {
        global $wpdb;
        $wpdb->insert(self::get_prefix() . 'staff', $data);
        return $wpdb->insert_id;
    }

    public static function update_staff($id, $data) {
        global $wpdb;
        return $wpdb->update(self::get_prefix() . 'staff', $data, ['id' => $id]);
    }

    public static function get_staff_member($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::get_prefix() . "staff WHERE id=%d", $id));
    }

    public static function get_blocked_dates() {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM " . self::get_prefix() . "blocked_dates ORDER BY date_start ASC");
    }

    public static function insert_blocked_date($data) {
        global $wpdb;
        $wpdb->insert(self::get_prefix() . 'blocked_dates', $data);
        return $wpdb->insert_id;
    }

    public static function delete_blocked_date($id) {
        global $wpdb;
        return $wpdb->delete(self::get_prefix() . 'blocked_dates', ['id' => $id]);
    }

    public static function is_date_blocked($date) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::get_prefix() . "blocked_dates WHERE %s BETWEEN date_start AND date_end", $date
        ));
    }

    public static function get_leads_by_date_range($start, $end) {
        global $wpdb;
        $t = self::get_prefix() . 'leads';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t WHERE requested_date BETWEEN %s AND %s AND status IN ('proposed','booked') ORDER BY requested_date, start_time",
            $start, $end
        ));
    }
}
