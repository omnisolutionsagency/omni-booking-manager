<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="theme-color" content="<?php echo esc_attr($brand_color); ?>">
<title><?php echo esc_html($biz_name); ?></title>
<link rel="manifest" href="<?php echo OBM_PLUGIN_URL; ?>app/manifest.php">
<style>
*{margin:0;padding:0;box-sizing:border-box;}
:root{--brand:<?php echo esc_attr($brand_color); ?>;--dark:#1a1a2e;--card:#fff;--bg:#f0f2f5;--text:#333;--muted:#888;--yellow:#f0c040;--red:#cc3333;--blue:#4a90d9;}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:var(--bg);color:var(--text);-webkit-tap-highlight-color:transparent;padding-bottom:70px;}
.header{background:var(--brand);color:#fff;padding:12px 16px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;box-shadow:0 2px 8px rgba(0,0,0,.2);}
.header h1{font-size:18px;font-weight:600;}
.header .user{font-size:12px;opacity:.8;}
.nav{position:fixed;bottom:0;left:0;right:0;background:#fff;border-top:1px solid #ddd;display:flex;z-index:100;padding-bottom:env(safe-area-inset-bottom);}
.nav button{flex:1;padding:10px 0 8px;border:none;background:none;font-size:11px;color:var(--muted);display:flex;flex-direction:column;align-items:center;gap:3px;cursor:pointer;}
.nav button.active{color:var(--brand);font-weight:600;}
.nav button svg{width:22px;height:22px;}
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;padding:12px;}
.stat-box{background:var(--card);border-radius:10px;padding:10px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.08);}
.stat-box .num{font-size:22px;font-weight:700;display:block;}
.stat-box .lbl{font-size:10px;text-transform:uppercase;color:var(--muted);}
.stat-box.proposed{border-top:3px solid var(--yellow);}
.stat-box.booked{border-top:3px solid var(--brand);}
.stat-box.declined{border-top:3px solid var(--red);}
.stat-box.completed{border-top:3px solid var(--muted);}
.page{display:none;padding:0 12px 12px;}
.page.active{display:block;}
.lead-card{background:var(--card);border-radius:12px;padding:14px;margin-bottom:10px;box-shadow:0 1px 3px rgba(0,0,0,.08);cursor:pointer;}
.lead-card .lead-name{font-weight:600;font-size:16px;}
.lead-card .lead-meta{font-size:13px;color:var(--muted);margin-top:4px;}
.lead-card .lead-date{font-size:13px;color:var(--brand);font-weight:500;}
.badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;text-transform:uppercase;}
.badge-proposed{background:#fef3cd;color:#856404;}
.badge-booked{background:#d4edda;color:#155724;}
.badge-declined{background:#f8d7da;color:#721c24;}
.badge-completed{background:#e2e3e5;color:#383d41;}
.badge-dup{background:var(--red);color:#fff;font-size:10px;margin-left:4px;}
.filter-bar{display:flex;gap:6px;padding:12px;overflow-x:auto;-webkit-overflow-scrolling:touch;}
.filter-btn{padding:6px 14px;border-radius:20px;border:1px solid #ddd;background:#fff;font-size:13px;white-space:nowrap;cursor:pointer;}
.filter-btn.active{background:var(--brand);color:#fff;border-color:var(--brand);}
.detail-overlay{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:200;display:none;}
.detail-panel{position:fixed;bottom:0;left:0;right:0;background:#fff;z-index:201;border-radius:16px 16px 0 0;max-height:90vh;overflow-y:auto;padding:20px 16px;padding-bottom:calc(20px + env(safe-area-inset-bottom));display:none;animation:slideUp .3s ease;}
@keyframes slideUp{from{transform:translateY(100%)}to{transform:translateY(0)}}
.detail-panel h2{font-size:20px;margin-bottom:12px;}
.detail-row{padding:8px 0;border-bottom:1px solid #eee;}
.detail-row label{font-weight:600;font-size:13px;color:var(--muted);display:block;}
.detail-row span{font-size:14px;}
.detail-panel select,.detail-panel input,.detail-panel textarea{width:100%;padding:8px;border:1px solid #ddd;border-radius:8px;font-size:14px;margin-top:4px;}
.detail-panel textarea{min-height:60px;resize:vertical;}
.btn{display:inline-block;padding:10px 20px;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;text-align:center;width:100%;margin-top:8px;}
.btn-book{background:var(--brand);color:#fff;}
.btn-decline{background:var(--red);color:#fff;}
.btn-complete{background:var(--blue);color:#fff;}
.btn-secondary{background:#eee;color:#333;}
.btn-group{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:12px;}
.loading{text-align:center;padding:40px;color:var(--muted);}
.cal-nav{display:flex;align-items:center;justify-content:space-between;padding:12px;background:var(--card);border-radius:12px;margin-bottom:10px;}
.cal-nav h3{font-size:16px;}
.cal-nav button{background:none;border:1px solid #ddd;border-radius:8px;padding:6px 12px;cursor:pointer;}
.cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:2px;background:var(--card);border-radius:12px;padding:8px;}
.cal-hdr{text-align:center;font-size:11px;font-weight:600;color:var(--muted);padding:4px 0;}
.cal-cell{min-height:44px;padding:2px;border-radius:6px;font-size:11px;}
.cal-cell .cnum{font-weight:600;font-size:12px;}
.cal-cell.today{background:#fffde7;}
.cal-cell.blocked{background:#fce4ec;}
.cal-evt{padding:1px 3px;margin:1px 0;border-radius:3px;font-size:9px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.cal-evt.proposed{background:#fef3cd;}
.cal-evt.booked{background:#d4edda;}
.cal-empty{opacity:.3;}
</style>
</head>
<body>
<div class="header">
    <h1><?php echo esc_html($biz_name); ?></h1>
    <span class="user"><?php echo esc_html($user); ?></span>
</div>
<div id="stats" class="stats"></div>
<div id="page-leads" class="page active">
    <div class="filter-bar" id="filters"></div>
    <div id="lead-list"></div>
</div>
<div id="page-calendar" class="page">
    <div id="calendar"></div>
</div>
<div class="detail-overlay" id="overlay"></div>
<div class="detail-panel" id="detail"></div>
<nav class="nav">
    <button id="nav-leads" class="active" onclick="showPage('leads')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        Leads</button>
    <button id="nav-calendar" onclick="showPage('calendar')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        Calendar</button>
</nav>
<script>
const API="<?php echo esc_js($api); ?>";
const NONCE="<?php echo esc_js($nonce); ?>";
const H={"X-WP-Nonce":NONCE,"Content-Type":"application/json"};
const STAFF_LABEL="<?php echo esc_js($staff_label); ?>";
const DURATIONS=<?php echo json_encode($duration_opts); ?>;
let leads=[],staff=[],curFilter="",calMonth="";

async function api(path,opts={}){
    const r=await fetch(API+path,{headers:H,credentials:"same-origin",...opts});
    return r.json();
}
async function init(){
    [leads,staff]=await Promise.all([api("leads"),api("staff")]);
    loadStats();renderLeads();
    calMonth=new Date().toISOString().slice(0,7);
    loadCalendar();
}
async function loadStats(){
    const s=await api("stats");
    document.getElementById("stats").innerHTML=["proposed","booked","declined","completed"].map(k=>
        `<div class="stat-box ${k}"><span class="num">${s[k]}</span><span class="lbl">${k}</span></div>`
    ).join("");
    const t=s.proposed+s.booked+s.declined+s.completed;
    const btns=[["","All ("+t+")"],["proposed","Proposed"],["booked","Booked"],["declined","Declined"]];
    document.getElementById("filters").innerHTML=btns.map(b=>
        `<button class="filter-btn ${curFilter===b[0]?"active":""}" onclick="setFilter('${b[0]}')">${b[1]}</button>`
    ).join("");
}
function renderLeads(){
    const fl=curFilter?leads.filter(l=>l.status===curFilter):leads;
    const el=document.getElementById("lead-list");
    if(!fl.length){el.innerHTML='<div class="loading">No leads found.</div>';return;}
    el.innerHTML=fl.map(l=>{
        const dup=+l.duplicate_flag?'<span class="badge badge-dup">DUP</span>':"";
        const tm=l.start_time?" at "+l.start_time:"";
        return `<div class="lead-card" onclick="openDetail(${l.id})">
        <div style="display:flex;justify-content:space-between;align-items:start">
        <span class="lead-name">${esc(l.name)}${dup}</span>
        <span class="badge badge-${l.status}">${l.status}</span></div>
        <div class="lead-date">${l.requested_date}${tm}</div>
        <div class="lead-meta">${l.guests} guests | ${l.staff_name||"Unassigned"}</div>
        </div>`;
    }).join("");
}
function esc(s){const d=document.createElement("div");d.textContent=s;return d.innerHTML;}
function setFilter(f){curFilter=f;loadStats();renderLeads();}
function showPage(p){
    document.querySelectorAll(".page").forEach(e=>e.classList.remove("active"));
    document.querySelectorAll(".nav button").forEach(e=>e.classList.remove("active"));
    document.getElementById("page-"+p).classList.add("active");
    document.getElementById("nav-"+p).classList.add("active");
    if(p==="calendar")loadCalendar();
}
function openDetail(id){
    const l=leads.find(x=>x.id==id);if(!l)return;
    const staffOpts=staff.map(s=>`<option value="${s.id}" ${s.id==l.staff_id?"selected":""}>${esc(s.name)}</option>`).join("");
    const durOpts=DURATIONS.map(d=>`<option value="${d}" ${d===l.service_duration?"selected":""}>${d||"Select"}</option>`).join("");
    const payOpts=["none","deposit","full"].map(p=>`<option value="${p}" ${p===l.payment_status?"selected":""}>${p.charAt(0).toUpperCase()+p.slice(1)}</option>`).join("");
    let html=`<h2>${esc(l.name)} <span class="badge badge-${l.status}">${l.status}</span></h2>`;
    html+=`<div class="detail-row"><label>Email</label><span>${esc(l.email)}</span></div>`;
    html+=`<div class="detail-row"><label>Phone</label><span><a href="tel:${esc(l.phone)}">${esc(l.phone)}</a></span></div>`;
    html+=`<div class="detail-row"><label>Date</label><span>${l.requested_date}</span></div>`;
    if(l.backup_date&&l.backup_date!==l.requested_date)
        html+=`<div class="detail-row"><label>Backup</label><span>${l.backup_date} <button class="btn btn-secondary" style="width:auto;padding:4px 10px;margin:0" onclick="useBackup(${l.id})">Use This</button></span></div>`;
    html+=`<div class="detail-row"><label>Guests</label><span>${l.guests}${+l.guests_under_6?" ("+l.guests_under_6+" under 6)":""}</span></div>`;
    if(l.message)html+=`<div class="detail-row"><label>Message</label><span>${esc(l.message)}</span></div>`;
    html+=`<div class="detail-row"><label>Start Time</label><input type="time" id="d-time" value="${l.start_time||""}" onchange="updateField(${l.id},'start_time',this.value)"></div>`;
    html+=`<div class="detail-row"><label>Duration</label><select id="d-dur" onchange="updateField(${l.id},'service_duration',this.value)"><option value="">Select</option>${durOpts}</select></div>`;
    html+=`<div class="detail-row"><label>${STAFF_LABEL}</label><select id="d-staff" onchange="updateField(${l.id},'staff_id',this.value)"><option value="0">Unassigned</option>${staffOpts}</select></div>`;
    html+=`<div class="detail-row"><label>Payment</label><select id="d-pay" onchange="updateField(${l.id},'payment_status',this.value)">${payOpts}</select></div>`;
    html+=`<div class="detail-row"><label>Notes</label><textarea id="d-notes">${esc(l.notes||"")}</textarea>
    <button class="btn btn-secondary" style="margin-top:6px" onclick="saveNotes(${l.id})">Save Notes</button></div>`;
    if(l.status==="proposed")
        html+=`<div class="btn-group"><button class="btn btn-book" onclick="doAction(${l.id},'book')">Book</button>
        <button class="btn btn-decline" onclick="doAction(${l.id},'decline')">Decline</button></div>`;
    else if(l.status==="booked")
        html+=`<div class="btn-group">
        <button class="btn btn-complete" onclick="doAction(${l.id},'complete')">Completed</button>
        <button class="btn btn-decline" onclick="doAction(${l.id},'decline')">Cancel</button></div>`;
    html+=`<button class="btn btn-secondary" style="margin-top:12px" onclick="closeDetail()">Close</button>`;
    document.getElementById("detail").innerHTML=html;
    document.getElementById("detail").style.display="block";
    document.getElementById("overlay").style.display="block";
}
function closeDetail(){
    document.getElementById("detail").style.display="none";
    document.getElementById("overlay").style.display="none";
}
document.getElementById("overlay").onclick=closeDetail;
async function doAction(id,action){
    if(action==="decline"&&!confirm("Decline this lead?"))return;
    const body={};
    if(action==="book"){
        body.service_duration=document.getElementById("d-dur").value;
        body.staff_id=document.getElementById("d-staff").value;
        body.start_time=document.getElementById("d-time").value;
        body.payment_status=document.getElementById("d-pay").value;
        if(!body.service_duration){alert("Select a duration.");return;}
    }
    await api("leads/"+id+"/"+action,{method:"POST",body:JSON.stringify(body)});
    closeDetail();
    leads=await api("leads");loadStats();renderLeads();
}
async function updateField(id,field,val){
    const body={};body[field]=val;
    await api("leads/"+id,{method:"PATCH",body:JSON.stringify(body)});
    leads=await api("leads");
}
async function saveNotes(id){
    const n=document.getElementById("d-notes").value;
    await api("leads/"+id,{method:"PATCH",body:JSON.stringify({notes:n})});
    const i=leads.findIndex(l=>l.id==id);if(i>=0)leads[i].notes=n;
    alert("Notes saved.");
}
async function useBackup(id){
    if(!confirm("Switch to backup date?"))return;
    await api("leads/"+id+"/use-backup",{method:"POST"});
    leads=await api("leads");renderLeads();loadStats();closeDetail();
}
async function loadCalendar(){
    const data=await api("calendar?month="+calMonth);
    const byDate={};
    (data.leads||[]).forEach(l=>{
        if(!byDate[l.requested_date])byDate[l.requested_date]=[];
        byDate[l.requested_date].push(l);
    });
    const blocked={};
    (data.blocked||[]).forEach(b=>{
        let d=new Date(b.date_start+"T00:00:00");
        const end=new Date(b.date_end+"T00:00:00");
        while(d<=end){blocked[d.toISOString().slice(0,10)]=1;d.setDate(d.getDate()+1);}
    });
    const [y,m]=calMonth.split("-").map(Number);
    const first=new Date(y,m-1,1);
    const days=new Date(y,m,0).getDate();
    const dow=first.getDay();
    const today=new Date().toISOString().slice(0,10);
    const months=["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];
    let html=`<div class="cal-nav"><button onclick="calNav(-1)">&laquo;</button><h3>${months[m-1]} ${y}</h3><button onclick="calNav(1)">&raquo;</button></div>`;
    html+=`<div class="cal-grid">`;
    ["S","M","T","W","T","F","S"].forEach(d=>html+=`<div class="cal-hdr">${d}</div>`);
    for(let i=0;i<dow;i++)html+=`<div class="cal-cell cal-empty"></div>`;
    for(let d=1;d<=days;d++){
        const ds=`${y}-${String(m).padStart(2,"0")}-${String(d).padStart(2,"0")}`;
        let cls="cal-cell";
        if(ds===today)cls+=" today";
        if(blocked[ds])cls+=" blocked";
        html+=`<div class="${cls}"><span class="cnum">${d}</span>`;
        if(byDate[ds])byDate[ds].forEach(l=>
            html+=`<div class="cal-evt ${l.status}">${esc(l.name)}</div>`);
        html+=`</div>`;
    }
    html+=`</div>`;
    document.getElementById("calendar").innerHTML=html;
}
function calNav(dir){
    const [y,m]=calMonth.split("-").map(Number);
    const nd=new Date(y,m-1+dir,1);
    calMonth=nd.toISOString().slice(0,7);loadCalendar();
}
if("serviceWorker" in navigator){navigator.serviceWorker.register("<?php echo OBM_PLUGIN_URL; ?>app/sw.js");}
init();
</script>
</body></html>
