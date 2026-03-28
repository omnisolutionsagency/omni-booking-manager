<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Waiver - <?php echo esc_html($biz); ?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f5f5f5;color:#333;}
.container{max-width:700px;margin:0 auto;padding:20px;}
.header{background:<?php echo esc_attr($brand); ?>;color:#fff;padding:20px;text-align:center;border-radius:12px 12px 0 0;}
.header h1{font-size:22px;}
.content{background:#fff;padding:20px;border:1px solid #ddd;}
.waiver-text{background:#f9f9f9;border:1px solid #eee;padding:15px;border-radius:8px;max-height:300px;overflow-y:auto;font-size:14px;line-height:1.6;white-space:pre-wrap;margin-bottom:20px;}
.booking-info{background:#e8f5e9;padding:12px;border-radius:8px;margin-bottom:20px;}
.booking-info p{margin:4px 0;font-size:14px;}
.form-group{margin-bottom:15px;}
.form-group label{display:block;font-weight:600;margin-bottom:5px;}
.form-group input{width:100%;padding:10px;border:1px solid #ddd;border-radius:8px;font-size:16px;}
.sig-pad{border:2px solid #333;border-radius:8px;background:#fff;cursor:crosshair;touch-action:none;width:100%;height:200px;}
.sig-actions{display:flex;gap:10px;margin-top:8px;}
.btn{display:block;width:100%;padding:14px;border:none;border-radius:8px;font-size:16px;font-weight:600;cursor:pointer;margin-top:15px;text-align:center;}
.btn-primary{background:<?php echo esc_attr($brand); ?>;color:#fff;}
.btn-secondary{background:#eee;color:#333;width:auto;padding:8px 16px;}
.btn:disabled{opacity:.5;cursor:not-allowed;}
.signed-msg{background:#d4edda;color:#155724;padding:20px;border-radius:8px;text-align:center;font-size:18px;}
.footer{text-align:center;padding:15px;color:#999;font-size:12px;background:#fff;border:1px solid #ddd;border-top:none;border-radius:0 0 12px 12px;}
.checkbox-group{display:flex;align-items:start;gap:10px;margin:15px 0;}
.checkbox-group input[type="checkbox"]{margin-top:3px;width:auto;}
</style>
</head>
<body>
<div class="container">
<div class="header">
    <h1><?php echo esc_html($biz); ?></h1>
    <p>Liability & Consent Waiver</p>
</div>
<div class="content">
<?php if ($signed): ?>
    <div class="signed-msg">
        <p>This waiver has already been signed. Thank you!</p>
    </div>
<?php else: ?>
    <div class="booking-info">
        <p><strong>Name:</strong> <?php echo esc_html($lead->name); ?></p>
        <p><strong>Date:</strong> <?php echo esc_html($lead->requested_date); ?></p>
        <p><strong>Guests:</strong> <?php echo esc_html($lead->guests); ?></p>
    </div>

    <h3>Please read and sign below:</h3>
    <div class="waiver-text"><?php echo esc_html($waiver_text); ?></div>

    <div class="form-group">
        <label>Full Legal Name</label>
        <input type="text" id="signed-name" placeholder="Type your full name" required>
    </div>

    <div class="checkbox-group">
        <input type="checkbox" id="agree-check">
        <label for="agree-check">I have read the above waiver and voluntarily agree to its terms on behalf of myself and any minors in my party.</label>
    </div>

    <div class="form-group">
        <label>Signature (draw with finger or mouse)</label>
        <canvas id="sig-canvas" class="sig-pad"></canvas>
        <div class="sig-actions">
            <button class="btn-secondary" onclick="clearSig()">Clear</button>
        </div>
    </div>

    <button class="btn btn-primary" id="submit-btn" onclick="submitWaiver()" disabled>Sign Waiver</button>
    <p id="error-msg" style="color:red;margin-top:10px;display:none;"></p>

<script>
const canvas = document.getElementById('sig-canvas');
const ctx = canvas.getContext('2d');
let drawing = false, hasSig = false;

function resize() {
    const rect = canvas.parentElement.getBoundingClientRect();
    canvas.width = rect.width;
    canvas.height = 200;
    ctx.strokeStyle = '#000';
    ctx.lineWidth = 2;
    ctx.lineCap = 'round';
}
resize();
window.addEventListener('resize', resize);

function getPos(e) {
    const rect = canvas.getBoundingClientRect();
    const t = e.touches ? e.touches[0] : e;
    return { x: t.clientX - rect.left, y: t.clientY - rect.top };
}

canvas.addEventListener('mousedown', e => { drawing = true; ctx.beginPath(); const p = getPos(e); ctx.moveTo(p.x, p.y); });
canvas.addEventListener('mousemove', e => { if (!drawing) return; const p = getPos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); hasSig = true; checkReady(); });
canvas.addEventListener('mouseup', () => drawing = false);
canvas.addEventListener('touchstart', e => { e.preventDefault(); drawing = true; ctx.beginPath(); const p = getPos(e); ctx.moveTo(p.x, p.y); });
canvas.addEventListener('touchmove', e => { e.preventDefault(); if (!drawing) return; const p = getPos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); hasSig = true; checkReady(); });
canvas.addEventListener('touchend', () => drawing = false);

function clearSig() { ctx.clearRect(0, 0, canvas.width, canvas.height); hasSig = false; checkReady(); }

document.getElementById('agree-check').addEventListener('change', checkReady);
document.getElementById('signed-name').addEventListener('input', checkReady);

function checkReady() {
    const name = document.getElementById('signed-name').value.trim();
    const agree = document.getElementById('agree-check').checked;
    document.getElementById('submit-btn').disabled = !(name && agree && hasSig);
}

function submitWaiver() {
    const btn = document.getElementById('submit-btn');
    btn.disabled = true;
    btn.textContent = 'Submitting...';
    const data = new FormData();
    data.append('action', 'obm_sign_waiver');
    data.append('token', '<?php echo esc_js($token); ?>');
    data.append('signed_name', document.getElementById('signed-name').value);
    data.append('signature', canvas.toDataURL('image/png'));
    fetch('<?php echo esc_js($ajax_url); ?>', { method: 'POST', body: data })
    .then(r => r.json())
    .then(r => {
        if (r.success) {
            document.querySelector('.content').innerHTML = '<div class="signed-msg"><p>Waiver signed successfully! Thank you, <?php echo esc_js($lead->name); ?>.</p></div>';
        } else {
            document.getElementById('error-msg').textContent = r.data || 'Error signing waiver.';
            document.getElementById('error-msg').style.display = 'block';
            btn.disabled = false;
            btn.textContent = 'Sign Waiver';
        }
    })
    .catch(() => { btn.disabled = false; btn.textContent = 'Sign Waiver'; });
}
</script>
<?php endif; ?>
</div>
<div class="footer">Powered by Omni Booking Manager</div>
</div>
</body>
</html>
