<?php
class OBM_Admin_Import {
    private static $instance = null;
    public static function get_instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {
        add_action('admin_post_obm_import_csv', [$this, 'handle_import']);
        add_action('admin_post_obm_check_submissions', [$this, 'handle_check_submissions']);
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

    public function handle_check_submissions() {
        check_admin_referer('obm_import_action');
        global $wpdb;

        // Check if Elementor submissions table exists
        $table = $wpdb->prefix . 'e_submissions';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            wp_redirect(admin_url('admin.php?page=obm-import&msg=error&detail=no_elementor_submissions'));
            exit;
        }

        $settings = obm_get_settings();
        $form_id = $settings['elementor_form_id'] ?? '';
        $field_map = $settings['field_mapping'] ?? [];

        // Get all submissions, optionally filtered by form
        $sql = "SELECT s.id, s.created_at_gmt FROM {$table} s";
        if ($form_id) {
            $sql .= $wpdb->prepare(" WHERE s.element_id = %s", $form_id);
        }
        $sql .= " ORDER BY s.id ASC";
        $submissions = $wpdb->get_results($sql);

        $imported = 0;
        $skipped = 0;
        $existing_prefix = OBM_DB::get_prefix();

        foreach ($submissions as $sub) {
            // Get field values for this submission
            $vals = $wpdb->get_results($wpdb->prepare(
                "SELECT `key`, value FROM {$wpdb->prefix}e_submissions_values WHERE submission_id = %d",
                $sub->id
            ));
            $fields = [];
            foreach ($vals as $v) {
                $fields[$v->key] = $v->value;
            }

            // Map fields
            $name = $fields[$field_map['name'] ?? 'name'] ?? '';
            $email = $fields[$field_map['email'] ?? 'email'] ?? '';
            if (empty($name)) { $skipped++; continue; }

            // Check if this submission already exists (by email + date combo, or just email)
            $date = $fields[$field_map['date'] ?? 'date'] ?? '';
            if ($email && $date) {
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$existing_prefix}leads WHERE email = %s AND requested_date = %s",
                    $email, $date
                ));
            } elseif ($email) {
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$existing_prefix}leads WHERE email = %s",
                    $email
                ));
            } else {
                $exists = 0;
            }

            if ($exists > 0) { $skipped++; continue; }

            // Import this submission
            $data = [
                'name' => $name,
                'email' => $email,
                'phone' => $fields[$field_map['phone'] ?? 'phone'] ?? '',
                'requested_date' => $date,
                'backup_date' => $fields[$field_map['backup_date'] ?? 'backup_date'] ?? '',
                'guests' => intval($fields[$field_map['guests'] ?? 'guests'] ?? 0),
                'guests_under_6' => intval($fields[$field_map['guests_under_6'] ?? 'guests_under_6'] ?? 0),
                'message' => $fields[$field_map['message'] ?? 'message'] ?? '',
                'status' => 'proposed',
                'payment_status' => 'none',
            ];
            OBM_DB::insert_lead($data);
            $imported++;
        }

        wp_redirect(admin_url("admin.php?page=obm-import&msg=submissions&imported=$imported&skipped=$skipped"));
        exit;
    }

    public function render() {
        $settings = obm_get_settings();
        $has_form = !empty($settings['elementor_form_id']);
        ?>
        <div class="wrap obm-wrap">
        <h1>Import Bookings</h1>
        <?php if (isset($_GET['msg'])): ?>
            <?php if ($_GET['msg'] === 'success'): ?>
            <div class="notice notice-success"><p>Imported <?php echo intval($_GET['imported']); ?> leads. Skipped <?php echo intval($_GET['skipped']); ?>.</p></div>
            <?php elseif ($_GET['msg'] === 'submissions'): ?>
            <div class="notice notice-success"><p>Checked existing submissions: imported <?php echo intval($_GET['imported']); ?>, skipped <?php echo intval($_GET['skipped']); ?> (already in system or missing name).</p></div>
            <?php else: ?>
            <div class="notice notice-error"><p>Import error: <?php echo esc_html($_GET['detail'] ?? 'unknown'); ?></p></div>
            <?php endif; ?>
        <?php endif; ?>

        <h2>Check Existing Submissions</h2>
        <p>Scan Elementor form submissions and import any that aren't already in the booking system.</p>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="obm_check_submissions">
            <?php wp_nonce_field('obm_import_action'); ?>
            <p>
                <?php if ($has_form): ?>
                <span class="description">Checking form: <code><?php echo esc_html($settings['elementor_form_id']); ?></code> (configured in wizard). Submissions already in the system will be skipped.</span>
                <?php else: ?>
                <span class="description">No specific form configured — will check all Elementor submissions. Configure a form in the <a href="<?php echo admin_url('admin.php?page=obm-settings&tab=wizard'); ?>">Setup Wizard</a> to narrow it down.</span>
                <?php endif; ?>
            </p>
            <p><input type="submit" class="button button-primary" value="Check Existing Submissions"></p>
        </form>
        <hr>

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
