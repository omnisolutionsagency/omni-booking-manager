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
$(".obm-send-email").on("click",function(){
    var btn=$(this),id=btn.data("id");
    var template=btn.closest("div").find(".obm-email-template").val();
    if(!template){alert("Select a template first.");return;}
    btn.prop("disabled",true).text("Sending...");
    var ajax_action = template === "review_request" ? "obm_send_review_request" : "obm_send_email";
    var data = {action:ajax_action,nonce:obm.nonce,lead_id:id};
    if(ajax_action === "obm_send_email") data.template = template;
    $.post(obm.ajax_url,data,function(r){
        if(r.success){btn.text("Sent!").delay(2000).queue(function(){$(this).text("Send").prop("disabled",false).dequeue();});}
        else{alert("Error: "+(r.data||"Failed to send"));btn.prop("disabled",false).text("Send");}
    }).fail(function(){btn.prop("disabled",false).text("Send");});
});
$(".obm-send-invoice").on("click",function(){
    var btn=$(this),id=btn.data("id"),type=btn.data("type");
    var wrap=btn.closest("div");
    var amountInput=type==="balance"?wrap.find(".obm-balance-amount"):wrap.find(".obm-invoice-amount");
    var amount=amountInput.val();
    if(!amount||amount<=0){alert("Enter a valid amount.");return;}
    if(!confirm("Send "+type+" invoice for $"+amount+"?")){return;}
    btn.prop("disabled",true).text("Creating...");
    $.post(obm.ajax_url,{action:"obm_send_invoice",nonce:obm.nonce,lead_id:id,payment_type:type,amount:amount},function(r){
        if(r.success){btn.text("Sent!");setTimeout(function(){location.reload();},1500);}
        else{alert("Error: "+(r.data||"Failed to create invoice. Check Stripe keys."));btn.prop("disabled",false).text(type==="balance"?"Send Balance Invoice":"Send Deposit Invoice");}
    }).fail(function(){btn.prop("disabled",false).text(type==="balance"?"Send Balance Invoice":"Send Deposit Invoice");});
});
$(".obm-refund").on("click",function(){
    var btn=$(this),id=btn.data("id"),payId=btn.data("payment");
    if(!confirm("Process refund? This cannot be undone.")){return;}
    btn.prop("disabled",true).text("Processing...");
    $.post(obm.ajax_url,{action:"obm_process_refund",nonce:obm.nonce,lead_id:id,payment_id:payId},function(r){
        if(r.success){btn.text("Refunded");setTimeout(function(){location.reload();},1500);}
        else{alert("Error: "+(r.data||"Refund failed"));btn.prop("disabled",false).text("Refund");}
    }).fail(function(){btn.prop("disabled",false).text("Refund");});
});
$(".obm-send-portal").on("click",function(){
    var btn=$(this),id=btn.data("id");
    btn.prop("disabled",true).text("Sending...");
    $.post(obm.ajax_url,{action:"obm_send_portal_link",nonce:obm.nonce,lead_id:id},function(r){
        if(r.success){btn.text("Sent!");if(r.data&&r.data.portal_url){btn.after(' <a href="'+r.data.portal_url+'" target="_blank" class="button" style="font-size:12px;">View Portal</a>');}}
        else{alert("Error: "+(r.data||"Failed to send"));btn.prop("disabled",false).text("Send Portal Link");}
    }).fail(function(){btn.prop("disabled",false).text("Send Portal Link");});
});
$(".obm-send-sms").on("click",function(){
    var btn=$(this),id=btn.data("id");
    var msg=btn.closest("div").find(".obm-sms-message").val();
    if(!msg){alert("Type a message first.");return;}
    btn.prop("disabled",true).text("Sending...");
    $.post(obm.ajax_url,{action:"obm_send_sms",nonce:obm.nonce,lead_id:id,message:msg},function(r){
        if(r.success){btn.text("Sent!").delay(2000).queue(function(){$(this).text("Send").prop("disabled",false).dequeue();});btn.closest("div").find(".obm-sms-message").val("");}
        else{alert("Error: "+(r.data||"Failed to send. Check Twilio settings."));btn.prop("disabled",false).text("Send");}
    }).fail(function(){btn.prop("disabled",false).text("Send");});
});
$(".obm-send-review").on("click",function(){
    var btn=$(this),id=btn.data("id");
    btn.prop("disabled",true).text("Sending...");
    $.post(obm.ajax_url,{action:"obm_send_review_request",nonce:obm.nonce,lead_id:id},function(r){
        if(r.success){btn.text("Sent!").css("color","green");}
        else{alert("Error: "+(r.data||"Failed. Check review URL in settings."));btn.prop("disabled",false).text("Send Review Request");}
    }).fail(function(){btn.prop("disabled",false).text("Send Review Request");});
});
$(".obm-send-waiver").on("click",function(){
    var btn=$(this),id=btn.data("id");
    btn.prop("disabled",true).text("Sending...");
    $.post(obm.ajax_url,{action:"obm_send_waiver",nonce:obm.nonce,lead_id:id},function(r){
        if(r.success){btn.text("Sent!").after('<span style="color:#d63638;font-size:12px;margin-left:5px;">Sent — awaiting signature</span>');}
        else{alert("Error: "+(r.data||"Failed to send"));btn.prop("disabled",false).text("Send Waiver");}
    }).fail(function(){btn.prop("disabled",false).text("Send Waiver");});
});
$(".obm-cal-event[data-lead-id]").on("click",function(e){
    e.preventDefault();
    var id=$(this).data("lead-id");
    var row=$("#obm-detail-"+id);
    if(row.length){
        $(".obm-detail-row").hide();
        row.show();
        $("html,body").animate({scrollTop:row.offset().top-100},300);
    }
});
});
