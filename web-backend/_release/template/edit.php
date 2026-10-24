<?php
require_once '../_init.php';
if ($_POST['id'] != 0) {
    $data = array();
    $data['id'] = $_POST['id'];
    $result = curlPost('api/template/view', $data);
    if ($result['code'] != 1000) {
        if ($result['code'] > 9997) {
            header("location:" . BASE_URL . "login.php");
        } else {
            header('Location:' . $_SERVER['HTTP_REFERER']);
        }
        exit();
    }
    $data = $result['data'];
}
?>
<div class="side-modal-wrapper">
    <div class="vertical-align">
        <div class="table-cell">
            <div class="pdd-horizon-15">
                <h4><?=(! isset($data)) ? '新增版型' : '編輯版型'?></h4>
                <p class="mrg-btm-15 font-size-13">您可於下方變更版型相關資訊</p>
                <form id="data-form">
                    <div class="form-group">
                        <p class="mrg-btm-15 font-size-13">版型名稱</p>
                        <input type="text" class="form-control" placeholder="版型名稱" name="name" value="<?=$data['name']?>">
                    </div>
                    <div class="form-group">
                        <p class="mrg-btm-15 font-size-13">版型縮圖</p>
                        <div>
                            <label for="img-upload" class="pointer">
                                <img src="<?=$data['thumbnail']?>" id="show_image" style="max-height:300px;max-width:300px"/>
                                <input type="hidden" name="image" >
                                <span class="btn btn-default display-block no-mrg-btm">Choose file</span>
                                <input class="d-none" type="file" name="image_file" id="img-upload">
                            </label>
                        </div>
                    </div>
                    <div class="form-group">
                        <p class="mrg-btm-15 font-size-13">啟用狀態</p>
                        <div class="toggle-checkbox toggle-success checkbox-inline toggle-sm mrg-top-10">
                            <input type="checkbox" name="status" id="toggle1" <?=($data['status'] == 1) ? 'checked' : ''?>>
                            <label for="toggle1"></label>
                        </div>
                    </div>
                    <input type="hidden" name="id" value="<?=$data['id']?>">
                    <input type="hidden" name="action" value="save_template">
                    <button class="btn btn-info btn-sm" id="save">儲存</button>                                                    
                </form>
            </div>
        </div>
    </div>
</div>
<script>
$( document ).ready(function() {
	//儲存資料
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

    //上傳圖片後可預覽
	$('#img-upload').change(function(e) {
		if(e.preventDefault){
			e.preventDefault();
		}else{
			window.event.returnValue == false;
		}
    	var file = document.querySelector('input[type="file"]').files[0];
    	getBase64(file); // prints the base64 string
	});
});
//圖片轉成BASE64
function getBase64(file) {
   var reader = new FileReader();
   reader.readAsDataURL(file);
   reader.onload = function () {
	   //RETURN後變undefined，所以先在這處理
	   var image = reader.result;
	   	$('#show_image').attr('src',image);
		$('[name="image"]').val(image);
	   return reader.result;
   };
   reader.onerror = function (error) {
	   return 'Error: ', error;
   };
}
</script>
