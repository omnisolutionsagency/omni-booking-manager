<html><body style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;">
<h2 style="color:<?php echo esc_attr($brand_color); ?>;"><?php echo esc_html($biz); ?> - Weekly Digest</h2>
<h3>Upcoming Booked (Next 7 Days)</h3>
<?php if (empty($next_week)): ?>
<p>No bookings this week.</p>
<?php else: ?>
<table style="width:100%;border-collapse:collapse;">
<tr style="background:<?php echo esc_attr($brand_color); ?>;color:#fff;">
<th style="padding:8px;text-align:left;">Name</th>
<th style="padding:8px;">Date</th>
<th style="padding:8px;">Guests</th>
<th style="padding:8px;">Duration</th>
<th style="padding:8px;"><?php echo esc_html($staff_label); ?></th></tr>
<?php foreach ($next_week as $b):
$s = $b->staff_id ? OBM_DB::get_staff_member($b->staff_id) : null; ?>
<tr style="border-bottom:1px solid #ddd;">
<td style="padding:8px;"><?php echo esc_html($b->name); ?></td>
<td style="padding:8px;"><?php echo $b->requested_date; ?></td>
<td style="padding:8px;"><?php echo $b->guests; ?></td>
<td style="padding:8px;"><?php echo esc_html($b->service_duration); ?></td>
<td style="padding:8px;"><?php echo $s ? esc_html($s->name) : 'Unassigned'; ?></td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>
<hr>
<h3>Pending Proposed Leads</h3>
<?php if (empty($proposed)): ?>
<p>No pending leads.</p>
<?php else: ?>
<ul>
<?php foreach ($proposed as $p): ?>
<li><strong><?php echo esc_html($p->name); ?></strong> - <?php echo $p->requested_date; ?> (<?php echo $p->guests; ?> guests)
<br><small><?php echo esc_html($p->email); ?> | <?php echo esc_html($p->phone); ?></small></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<hr>
<p>Booked: <?php echo count($booked); ?> | Proposed: <?php echo count($proposed); ?></p>
<p style="color:#999;font-size:12px;">Omni Booking Manager</p>
</body></html>
