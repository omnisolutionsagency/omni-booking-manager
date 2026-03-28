<?php
class OBM_Admin_Blocked_Dates {
    private static $instance = null;
    public static function get_instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {
        add_action('admin_post_obm_add_blocked_date', [$this, 'handle_add']);
        add_action('admin_post_obm_delete_blocked_date', [$this, 'handle_delete']);
    }

    public function handle_add() {
        check_admin_referer('obm_blocked_date_action');
        OBM_DB::insert_blocked_date([
            'date_start' => sanitize_text_field($_POST['date_start']),
            'date_end' => sanitize_text_field($_POST['date_end']),
            'reason' => sanitize_text_field($_POST['reason'])
        ]);
        wp_redirect(admin_url('admin.php?page=obm-blocked-dates&msg=added'));
        exit;
    }

    public function handle_delete() {
        check_admin_referer('obm_blocked_date_action');
        OBM_DB::delete_blocked_date(intval($_POST['blocked_id']));
        wp_redirect(admin_url('admin.php?page=obm-blocked-dates&msg=deleted'));
        exit;
    }

    public function render() {
        $dates = OBM_DB::get_blocked_dates();
        ?>
        <div class="wrap obm-wrap">
        <h1>Blocked Dates</h1>
        <?php if (isset($_GET['msg'])): ?>
        <div class="notice notice-success"><p>Date <?php echo esc_html($_GET['msg']); ?> successfully.</p></div>
        <?php endif; ?>
        <h2>Block Date Range</h2>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="obm_add_blocked_date">
            <?php wp_nonce_field('obm_blocked_date_action'); ?>
            <table class="form-table"><tr>
                <th>Start Date</th><td><input type="date" name="date_start" required></td>
            </tr><tr>
                <th>End Date</th><td><input type="date" name="date_end" required></td>
            </tr><tr>
                <th>Reason</th><td><input type="text" name="reason" class="regular-text"></td>
            </tr></table>
            <p><input type="submit" class="button button-primary" value="Block Dates"></p>
        </form>
        <h2>Currently Blocked</h2>
        <table class="wp-list-table widefat fixed striped">
        <thead><tr><th>Start</th><th>End</th><th>Reason</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($dates as $d): ?>
        <tr>
            <td><?php echo esc_html($d->date_start); ?></td>
            <td><?php echo esc_html($d->date_end); ?></td>
            <td><?php echo esc_html($d->reason); ?></td>
            <td>
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display:inline;">
                <input type="hidden" name="action" value="obm_delete_blocked_date">
                <input type="hidden" name="blocked_id" value="<?php echo $d->id; ?>">
                <?php wp_nonce_field('obm_blocked_date_action'); ?>
                <input type="submit" class="button" value="Remove" onclick="return confirm('Remove?');">
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <?php
    }
}
