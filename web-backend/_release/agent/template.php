<?php
require_once '../_init.php';
// 取可用模版
$data = array();
$data['agent_id'] = $_POST['id'];
$data['page'] = 1;
$data['per_page'] = 100;
$result = curlPost('api/agentTemplate/list', $data);
if ($result['code'] == 1000 && count($result['data']) > 0) {
    $templates = $result['data'];
    ?>
<div class="card">
    <div class="card-heading border bottom">
        <h4 class="card-title">網站主題設定</h4>
    </div>
    <div class="card-block">
        <div class="row">
        <?php
    foreach ($templates as $row) {
        ?>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-block">
                        <h4 class="card-title"><?=$row['template_name']?></h4>
                        <div class="card-media">
                            <img class="img-responsive" src="<?=$row['template_thumbnail']?>" alt="">
                        </div>
                        <div class="mrg-top-25" style="text-align:center">
                            <a href="" class="btn <?=($row['status'] == 1) ? 'btn-primary' : 'btn-default'?> no-mrg-btm status_btn" tid="<?=$row['id']?>" status="<?=($row['status'] == 1) ? 0 : 1?>">
                                <span class="ei <?=($row['status'] == 1) ? 'ei-checked' : 'ei-close'?> "></span><?=($row['status'] == 1) ? '啟用' : '停用'?>
                            </a>
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
<?php
}
?>
<script>
$( document ).ready(function() {
	$( ".status_btn" ).click(function(e) {
		if(e.preventDefault){
			e.preventDefault();
		}else{
			window.event.returnValue == false;
		}
		var id = '<?=$_POST['id']?>';
		var template_id = $(this).attr('tid');
		var status = $(this).attr('status');
		$.blockUI({
		    baseZ: 9000,
		    overlayCSS: { backgroundColor: '#444444' }
		})
    	$.post( "<?=BASE_URL?>ajax.php",{'action':'agent_template_setting','id':id,'template_id':template_id,'status':status}, function( data ) {
    		$.unblockUI();
        	if(data.code == '1000'){
            	$.post( "template.php",{'id':id}, function( data ) {
            		$( "#template" ).html(data);
            	});
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
                    