<?php
require_once '../_init.php';
$result = curlPost('api/validation/list');
if ($result['code'] != 1000) {
    if ($result['code'] > 9997) {
        header("location:" . BASE_URL . "login.php");
    } else {
        header('Location:' . $_SERVER['HTTP_REFERER']);
    }
    exit();
}
require_once '../_header.php';
require_once '../_menu.php';
?>
<!-- Page Container START -->
<div class="page-container">
<?php
require_once '../_top.php';
require_once '../_theme.php';
?>
<!-- Content Wrapper START -->
<div class="main-content">
    <div class="container-fluid">
        <div class="page-title">
            <h4><?=$lang['system_management']?> > <?=$lang['member_certification_management']?></h4>
        </div>

        <div class="row">
        <?php
        foreach ($result['data'] as $row) {
            ?>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-block">
                        <h5 class="mrg-btm-10"><?=$row['name']?></h5>
                        <img class="img-fluid mrg-horizon-auto mrg-btm-15" src="/assets/images/others/modal-sm.jpg" alt="">
                        <div class="row">
                            <div class="col-lg-8 col-md-12">
                                <p class="font-size-12"></p>
                            </div>
                            <div class="col-lg-4 col-md-12 text-right pdd-top-5">
                                <button class="status_btn btn btn-sm <?=($row['status'] == 1) ? 'btn-primary' : 'btn-default'?>"  tid="<?=$row['id']?>" status="<?=($row['status'] == 1) ? 0 : 1?>"><?=($row['status'] == 1) ? '啟用' : '停用'?></button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php
        }
        ?>
        </div>
    </div>
</div>
<!-- Content Wrapper END -->
<?php
require_once '../_bottom.php';
require_once '../_footer.php';
?>
<script>
$( document ).ready(function() {
	$( ".status_btn" ).click(function(e) {
		if(e.preventDefault){
			e.preventDefault();
		}else{
			window.event.returnValue == false;
		}
		var id = $(this).attr('tid');
		var status = $(this).attr('status');
		$.blockUI({
		    baseZ: 9000,
		    overlayCSS: { backgroundColor: '#444444' }
		})
    	$.post( "<?=BASE_URL?>ajax.php",{'action':'validation_setting','id':id,'status':status}, function( data ) {
    		$.unblockUI();
        	if(data.code == '1000'){
            	window.location.reload();
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