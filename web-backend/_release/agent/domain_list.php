<?php
require_once '../_init.php';
$data = array();
$data['agent_id'] = $_POST['id'];
$data['page'] = 1;
$data['per_page'] = 20;
$result = curlPost('api/agentDomain/list', $data);
if ($result['code'] != 1000) {
    if ($result['code'] > 9997) {
        header("location:" . BASE_URL . "login.php");
    } else {
        header('Location:' . $_SERVER['HTTP_REFERER']);
    }
    exit();
}

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
                                <h4 class="card-title">網域清單</h4>
                                <button id="add" data-uid="<?=$_POST['id']?>" data-toggle="modal" data-target="#domain_add" class="btn btn-sm btn-success btn-rounded">新增網域</button>
                                <p>可於下方清單管理啟用狀態及移除</p>
                                <div class="table-overflow">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>域名</th>
                                                <th>啟用狀態</th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php
                                        foreach ($result['data'] as $domain) {
                                            ?>
                                            <tr>
                                                <td><?=$domain['domain']?></td>
                                                <td>
                                                    <div class="toggle-checkbox toggle-success checkbox-inline toggle-sm mrg-top-10">
                                                        <input type="checkbox" name="status" id="status<?=$domain['id']?>" data-id="<?=$domain['id']?>" status="<?=($domain['status'] == 1) ? 0 : 1?>" class="domain_toggle_status" <?=($domain['status'] == 1) ? 'checked' : ''?>>
                                                        <label for="status<?=$domain['id']?>"></label>
                                                    </div>
                                                </td>
                                                <td>
                                                    <a href="" class="btn btn-danger btn-rounded btn-sm delete" data-id="<?=$domain['id']?>" >刪除網域</a>
                                                </td>
                                            </tr>
                                            <?php
                                        }
                                        ?>
                                        </tbody>
                                    </table>
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
	//修改狀態
	$( ".domain_toggle_status" ).click(function(e) {
		if(e.preventDefault){
			e.preventDefault();
		}else{
			window.event.returnValue == false;
		}
		var id = $(this).attr('data-id');
		var status = $(this).attr('status');
		$.blockUI({
		    baseZ: 9000,
		    overlayCSS: { backgroundColor: '#444444' }
		})
    	$.post( "<?=BASE_URL?>ajax.php",{'action':'domain_toggle_status','id':id,'status':status}, function( data ) {
    		$.unblockUI();
        	if(data.code == '1000'){
        		if(status == 1){
        			$('#status'+id).prop('checked', true);
        			$('#status'+id).attr('status',0);
        		}else{
        			$('#status'+id).prop('checked', false);
        			$('#status'+id).attr('status',1);
        		}
        	}else{
        		if(status == 1){
        			$('#status'+id).prop('checked', false);
        		}else{
        			$('#status'+id).prop('checked', true);
        		}
            	if(data.code == '2002'){
            		alert(data.data);
            	}else{
            		alert(data.message);
            	}
        	}            	
		},'json');
	});

	//刪除域名
	$( ".delete" ).click(function(e) {
		if(e.preventDefault){
			e.preventDefault();
		}else{
			window.event.returnValue == false;
		}
		var button = $(this);
		var id = $(this).attr('data-id');
		$.blockUI({
		    baseZ: 9000,
		    overlayCSS: { backgroundColor: '#444444' }
		})
    	$.post( "<?=BASE_URL?>ajax.php",{'action':'domain_delete','id':id}, function( data ) {
    		$.unblockUI();
        	if(data.code == '1000'){
				button.parents("td").parents("tr").remove(); 
        	}else{
            	if(data.code == '2002'){
            		alert(data.data);
            	}else{
            		alert(data.message);
            	}
        	}            	
		},'json');
	});

	//開啟新增域名框
	$('#add').on('click', function (e) {
		var id = $(this).attr('data-uid');
		$.post( "domain_add.php",{'id':id}, function( data ) {
			$( "#domain_add_content" ).html(data);
		});
	})
	
});
</script>
