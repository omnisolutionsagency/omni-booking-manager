<?php
class OBM_Integration_Stripe {
    private static $instance = null;
    public static function get_instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'add_menu'], 99);
        add_action('admin_post_obm_stripe_settings', [$this, 'save_settings']);
        add_action('rest_api_init', [$this, 'register_webhook']);
        add_action('wp_ajax_obm_send_invoice', [$this, 'ajax_send_invoice']);
        add_action('wp_ajax_obm_process_refund', [$this, 'ajax_process_refund']);
    }

    private function get_secret_key() { return get_option('obm_stripe_secret_key', ''); }
    private function get_publishable_key() { return get_option('obm_stripe_publishable_key', ''); }
    private function get_webhook_secret() { return get_option('obm_stripe_webhook_secret', ''); }

    private function api($method, $endpoint, $body = []) {
        $url = 'https://api.stripe.com/v1/' . $endpoint;
        $args = [
            'method' => $method,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->get_secret_key(),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
        ];
        if (!empty($body)) {
            $args['body'] = $body;
        }
        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) return false;
        return json_decode(wp_remote_retrieve_body($response), true);
    }

    public function create_payment_link($lead, $amount, $type = 'deposit') {
        $biz = obm_get('business_name', get_bloginfo('name'));

        $line_items = [];
        $item_idx = 0;

        // For balance invoices, show the deposit as a line item with 100% discount
        if ($type === 'balance') {
            $deposit_paid = $this->get_paid_amount($lead->id, 'deposit');
            if ($deposit_paid > 0) {
                $line_items["line_items[{$item_idx}][price_data][currency]"] = 'usd';
                $line_items["line_items[{$item_idx}][price_data][unit_amount]"] = 0;
                $line_items["line_items[{$item_idx}][price_data][product_data][name]"] = "Deposit Already Paid — \${$deposit_paid}";
                $line_items["line_items[{$item_idx}][quantity]"] = 1;
                $item_idx++;
            }
        }

        $desc = "{$biz} - " . ucfirst($type) . " for {$lead->name} on {$lead->requested_date}";
        $line_items["line_items[{$item_idx}][price_data][currency]"] = 'usd';
        $line_items["line_items[{$item_idx}][price_data][unit_amount]"] = intval($amount * 100);
        $line_items["line_items[{$item_idx}][price_data][product_data][name]"] = $desc;
        $line_items["line_items[{$item_idx}][quantity]"] = 1;

        $result = $this->api('POST', 'checkout/sessions', array_merge([
            'mode' => 'payment',
            'payment_method_types[0]' => 'card',
            'success_url' => home_url('/booking-app/?payment=success'),
            'cancel_url' => home_url('/booking-app/?payment=cancelled'),
            'customer_email' => $lead->email,
            'metadata[lead_id]' => $lead->id,
            'metadata[type]' => $type,
        ], $line_items));

        if ($result && isset($result['id'])) {
            global $wpdb;
            $wpdb->insert(OBM_DB::get_prefix() . 'payments', [
                'lead_id' => $lead->id,
                'type' => $type,
                'amount' => $amount,
                'stripe_id' => $result['id'],
                'status' => 'pending',
                'created_at' => current_time('mysql'),
            ]);
            return $result['url'] ?? false;
        }
        return false;
    }

    public function process_refund($lead_id, $payment_id = null) {
        global $wpdb;
        $p = OBM_DB::get_prefix();

        if ($payment_id) {
            $payment = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}payments WHERE id=%d", $payment_id));
        } else {
            $payment = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$p}payments WHERE lead_id=%d AND status='paid' AND refunded=0 ORDER BY id DESC LIMIT 1",
                $lead_id
            ));
        }

        if (!$payment || !$payment->stripe_id) return false;

        $session = $this->api('GET', 'checkout/sessions/' . $payment->stripe_id);
        if (!$session || empty($session['payment_intent'])) return false;

        $result = $this->api('POST', 'refunds', [
            'payment_intent' => $session['payment_intent'],
        ]);

        if ($result && isset($result['id'])) {
            $wpdb->update($p . 'payments', ['refunded' => 1, 'status' => 'refunded'], ['id' => $payment->id]);
            OBM_DB::update_lead($lead_id, ['payment_status' => 'refunded']);
            return true;
        }
        return false;
    }

    public function get_paid_amount($lead_id, $type = null) {
        global $wpdb;
        $p = OBM_DB::get_prefix();
        $sql = "SELECT COALESCE(SUM(amount),0) FROM {$p}payments WHERE lead_id=%d AND status='paid' AND refunded=0";
        if ($type) $sql .= $wpdb->prepare(" AND type=%s", $type);
        return floatval($wpdb->get_var($wpdb->prepare($sql, $lead_id)));
    }

    public function get_payments($lead_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . OBM_DB::get_prefix() . "payments WHERE lead_id=%d ORDER BY created_at DESC",
            $lead_id
        ));
    }

    public function register_webhook() {
        register_rest_route('obm/v1', '/stripe-webhook', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_webhook'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle_webhook($request) {
        $payload = $request->get_body();
        $sig = $request->get_header('stripe-signature');
        $secret = $this->get_webhook_secret();

        if ($secret && $sig) {
            $elements = explode(',', $sig);
            $timestamp = '';
            $signatures = [];
            foreach ($elements as $el) {
                list($key, $val) = explode('=', $el, 2);
                if (trim($key) === 't') $timestamp = $val;
                if (trim($key) === 'v1') $signatures[] = $val;
            }
            $signed_payload = $timestamp . '.' . $payload;
            $expected = hash_hmac('sha256', $signed_payload, $secret);
            if (!in_array($expected, $signatures)) {
                return new WP_Error('invalid_sig', 'Invalid signature', ['status' => 400]);
            }
        }

        $event = json_decode($payload, true);
        if (!$event) return new WP_Error('bad_json', 'Bad JSON', ['status' => 400]);

        if ($event['type'] === 'checkout.session.completed') {
            $session = $event['data']['object'];
            $lead_id = $session['metadata']['lead_id'] ?? 0;
            $type = $session['metadata']['type'] ?? 'deposit';

            if ($lead_id) {
                global $wpdb;
                $p = OBM_DB::get_prefix();
                $wpdb->update($p . 'payments', ['status' => 'paid'], ['stripe_id' => $session['id']]);

                if ($type === 'full' || $type === 'balance') {
                    $new_status = 'full';
                } else {
                    $new_status = 'deposit';
                }
                OBM_DB::update_lead($lead_id, [
                    'payment_status' => $new_status,
                    'stripe_payment_intent' => $session['payment_intent'] ?? '',
                ]);
            }
        }

        return rest_ensure_response(['received' => true]);
    }

    public function ajax_send_invoice() {
        check_ajax_referer('obm_nonce', 'nonce');
        if (!current_user_can('obm_manage_bookings')) wp_send_json_error('Unauthorized');

        $lead_id = intval($_POST['lead_id']);
        $amount = floatval($_POST['amount']);
        $type = sanitize_text_field($_POST['payment_type'] ?? 'deposit');
        $lead = OBM_DB::get_lead($lead_id);
        if (!$lead) wp_send_json_error('Lead not found');

        $url = $this->create_payment_link($lead, $amount, $type);
        if ($url) {
            $biz = obm_get('business_name', get_bloginfo('name'));
            $subj = "{$biz} - " . ucfirst($type) . " Payment";

            if ($type === 'balance') {
                $deposit_paid = $this->get_paid_amount($lead_id, 'deposit');
                $total = $deposit_paid + $amount;
                $body = "Hi {$lead->name},\n\n";
                $body .= "Here's a summary of your booking on {$lead->requested_date}:\n\n";
                $body .= "Total: \$" . number_format($total, 2) . "\n";
                $body .= "Deposit Paid: \$" . number_format($deposit_paid, 2) . "\n";
                $body .= "Balance Due: \$" . number_format($amount, 2) . "\n\n";
                $body .= "Please complete your remaining balance:\n\n{$url}\n\nThank you!\n{$biz}";
            } else {
                $body = "Hi {$lead->name},\n\nPlease complete your {$type} payment of \${$amount}:\n\n{$url}\n\nThank you!\n{$biz}";
            }

            wp_mail($lead->email, $subj, $body);

            if ($type === 'deposit') {
                OBM_DB::update_lead($lead_id, ['deposit_amount' => $amount]);
            } elseif ($type === 'balance') {
                $deposit_paid = $this->get_paid_amount($lead_id, 'deposit');
                OBM_DB::update_lead($lead_id, ['total_amount' => $deposit_paid + $amount]);
            } else {
                OBM_DB::update_lead($lead_id, ['total_amount' => $amount]);
            }

            wp_send_json_success(['payment_url' => $url]);
        } else {
            wp_send_json_error('Failed to create payment link');
        }
    }

    public function ajax_process_refund() {
        check_ajax_referer('obm_nonce', 'nonce');
        if (!current_user_can('obm_manage_bookings')) wp_send_json_error('Unauthorized');

        $lead_id = intval($_POST['lead_id']);
        if ($this->process_refund($lead_id)) {
            wp_send_json_success();
        } else {
            wp_send_json_error('Refund failed');
        }
    }

    public function add_menu() {
        add_submenu_page('obm-dashboard', 'Payments', 'Payments', 'manage_options', 'obm-int-stripe', [$this, 'render_settings']);
    }

    public function save_settings() {
        check_admin_referer('obm_stripe_settings_action');
        update_option('obm_stripe_secret_key', sanitize_text_field($_POST['secret_key']));
        update_option('obm_stripe_publishable_key', sanitize_text_field($_POST['publishable_key']));
        update_option('obm_stripe_webhook_secret', sanitize_text_field($_POST['webhook_secret']));
        update_option('obm_stripe_deposit_amount', floatval($_POST['deposit_amount']));
        wp_redirect(admin_url('admin.php?page=obm-int-stripe&msg=saved'));
        exit;
    }

    public function render_settings() {
        ?>
        <div class="wrap obm-wrap">
        <h1>Stripe Payments Settings</h1>
        <?php if (isset($_GET['msg'])): ?>
        <div class="notice notice-success"><p>Settings saved.</p></div>
        <?php endif; ?>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="obm_stripe_settings">
            <?php wp_nonce_field('obm_stripe_settings_action'); ?>
            <table class="form-table">
            <tr><th>Publishable Key</th><td><input type="text" name="publishable_key" value="<?php echo esc_attr($this->get_publishable_key()); ?>" class="regular-text"></td></tr>
            <tr><th>Secret Key</th><td><input type="password" name="secret_key" value="<?php echo esc_attr($this->get_secret_key()); ?>" class="regular-text"></td></tr>
            <tr><th>Webhook Secret</th><td><input type="password" name="webhook_secret" value="<?php echo esc_attr($this->get_webhook_secret()); ?>" class="regular-text">
                <p class="description">Webhook URL: <code><?php echo rest_url('obm/v1/stripe-webhook'); ?></code></p></td></tr>
            <tr><th>Default Deposit Amount</th><td>$<input type="number" name="deposit_amount" value="<?php echo esc_attr(get_option('obm_stripe_deposit_amount', 50)); ?>" step="0.01" min="0" style="width:100px;"></td></tr>
            </table>
            <p><input type="submit" class="button button-primary" value="Save Stripe Settings"></p>
        </form>
        </div>
        <?php
    }
}
