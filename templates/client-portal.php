<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>My Booking - <?php echo esc_html($biz); ?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f5f5f5;color:#333;}
.container{max-width:600px;margin:0 auto;padding:20px;}
.header{background:<?php echo esc_attr($brand); ?>;color:#fff;padding:20px;text-align:center;border-radius:12px 12px 0 0;}
.header h1{font-size:22px;}
.header .status{display:inline-block;padding:4px 12px;border-radius:12px;font-size:13px;font-weight:600;margin-top:8px;text-transform:uppercase;}
.status-proposed{background:rgba(255,255,255,.2);}
.status-booked{background:#4caf50;}
.status-completed{background:#78909c;}
.card{background:#fff;border:1px solid #ddd;padding:20px;margin-bottom:0;}
.card:last-of-type{border-radius:0 0 12px 12px;}
.card h2{font-size:18px;margin-bottom:12px;color:<?php echo esc_attr($brand); ?>;}
.info-row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f0f0f0;}
.info-row:last-child{border:none;}
.info-row .label{color:#888;font-size:14px;}
.info-row .value{font-weight:600;font-size:14px;}
.checklist{list-style:none;padding:0;}
.checklist li{padding:10px 0;border-bottom:1px solid #f0f0f0;display:flex;align-items:center;gap:10px;font-size:14px;}
.checklist li:last-child{border:none;}
.check-icon{width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;}
.check-done{background:#d4edda;color:#155724;}
.check-pending{background:#fff3cd;color:#856404;}
.btn{display:block;text-align:center;padding:12px;border-radius:8px;text-decoration:none;font-weight:600;font-size:14px;margin-top:8px;}
.btn-primary{background:<?php echo esc_attr($brand); ?>;color:#fff;}
.btn-outline{background:#fff;color:<?php echo esc_attr($brand); ?>;border:1px solid <?php echo esc_attr($brand); ?>;}
.payment-row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #f0f0f0;}
.payment-amount{font-weight:700;}
.payment-status{font-size:12px;padding:2px 8px;border-radius:10px;}
.pay-paid{background:#d4edda;color:#155724;}
.pay-pending{background:#fff3cd;color:#856404;}
.pay-refunded{background:#f8d7da;color:#721c24;}
.footer{text-align:center;padding:15px;color:#999;font-size:12px;}
</style>
</head>
<body>
<div class="container">
<div class="header">
    <h1><?php echo esc_html($biz); ?></h1>
    <p>Booking for <?php echo esc_html($lead->name); ?></p>
    <span class="status status-<?php echo $lead->status; ?>"><?php echo ucfirst($lead->status); ?></span>
</div>

<div class="card">
    <h2>Booking Details</h2>
    <div class="info-row"><span class="label">Date</span><span class="value"><?php echo esc_html($lead->requested_date); ?></span></div>
    <?php if ($lead->start_time): ?>
    <div class="info-row"><span class="label">Time</span><span class="value"><?php echo esc_html($lead->start_time); ?></span></div>
    <?php endif; ?>
    <div class="info-row"><span class="label">Guests</span><span class="value"><?php echo $lead->guests; ?><?php if ($lead->guests_under_6): ?> (<?php echo $lead->guests_under_6; ?> under 6)<?php endif; ?></span></div>
    <?php if ($lead->service_duration): ?>
    <div class="info-row"><span class="label">Duration</span><span class="value"><?php echo esc_html($lead->service_duration); ?></span></div>
    <?php endif; ?>
    <?php if ($staff): ?>
    <div class="info-row"><span class="label"><?php echo esc_html($staff_label); ?></span><span class="value"><?php echo esc_html($staff->name); ?></span></div>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Checklist</h2>
    <ul class="checklist">
        <li>
            <span class="check-icon <?php echo $lead->status === 'booked' || $lead->status === 'completed' ? 'check-done' : 'check-pending'; ?>">
                <?php echo $lead->status === 'booked' || $lead->status === 'completed' ? '&#10003;' : '&#8226;'; ?>
            </span>
            Booking <?php echo $lead->status === 'booked' || $lead->status === 'completed' ? 'Confirmed' : 'Pending'; ?>
        </li>
        <?php if ($integrations->is_active('stripe')): ?>
        <li>
            <span class="check-icon <?php echo $lead->payment_status !== 'none' ? 'check-done' : 'check-pending'; ?>">
                <?php echo $lead->payment_status !== 'none' ? '&#10003;' : '&#8226;'; ?>
            </span>
            Payment: <?php echo ucfirst($lead->payment_status ?: 'none'); ?>
        </li>
        <?php endif; ?>
        <?php if ($integrations->is_active('waivers')): ?>
        <li>
            <span class="check-icon <?php echo $waiver_signed ? 'check-done' : 'check-pending'; ?>">
                <?php echo $waiver_signed ? '&#10003;' : '&#8226;'; ?>
            </span>
            Waiver: <?php echo $waiver_signed ? 'Signed' : 'Not yet signed'; ?>
            <?php if (!$waiver_signed && $waiver_url): ?>
            <a href="<?php echo esc_url($waiver_url); ?>" class="btn btn-outline" style="margin:0;padding:6px 12px;display:inline;">Sign Now</a>
            <?php endif; ?>
        </li>
        <?php endif; ?>
    </ul>
</div>

<?php if (!empty($payments)): ?>
<div class="card">
    <h2>Payments</h2>
    <?php foreach ($payments as $pay): ?>
    <div class="payment-row">
        <div>
            <strong><?php echo ucfirst($pay->type); ?></strong>
            <br><small><?php echo date('M j, Y', strtotime($pay->created_at)); ?></small>
        </div>
        <div style="text-align:right;">
            <span class="payment-amount">$<?php echo number_format($pay->amount, 2); ?></span>
            <br><span class="payment-status pay-<?php echo $pay->status; ?>"><?php echo ucfirst($pay->status); ?></span>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="footer">
    <p>Questions? Contact us at <?php echo get_option('admin_email'); ?></p>
    <p>Powered by Omni Booking Manager</p>
</div>
</div>
</body>
</html>
