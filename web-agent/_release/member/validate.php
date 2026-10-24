<?php
require_once '../_init.php';
$data = array();
$data['id'] = $_POST['id'];
$result = curlPost('api/memberValidation/view', $data);
if ($result['code'] != 1000) {
    if ($result['code'] > 9997) {
        header("location:" . BASE_URL . "login.php");
    } else {
        header('Location:' . $_SERVER['HTTP_REFERER']);
    }
    exit();
}
$validate = $result['data'];
?>
<div class="modal-body height-160">
	<a class="modal-close" href="" data-dismiss="modal" style="float: right;">
        <i class="ti-close"></i>
    </a>
    <div class="vertical-align text-center">
        <div class="table-cell">
            <div class="container">
                <div class="row">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-block">
                                <h4 class="card-title"><?=$lang['auth_approval']?></h4>
                                <div class="side-modal-wrapper">
                                    <form id="data-form">
                                        <div class="form-group">
                                            <p class="mrg-btm-15 font-size-13"><?=$validate['name']?></p>
                                        </div>
                                        <div class="form-group">
                                        	<img src="<?=$validate['image']?>" height="150">
                                        </div>
                                        <div class="form-group" style="text-align: left;">
                                            <label><?=$lang['status']?></label>
                                            <div class="col-md-10">
                                            <div class="radio radio-inline">
                                                <input type="radio" name="status" value="1" id="rad1">
                                                <label for="rad1"><?=$lang['enable']?></label>
                                            </div>
                                            <div class="radio radio-inline">
                                                <input type="radio" name="status" value="2"  id="rad2">
                                                <label for="rad2"><?=$lang['disable']?></label>
                                             </div>
                                            </div>
                                        </div>
                                        
                                        <div class="form-group" style="text-align: left;">
                                            <label><?=$lang['memo']?></label>
                                            <input type="text" placeholder="18" class="form-control" name="remark">
                                        </div>
                                        <input type="hidden" name="action" value="save_validate">
                                        <input type="hidden" name="id" value="<?=$validate['id']?>">
                                        <button class="btn btn-info btn-sm" id="save"><?=$lang['btn_update']?></button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
$( document ).ready(function() {
	$( "#save" ).click(function(e) {
		if(e.preventDefault){
			e.preventDefault();
		}else{
			window.event.returnValue == false;
		}
		var msg = '';
		var status = $('[name="status"]:checked').val();
		var remark = $('[name="remark"]').val();
		console.log(status);
		console.log(remark);
		if(status == undefined){
			msg = $lang['choose_pass_ornot'];
		}else if(status == 2 && remark ==''){
			msg = $lang['rejected_reason'];
		}
		if(msg==''){
    		$.blockUI({
    		    baseZ: 9000,
    		    overlayCSS: { backgroundColor: '#444444' }
    		})
    		
        	$.post( "<?=BASE_URL?>ajax.php",$( "#data-form" ).serialize(), function( data ) {
        		$.unblockUI();
            	if(data.code == 1000){
            		window.location.reload();
            	}else{
            		if(data.code == '2002'){
                		alert(data.data);
                	}else{
                		alert(data.message);
                	}
            	}
    		},'json');
		}else{
			alert(msg);
		}
	});
});
</script>