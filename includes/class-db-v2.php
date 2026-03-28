<?php
/**
 * V2.0 Database additions - run once to add new tables/columns.
 * Called from OBM_DB::create_tables() or manually via wp eval.
 */
class OBM_DB_V2 {
    public static function upgrade() {
        global $wpdb;
        $p = OBM_DB::get_prefix();
        $c = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Add columns to leads table if missing
        $cols = $wpdb->get_col("DESCRIBE {$p}leads", 0);
        if (!in_array('stripe_invoice_id', $cols)) {
            $wpdb->query("ALTER TABLE {$p}leads ADD COLUMN stripe_invoice_id VARCHAR(255) DEFAULT '' AFTER google_event_id");
        }
        if (!in_array('stripe_payment_intent', $cols)) {
            $wpdb->query("ALTER TABLE {$p}leads ADD COLUMN stripe_payment_intent VARCHAR(255) DEFAULT '' AFTER stripe_invoice_id");
        }
        if (!in_array('waiver_status', $cols)) {
            $wpdb->query("ALTER TABLE {$p}leads ADD COLUMN waiver_status VARCHAR(20) DEFAULT 'pending' AFTER stripe_payment_intent");
        }
        if (!in_array('waiver_token', $cols)) {
            $wpdb->query("ALTER TABLE {$p}leads ADD COLUMN waiver_token VARCHAR(64) DEFAULT '' AFTER waiver_status");
        }
        if (!in_array('portal_token', $cols)) {
            $wpdb->query("ALTER TABLE {$p}leads ADD COLUMN portal_token VARCHAR(64) DEFAULT '' AFTER waiver_token");
        }
        if (!in_array('deposit_amount', $cols)) {
            $wpdb->query("ALTER TABLE {$p}leads ADD COLUMN deposit_amount DECIMAL(10,2) DEFAULT 0 AFTER payment_status");
        }
        if (!in_array('total_amount', $cols)) {
            $wpdb->query("ALTER TABLE {$p}leads ADD COLUMN total_amount DECIMAL(10,2) DEFAULT 0 AFTER deposit_amount");
        }

        // Add receive_digest to staff table if missing
        $staff_cols = $wpdb->get_col("DESCRIBE {$p}staff", 0);
        if (!in_array('receive_digest', $staff_cols)) {
            $wpdb->query("ALTER TABLE {$p}staff ADD COLUMN receive_digest TINYINT DEFAULT 1 AFTER active");
        }

        // Payments table
        dbDelta("CREATE TABLE {$p}payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            lead_id INT NOT NULL,
            type VARCHAR(20) NOT NULL DEFAULT 'deposit',
            amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            stripe_id VARCHAR(255) DEFAULT '',
            status VARCHAR(20) DEFAULT 'pending',
            refunded TINYINT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $c");

        // Waivers table
        dbDelta("CREATE TABLE {$p}waivers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            lead_id INT NOT NULL,
            signature_data LONGTEXT,
            signed_name VARCHAR(255) DEFAULT '',
            signed_at DATETIME DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT '',
            waiver_text LONGTEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $c");

        // Email templates
        dbDelta("CREATE TABLE {$p}email_templates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(50) NOT NULL,
            name VARCHAR(100) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            body LONGTEXT NOT NULL,
            trigger_event VARCHAR(50) DEFAULT '',
            delay_days INT DEFAULT 0,
            active TINYINT DEFAULT 1
        ) $c");

        // Email log
        dbDelta("CREATE TABLE {$p}email_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            lead_id INT NOT NULL,
            template_slug VARCHAR(50) DEFAULT '',
            subject VARCHAR(255) DEFAULT '',
            recipient VARCHAR(255) DEFAULT '',
            status VARCHAR(20) DEFAULT 'sent',
            sent_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $c");

        // SMS log
        dbDelta("CREATE TABLE {$p}sms_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            lead_id INT NOT NULL,
            phone VARCHAR(100) DEFAULT '',
            message TEXT,
            status VARCHAR(20) DEFAULT 'sent',
            twilio_sid VARCHAR(255) DEFAULT '',
            sent_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $c");

        // Seed default email templates if empty
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$p}email_templates");
        if ($count == 0) {
            self::seed_templates();
        }

        update_option('obm_db_version', '2.1.0');
    }

    private static function seed_templates() {
        global $wpdb;
        $p = OBM_DB::get_prefix();
        $biz_tag = '{business_name}';
        $templates = [
            [
                'slug' => 'welcome',
                'name' => 'Booking Confirmation',
                'subject' => 'Your booking is confirmed!',
                'body' => "Hi {client_name},\n\nGreat news! Your booking for {date} has been confirmed.\n\nDetails:\n- Date: {date}\n- Time: {time}\n- Guests: {guests}\n- {staff_label}: {staff_name}\n\nIf you have any questions, feel free to reply to this email.\n\nSee you soon!\n{$biz_tag}",
                'trigger_event' => 'on_booked',
                'delay_days' => 0,
            ],
            [
                'slug' => 'reminder_7d',
                'name' => '7-Day Reminder',
                'subject' => 'Your booking is coming up in one week!',
                'body' => "Hi {client_name},\n\nJust a friendly reminder that your booking is coming up in one week on {date}.\n\nPlease make sure to:\n- Arrive 15 minutes early\n- Let us know if your party size has changed\n\nSee you soon!\n{$biz_tag}",
                'trigger_event' => 'reminder',
                'delay_days' => -7,
            ],
            [
                'slug' => 'reminder_1d',
                'name' => '1-Day Reminder',
                'subject' => 'See you tomorrow!',
                'body' => "Hi {client_name},\n\nThis is a reminder that your booking is tomorrow, {date}!\n\nTime: {time}\nGuests: {guests}\n\nWe look forward to seeing you!\n{$biz_tag}",
                'trigger_event' => 'reminder',
                'delay_days' => -1,
            ],
            [
                'slug' => 'thank_you',
                'name' => 'Post-Trip Thank You',
                'subject' => 'Thanks for joining us!',
                'body' => "Hi {client_name},\n\nThank you for choosing {$biz_tag}! We hope you had an amazing time.\n\nWe would love to hear about your experience. If you have a moment, please leave us a review:\n{review_link}\n\nHope to see you again!\n{$biz_tag}",
                'trigger_event' => 'post_trip',
                'delay_days' => 1,
            ],
            [
                'slug' => 'deposit_reminder',
                'name' => 'Deposit Reminder',
                'subject' => 'Deposit needed to confirm your booking',
                'body' => "Hi {client_name},\n\nWe noticed we haven't received your deposit yet for your booking on {date}.\n\nPlease complete your payment to confirm your reservation:\n{payment_link}\n\nIf you have any questions, just reply to this email.\n\n{$biz_tag}",
                'trigger_event' => 'deposit_reminder',
                'delay_days' => 2,
            ],
        ];

        foreach ($templates as $t) {
            $wpdb->insert($p . 'email_templates', $t);
        }
    }
}
