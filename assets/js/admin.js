jQuery(function($){
$(".obm-expand-btn").on("click",function(){
    var id=$(this).data("id");
    $("#obm-detail-"+id).toggle();
});
$(".obm-action-btn").on("click",function(){
    var btn=$(this),id=btn.data("id"),action=btn.data("action");
    var row=btn.closest(".obm-detail-row");
    var data={action:"obm_update_status",nonce:obm.nonce,lead_id:id,status_action:action};
    if(action==="book"){
        var dur=row.find(".obm-duration").val();
        var staff=row.find(".obm-staff").val();
        var time=row.find(".obm-start-time").val();
        var pay=row.find(".obm-payment").val();
        if(!dur){alert("Please select a duration.");return;}
        data.duration=dur;data.staff_id=staff;data.start_time=time;data.payment=pay;
    }
    if(action==="decline"&&!confirm("Decline this lead? Calendar event will be removed.")){return;}
    btn.prop("disabled",true).text("Processing...");
    $.post(obm.ajax_url,data,function(r){
        if(r.success){location.reload();}
        else{alert("Error: "+(r.data||"Unknown"));btn.prop("disabled",false);}
    }).fail(function(){alert("Request failed.");btn.prop("disabled",false);});
});
$(".obm-save-notes").on("click",function(){
    var btn=$(this),id=btn.data("id");
    var notes=btn.parent().find("textarea").val();
    btn.prop("disabled",true).text("Saving...");
    $.post(obm.ajax_url,{action:"obm_save_notes",nonce:obm.nonce,lead_id:id,notes:notes},function(r){
        btn.prop("disabled",false);
        if(r.success){btn.text("Saved!").delay(1500).queue(function(){$(this).text("Save Notes").dequeue();});}
        else{alert("Error saving notes.");}
    });
});
$(".obm-staff").on("change",function(){
    var el=$(this),id=el.data("id");
    $.post(obm.ajax_url,{action:"obm_assign_staff",nonce:obm.nonce,lead_id:id,staff_id:el.val()});
});
$(".obm-duration").on("change",function(){
    var el=$(this),id=el.data("id");
    $.post(obm.ajax_url,{action:"obm_set_duration",nonce:obm.nonce,lead_id:id,duration:el.val()});
});
$(".obm-start-time").on("change",function(){
    var el=$(this),id=el.data("id");
    $.post(obm.ajax_url,{action:"obm_set_start_time",nonce:obm.nonce,lead_id:id,start_time:el.val()});
});
$(".obm-payment").on("change",function(){
    var el=$(this),id=el.data("id");
    $.post(obm.ajax_url,{action:"obm_set_payment",nonce:obm.nonce,lead_id:id,payment_status:el.val()});
});
$(".obm-use-backup").on("click",function(){
    var btn=$(this),id=btn.data("id");
    if(!confirm("Switch to backup date? The current date will become the backup.")){return;}
    btn.prop("disabled",true).text("Switching...");
    $.post(obm.ajax_url,{action:"obm_use_backup_date",nonce:obm.nonce,lead_id:id},function(r){
        if(r.success){location.reload();}
        else{alert("Error: "+(r.data||"Unknown"));btn.prop("disabled",false);}
    });
});
});
