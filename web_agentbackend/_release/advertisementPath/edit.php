<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';

//確認權限
if (!isset($check_auth['advertisementPath/index'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

?>
<div class="page-container">
<?php
require_once '../_top.php';
require_once '../_theme.php';
// 取詳細資料
if ($_GET['id']) {
    $data = array();
    $data['id'] = $_GET['id'];
    $result = curlPost('api/advertisementPath/view', $data);
    if ($result['code'] == 1000) {
        $path = $result['data'];
    }
}
?>
    <!-- Content Wrapper START -->
    <div class="main-content">
        <div class="container-fluid">
            <div class="page-title">
                <h4>
                    <?= $lang['system_management'] ?> > <?= $lang['menu_advertisement_path'] ?> > <?= ($_GET['id']) ? $lang['edit'] : $lang['add'] ?>
                </h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="mrg-top-40">
                                <div class="row">
                                    <div class="col-md-8 ml-auto mr-auto">
                                        <form id="data-form">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label><?= $lang['slot_name'] ?></label>
                                                        <input type="text" placeholder="" class="form-control" name="name"
                                                               value="<?=$path['name']?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label><?= $lang['number_of_column'] ?></label>
                                                        <input type="text" placeholder="" class="form-control" name="column"
                                                               value="<?=$path['column']?>">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label><?= $lang['picture_width'] ?></label>
                                                        <input type="text" placeholder="100px" class="form-control" name="width"
                                                               value="<?=$path['width']?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label><?= $lang['picture_height'] ?></label>
                                                        <input type="text" placeholder="100px" class="form-control" name="height"
                                                               value="<?=$path['height']?>">
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="row">
                                                <div class="col-md-12">
                                                    <div class="form-group">
                                                        <label><?= $lang['status'] ?></label>
                                                        <div class="col-md-10">
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="status" value="1" id="rad1" <?=($path['status'] == 1) ? 'checked' : ''?>>
                                                                <label for="rad1"><?= $lang['enable'] ?></label>
                                                            </div>
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="status" value="0" id="rad2" <?=($path['status'] != 1) ? 'checked' : ''?>>
                                                                <label for="rad2"><?= $lang['disable'] ?></label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-12 col-xs-6">
                                                    <div class="text-right mrg-top-5">
                                                    	<input type="hidden" name="id" value="<?=$_GET['id']?>">
                                                    	<input type="hidden" name="action" value="save_advertisement_path">
                                                        <input type="button" class="btn btn-default" value="<?= $lang['btn_back'] ?>"
                                                               onclick="history.back();" />
                                                        <button type="submit" class="btn btn-primary" id="save">
                                                            <?= $lang['btn_update'] ?>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>

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
    <!-- Content Wrapper END -->
<?php
require_once '../_bottom.php';
?>
</div>
<!-- Page Container END -->
<?php
require_once '../_footer.php';
?>
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