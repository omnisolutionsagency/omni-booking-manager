<?php
class OBM_Admin_Wizard {
    private static $instance = null;
    public static function get_instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_post_obm_wizard_save', [$this, 'handle_save']);
        add_action('admin_notices', [$this, 'setup_notice']);
    }

    public function setup_notice() {
        if (obm_is_setup_complete()) return;
        $screen = get_current_screen();
        if ($screen && strpos($screen->id, 'obm-wizard') !== false) return;
        echo '<div class="notice notice-warning"><p><strong>Omni Booking Manager</strong> needs to be configured. ';
        echo '<a href="' . admin_url('admin.php?page=obm-wizard') . '">Run Setup Wizard</a></p></div>';
    }

    public function add_menu() {
        if (!obm_is_setup_complete()) {
            add_menu_page('Setup Wizard', 'Booking Setup', 'manage_options', 'obm-wizard', [$this, 'render'], 'dashicons-admin-generic', 29);
        }
        add_submenu_page(obm_is_setup_complete() ? 'obm-dashboard' : null, 'Setup Wizard', 'Setup Wizard', 'manage_options', 'obm-wizard', [$this, 'render']);
    }

    /**
     * Auto-detect site context: name, Elementor forms, form fields
     */
    private function detect_context() {
        $ctx = [
            'site_name' => get_bloginfo('name'),
            'site_url' => home_url(),
            'admin_email' => get_option('admin_email'),
            'timezone' => wp_timezone_string(),
            'forms' => [],
        ];

        // Scan for Elementor Pro forms
        global $wpdb;
        $forms = $wpdb->get_results("
            SELECT DISTINCT s.form_name, s.element_id, s.post_id, p.post_title
            FROM {$wpdb->prefix}e_submissions s
            LEFT JOIN {$wpdb->posts} p ON p.ID = s.post_id
            WHERE s.form_name != ''
            GROUP BY s.element_id
            ORDER BY s.id DESC
        ");

        foreach ($forms as $form) {
            // Get field keys and sample values for this form
            $sample_sub = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}e_submissions WHERE element_id=%s ORDER BY id DESC LIMIT 1",
                $form->element_id
            ));
            $fields = [];
            if ($sample_sub) {
                $vals = $wpdb->get_results($wpdb->prepare(
                    "SELECT `key`, value FROM {$wpdb->prefix}e_submissions_values WHERE submission_id=%d",
                    $sample_sub
                ));
                foreach ($vals as $v) {
                    $fields[$v->key] = $v->value;
                }
            }

            $ctx['forms'][] = [
                'form_name' => $form->form_name,
                'element_id' => $form->element_id,
                'post_id' => $form->post_id,
                'page_title' => $form->post_title,
                'fields' => $fields,
            ];
        }

        return $ctx;
    }

    /**
     * Try to auto-map field keys to our lead fields
     */
    private function auto_map_fields($fields) {
        $mapping = [];
        $known = [
            'name' => ['name', 'client_name', 'customer_name', 'full_name', 'your_name'],
            'email' => ['email', 'email_address', 'client_email', 'your_email'],
            'phone' => ['phone', 'phone_number', 'tel', 'telephone', 'mobile'],
            'date' => ['date', 'requested_date', 'booking_date', 'preferred_date', 'event_date'],
            'backup_date' => ['backup_date', 'alt_date', 'alternate_date', 'backup', 'second_date'],
            'guests' => ['guests', 'party_size', 'number_of_guests', 'group_size', 'attendees'],
            'guests_under_6' => ['guests_under_6', 'kids', 'children', 'under_6', 'minors'],
            'message' => ['message', 'notes', 'comments', 'additional_info', 'details'],
        ];

        $field_keys = array_keys($fields);
        foreach ($known as $our_field => $aliases) {
            foreach ($aliases as $alias) {
                if (in_array($alias, $field_keys)) {
                    $mapping[$our_field] = $alias;
                    break;
                }
            }
        }

        // Try to guess unmapped fields by their sample values
        foreach ($field_keys as $key) {
            if (in_array($key, $mapping)) continue;
            $val = $fields[$key];
            if (!isset($mapping['email']) && filter_var($val, FILTER_VALIDATE_EMAIL)) {
                $mapping['email'] = $key;
            } elseif (!isset($mapping['phone']) && preg_match('/^\(?[0-9]{3}\)?[-.\s]?[0-9]{3}[-.\s]?[0-9]{4}$/', $val)) {
                $mapping['phone'] = $key;
            } elseif (!isset($mapping['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
                $mapping['date'] = $key;
            }
        }

        return $mapping;
    }

    public function handle_save() {
        check_admin_referer('obm_wizard_action');

        $step = intval($_POST['wizard_step'] ?? 1);

        if ($step === 1) {
            // Business info
            $settings = obm_get_settings();
            $settings['business_name'] = sanitize_text_field($_POST['business_name']);
            $settings['brand_color'] = sanitize_hex_color($_POST['brand_color'] ?: '#2c5f2d');
            $settings['staff_label'] = sanitize_text_field($_POST['staff_label'] ?: 'Staff');
            $settings['duration_options'] = sanitize_text_field($_POST['duration_options']);
            update_option('obm_settings', $settings);
            wp_redirect(admin_url('admin.php?page=obm-wizard&step=2'));
            exit;
        }

        if ($step === 2) {
            // Form selection and field mapping
            $settings = obm_get_settings();
            $settings['elementor_form_id'] = sanitize_text_field($_POST['form_element_id'] ?? '');
            $settings['elementor_post_id'] = intval($_POST['form_post_id'] ?? 0);
            $mapping = [];
            $map_fields = ['name', 'email', 'phone', 'date', 'backup_date', 'guests', 'guests_under_6', 'message'];
            foreach ($map_fields as $f) {
                $v = sanitize_text_field($_POST["map_{$f}"] ?? '');
                if ($v) $mapping[$f] = $v;
            }
            $settings['field_mapping'] = $mapping;
            update_option('obm_settings', $settings);
            wp_redirect(admin_url('admin.php?page=obm-wizard&step=3'));
            exit;
        }

        if ($step === 3) {
            // Google Calendar (optional) + finalize
            update_option('obm_setup_complete', true);
            // Auto-enable Phase 1 integrations if none are active yet
            $active = get_option('obm_active_integrations', []);
            if (empty($active)) {
                update_option('obm_active_integrations', ['stripe', 'emails', 'waivers']);
            }
            // Flush rewrite rules for PWA
            OBM_PWA::activate();
            wp_redirect(admin_url('admin.php?page=obm-dashboard&setup=complete'));
            exit;
        }
    }

    public function render() {
        $step = intval($_GET['step'] ?? 1);
        $ctx = $this->detect_context();
        $settings = obm_get_settings();
        ?>
        <div class="wrap" style="max-width:700px;">
        <h1>Omni Booking Manager Setup</h1>
        <div style="display:flex;gap:10px;margin:20px 0;">
            <?php for ($i = 1; $i <= 3; $i++): ?>
            <div style="flex:1;height:4px;border-radius:2px;background:<?php echo $i <= $step ? '#2c5f2d' : '#ddd'; ?>;"></div>
            <?php endfor; ?>
        </div>

        <?php if ($step === 1): ?>
        <h2>Step 1: Business Information</h2>
        <p>We detected your site: <strong><?php echo esc_html($ctx['site_name']); ?></strong></p>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="obm_wizard_save">
            <input type="hidden" name="wizard_step" value="1">
            <?php wp_nonce_field('obm_wizard_action'); ?>
            <table class="form-table">
            <tr>
                <th>Business Name</th>
                <td><input type="text" name="business_name" value="<?php echo esc_attr($settings['business_name'] ?? $ctx['site_name']); ?>" class="regular-text" required>
                <p class="description">Used in email notifications and calendar events.</p></td>
            </tr>
            <tr>
                <th>Brand Color</th>
                <td><input type="color" name="brand_color" value="<?php echo esc_attr($settings['brand_color'] ?? '#2c5f2d'); ?>">
                <p class="description">Primary color for admin UI and mobile app.</p></td>
            </tr>
            <tr>
                <th>Staff Role Label</th>
                <td><input type="text" name="staff_label" value="<?php echo esc_attr($settings['staff_label'] ?? 'Staff'); ?>" class="regular-text" placeholder="e.g., Captain, Guide, Instructor">
                <p class="description">What do you call your assigned team members?</p></td>
            </tr>
            <tr>
                <th>Duration Options</th>
                <td><input type="text" name="duration_options" value="<?php echo esc_attr($settings['duration_options'] ?? '1 Hour, 1.5 Hours, 2 Hours, 2.5 Hours, 3 Hours, 3.5 Hours, 4 Hours'); ?>" class="large-text">
                <p class="description">Comma-separated list of duration choices.</p></td>
            </tr>
            </table>
            <p><input type="submit" class="button button-primary button-hero" value="Continue &rarr;"></p>
        </form>

        <?php elseif ($step === 2): ?>
        <h2>Step 2: Form & Field Mapping</h2>
        <?php if (empty($ctx['forms'])): ?>
            <div class="notice notice-warning"><p>No Elementor form submissions found. You can configure this later after you receive your first form submission.</p></div>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="obm_wizard_save">
                <input type="hidden" name="wizard_step" value="2">
                <?php wp_nonce_field('obm_wizard_action'); ?>
                <p><input type="submit" class="button button-primary button-hero" value="Skip &amp; Continue &rarr;"></p>
            </form>
        <?php else: ?>
            <?php
            $selected_form = $_GET['form_idx'] ?? 0;
            $form = $ctx['forms'][$selected_form];
            $auto_map = $this->auto_map_fields($form['fields']);
            ?>
            <p>We found <strong><?php echo count($ctx['forms']); ?></strong> Elementor form(s). Select the one to capture leads from:</p>
            <div style="display:flex;gap:10px;margin-bottom:20px;">
            <?php foreach ($ctx['forms'] as $idx => $f): ?>
                <a href="?page=obm-wizard&step=2&form_idx=<?php echo $idx; ?>" class="button <?php echo $idx == $selected_form ? 'button-primary' : ''; ?>">
                    <?php echo esc_html($f['form_name']); ?> (<?php echo esc_html($f['page_title']); ?>)
                </a>
            <?php endforeach; ?>
            </div>

            <h3>Field Mapping</h3>
            <p>Map your form fields to booking data. We auto-detected what we could.</p>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="obm_wizard_save">
                <input type="hidden" name="wizard_step" value="2">
                <input type="hidden" name="form_element_id" value="<?php echo esc_attr($form['element_id']); ?>">
                <input type="hidden" name="form_post_id" value="<?php echo esc_attr($form['post_id']); ?>">
                <?php wp_nonce_field('obm_wizard_action'); ?>
                <table class="widefat" style="max-width:600px;">
                <thead><tr><th>Booking Field</th><th>Form Field</th><th>Sample Value</th></tr></thead>
                <tbody>
                <?php
                $our_fields = [
                    'name' => 'Name *',
                    'email' => 'Email',
                    'phone' => 'Phone',
                    'date' => 'Requested Date',
                    'backup_date' => 'Backup Date',
                    'guests' => 'Number of Guests',
                    'guests_under_6' => 'Guests Under 6',
                    'message' => 'Message',
                ];
                foreach ($our_fields as $key => $label):
                    $mapped = $auto_map[$key] ?? '';
                    $sample = $mapped ? ($form['fields'][$mapped] ?? '') : '';
                ?>
                <tr>
                    <td><strong><?php echo $label; ?></strong></td>
                    <td>
                        <select name="map_<?php echo $key; ?>">
                            <option value="">-- Not mapped --</option>
                            <?php foreach ($form['fields'] as $fk => $fv): ?>
                            <option value="<?php echo esc_attr($fk); ?>" <?php selected($mapped, $fk); ?>><?php echo esc_html($fk); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><small><?php echo esc_html(substr($sample, 0, 50)); ?></small></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                </table>
                <p style="margin-top:15px;"><input type="submit" class="button button-primary button-hero" value="Continue &rarr;"></p>
            </form>
        <?php endif; ?>

        <?php elseif ($step === 3): ?>
        <h2>Step 3: Finish</h2>
        <div style="background:#fff;padding:20px;border-radius:8px;border:1px solid #ddd;">
            <h3>Setup Summary</h3>
            <table class="form-table">
            <tr><th>Business Name</th><td><?php echo esc_html($settings['business_name'] ?? ''); ?></td></tr>
            <tr><th>Staff Label</th><td><?php echo esc_html($settings['staff_label'] ?? 'Staff'); ?></td></tr>
            <tr><th>Form Mapped</th><td><?php echo $settings['elementor_form_id'] ? 'Yes' : 'Not yet (can configure later)'; ?></td></tr>
            </table>
            <h3>What's Next</h3>
            <ul style="list-style:disc;padding-left:20px;">
                <li>Add your staff members under Bookings &gt; <?php echo esc_html($settings['staff_label'] ?? 'Staff'); ?></li>
                <li>Connect Google Calendar under Bookings &gt; Settings</li>
                <li>Access the mobile app at <code><?php echo home_url('/booking-app/'); ?></code></li>
                <li>Set up SiteGround cron for reliable scheduling</li>
            </ul>
        </div>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="obm_wizard_save">
            <input type="hidden" name="wizard_step" value="3">
            <?php wp_nonce_field('obm_wizard_action'); ?>
            <p style="margin-top:20px;"><input type="submit" class="button button-primary button-hero" value="Complete Setup &amp; Go to Dashboard &rarr;"></p>
        </form>
        <?php endif; ?>
        </div>
        <?php
    }
}
