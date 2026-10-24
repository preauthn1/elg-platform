<?php
require_once '../_init.php';
?>
<div class="side-modal-wrapper">
    <div class="vertical-align">
        <div class="table-cell">
            <div class="pdd-horizon-15">
                <h4>新增網域 - 請於下方輸入完整網域內容</h4>
                <form id="data-form">
                    <div class="form-group">
                        <p class="mrg-btm-15 font-size-13">網域</p>
                        <input type="text" class="form-control" placeholder="example.invalid" name="domain">
                    </div>
                    <div class="form-group">
                        <p class="mrg-btm-15 font-size-13">啟用狀態</p>
                        <div class="toggle-checkbox toggle-success checkbox-inline toggle-sm mrg-top-10">
                            <input type="checkbox" name="status" id="toggle1" checked>
                            <label for="toggle1"></label>
                        </div>
                    </div>
                    <input type="hidden" name="action" value="domain_add">
                    <input type="hidden" name="agent_id" value="<?=$_POST['id']?>">
                    <button class="btn btn-info btn-sm" id="save">確定新增</button>
                </form>
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
		$.blockUI({
		    baseZ: 9000,
		    overlayCSS: { backgroundColor: '#444444' }
		})
    	$.post( "<?=BASE_URL?>ajax.php",$( "#data-form" ).serialize(), function( data ) {
    		$.unblockUI();
        	if(data.code == 1000){
        		history.back();
        	}else{
        		if(data.code == '2002'){
            		alert(data.data);
            	}else{
            		alert(data.message);
            	}
        	}
		},'json');
	});
});
</script>