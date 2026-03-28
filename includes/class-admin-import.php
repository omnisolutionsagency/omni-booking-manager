<?php
class OBM_Admin_Import {
    private static $instance = null;
    public static function get_instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {
        add_action('admin_post_obm_import_csv', [$this, 'handle_import']);
    }

    public function handle_import() {
        check_admin_referer('obm_import_action');
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            wp_redirect(admin_url('admin.php?page=obm-import&msg=error&detail=upload'));
            exit;
        }
        $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
        if (!$handle) {
            wp_redirect(admin_url('admin.php?page=obm-import&msg=error&detail=open'));
            exit;
        }
        $header = fgetcsv($handle);
        $header = array_map(function ($h) { return strtolower(trim($h)); }, $header);
        $imported = 0;
        $skipped = 0;

        $col_map = [
            'name' => ['name', 'client_name', 'customer_name', 'client'],
            'email' => ['email', 'email_address', 'client_email'],
            'phone' => ['phone', 'phone_number', 'tel', 'telephone'],
            'requested_date' => ['date', 'requested_date', 'booking_date'],
            'start_time' => ['time', 'start_time'],
            'backup_date' => ['backup_date', 'alt_date'],
            'guests' => ['guests', 'party_size', 'number_of_guests'],
            'guests_under_6' => ['guests_under_6', 'kids', 'children'],
            'message' => ['message', 'notes', 'comments'],
            'status' => ['status', 'booking_status'],
            'service_duration' => ['duration', 'service_duration'],
            'payment_status' => ['payment', 'payment_status'],
        ];

        $indexes = [];
        foreach ($col_map as $field => $aliases) {
            foreach ($aliases as $alias) {
                $pos = array_search($alias, $header);
                if ($pos !== false) { $indexes[$field] = $pos; break; }
            }
        }
        if (!isset($indexes['name'])) {
            fclose($handle);
            wp_redirect(admin_url('admin.php?page=obm-import&msg=error&detail=no_name_column'));
            exit;
        }

        while (($row = fgetcsv($handle)) !== false) {
            if (empty(array_filter($row))) continue;
            $data = [];
            foreach ($indexes as $field => $pos) {
                $data[$field] = isset($row[$pos]) ? trim($row[$pos]) : '';
            }
            if (empty($data['name'])) { $skipped++; continue; }
            $data['guests'] = intval($data['guests'] ?? 0);
            $data['guests_under_6'] = intval($data['guests_under_6'] ?? 0);
            if (empty($data['status'])) $data['status'] = 'booked';
            if (empty($data['payment_status'])) $data['payment_status'] = 'none';
            $data['duplicate_flag'] = 0;
            $data['created_at'] = current_time('mysql');
            $data['updated_at'] = current_time('mysql');
            global $wpdb;
            $wpdb->insert(OBM_DB::get_prefix() . 'leads', $data);
            $imported++;
        }
        fclose($handle);
        wp_redirect(admin_url("admin.php?page=obm-import&msg=success&imported=$imported&skipped=$skipped"));
        exit;
    }

    public function render() {
        ?>
        <div class="wrap obm-wrap">
        <h1>Import Bookings</h1>
        <?php if (isset($_GET['msg'])): ?>
            <?php if ($_GET['msg'] === 'success'): ?>
            <div class="notice notice-success"><p>Imported <?php echo intval($_GET['imported']); ?> leads. Skipped <?php echo intval($_GET['skipped']); ?>.</p></div>
            <?php else: ?>
            <div class="notice notice-error"><p>Import error: <?php echo esc_html($_GET['detail'] ?? 'unknown'); ?></p></div>
            <?php endif; ?>
        <?php endif; ?>
        <h2>Upload CSV File</h2>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data">
            <input type="hidden" name="action" value="obm_import_csv">
            <?php wp_nonce_field('obm_import_action'); ?>
            <table class="form-table"><tr>
                <th>CSV File</th>
                <td><input type="file" name="csv_file" accept=".csv" required>
                <p class="description">First row must be column headers.</p></td>
            </tr></table>
            <p><input type="submit" class="button button-primary" value="Import"></p>
        </form>
        <hr>
        <h2>CSV Format</h2>
        <p>Recognized columns: <code>name</code> (required), <code>email</code>, <code>phone</code>, <code>date</code>, <code>time</code>, <code>guests</code>, <code>guests_under_6</code>, <code>message</code>, <code>status</code>, <code>duration</code>, <code>payment</code></p>
        <h3>Example</h3>
        <pre style="background:#f1f1f1;padding:10px;">name,email,phone,date,time,guests,status,duration,payment
John Smith,john@email.com,301-555-1234,2026-04-15,10:00,6,booked,2 Hours,deposit</pre>
        </div>
        <?php
    }
}
