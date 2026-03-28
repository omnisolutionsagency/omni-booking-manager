<?php
class OBM_Admin_Staff {
    private static $instance = null;
    public static function get_instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {
        add_action('admin_post_obm_add_staff', [$this, 'handle_add']);
        add_action('admin_post_obm_update_staff', [$this, 'handle_update']);
    }

    public function handle_add() {
        check_admin_referer('obm_staff_action');
        OBM_DB::insert_staff([
            'name' => sanitize_text_field($_POST['name']),
            'phone' => sanitize_text_field($_POST['phone']),
            'email' => sanitize_email($_POST['email'])
        ]);
        wp_redirect(admin_url('admin.php?page=obm-staff&msg=added'));
        exit;
    }

    public function handle_update() {
        check_admin_referer('obm_staff_action');
        $id = intval($_POST['staff_id']);
        OBM_DB::update_staff($id, [
            'name' => sanitize_text_field($_POST['name']),
            'phone' => sanitize_text_field($_POST['phone']),
            'email' => sanitize_email($_POST['email']),
            'active' => isset($_POST['active']) ? 1 : 0
        ]);
        wp_redirect(admin_url('admin.php?page=obm-staff&msg=updated'));
        exit;
    }

    public function render() {
        $staff = OBM_DB::get_staff(false);
        $label = obm_get('staff_label', 'Staff');
        ?>
        <div class="wrap obm-wrap">
        <h1><?php echo esc_html($label); ?> Management</h1>
        <?php if (isset($_GET['msg'])): ?>
        <div class="notice notice-success"><p><?php echo esc_html($label); ?> <?php echo esc_html($_GET['msg']); ?> successfully.</p></div>
        <?php endif; ?>
        <h2>Add <?php echo esc_html($label); ?></h2>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="obm_add_staff">
            <?php wp_nonce_field('obm_staff_action'); ?>
            <table class="form-table"><tr>
                <th>Name</th><td><input type="text" name="name" required></td>
            </tr><tr>
                <th>Phone</th><td><input type="text" name="phone"></td>
            </tr><tr>
                <th>Email</th><td><input type="email" name="email"></td>
            </tr></table>
            <p><input type="submit" class="button button-primary" value="Add <?php echo esc_attr($label); ?>"></p>
        </form>
        <h2>Current <?php echo esc_html($label); ?> Members</h2>
        <table class="wp-list-table widefat fixed striped">
        <thead><tr><th>Name</th><th>Phone</th><th>Email</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($staff as $s): ?>
        <tr>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="obm_update_staff">
            <input type="hidden" name="staff_id" value="<?php echo $s->id; ?>">
            <?php wp_nonce_field('obm_staff_action'); ?>
            <td><input type="text" name="name" value="<?php echo esc_attr($s->name); ?>"></td>
            <td><input type="text" name="phone" value="<?php echo esc_attr($s->phone); ?>"></td>
            <td><input type="email" name="email" value="<?php echo esc_attr($s->email); ?>"></td>
            <td><label><input type="checkbox" name="active" <?php checked($s->active, 1); ?>> Active</label></td>
            <td><input type="submit" class="button" value="Update"></td>
            </form>
        </tr>
        <?php endforeach; ?>
        </tbody></table>
        </div>
        <?php
    }
}
