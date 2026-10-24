<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';
?>
<!-- Page Container START -->
<div class="page-container">
<?php
require_once '../_top.php';
require_once '../_theme.php';
// 取訂單儲值方式
$result = curlPost('api/orderType/list');
if ($result['code'] == 1000) {
    $order_type = array();
    foreach ($result['data'] as $row) {
        $order_type[$row['id']] = $row['name'];
    }
}
// 取訂單列表
$data = array();
// username
if ($_GET['t_id'] != '' && isset($_GET['t_id'])) {
    $data['order_type_id'] = $_GET['t_id'];
}
// email
if ($_GET['no'] != '' && isset($_GET['no'])) {
    $data['order_no'] = $_GET['no'];
}
// 狀態
if ($_GET['username'] != '' && isset($_GET['username'])) {
    $data['username'] = $_GET['username'];
}
$data['page'] = 1;
$data['per_page'] = 1000;
$result = curlPost('api/order/list', $data);
if ($result['code'] == 1000) {
    $orders = $result['data'];
}

?>
<!-- Content Wrapper START -->
<div class="main-content">
    <div class="container-fluid">
        <div class="page-title">
            <h4><?=$lang['system_management']?> > <?=$lang['order_search']?></h4>
        </div>

        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-block">
                        <div class="row">
                            <div class="col-md-7">
                                <h4 class="card-title"><?=$lang['search']?></h4>
                                <form class="form-horizontal mrg-top-40 pdd-right-30">
                                    <div class="form-group row">
                                        <label for="form-1-1" class="col-md-2 control-label"><?=$lang['username']?></label>
                                        <div class="col-md-6">
                                            <input type="text" class="form-control" name="username" value="<?=$_GET['username']?>"/>
                                        </div>
                                    </div>
                                    <div class="form-group row">
                                        <label for="form-1-1" class="col-md-2 control-label"><?=$lang['ordernumber']?></label>
                                        <div class="col-md-8">
                                            <input type="text" class="form-control" name="no" value="<?=$_GET['no']?>"/>
                                        </div>
                                    </div>                                                   
                                    <div class="form-group row">
                                        <label for="form-1-1" class="col-md-2 control-label"><?=$lang['pay_method']?></label>
                                        <div class="col-md-4">
                                            <select id="selectize-dropdown" name="t_id">
                                                <option value=""><?=$lang['all']?></option>
                                                <?php
                                                foreach ($order_type as $key => $value) {
                                                    ?>
                                                <option value="<?=$key?>" <?=($_GET['t_id'] == $key) ? 'selected' : ''?>><?=$value?></option>
                                            <?php
                                                }
                                                ?>    
                                            </select>
                                        </div>
                                    </div>
                                    <div class="form-group row">
                                        <label for="form-1-2" class="col-md-2 control-label"></label>
                                        <div class="col-md-10">
                                            <button class="btn btn-primary"><?=$lang['search']?></button>
                                            <input type="reset" class="btn btn-default clear" value="<?=$lang['clear']?>" />
                                        </div>
                                    </div>
                                </form>
                            </div>

                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-block">
                        <div class="table-overflow">
                            <table id="dt-opt" class="table table-lg table-hover">
                                <thead>
                                    <tr>
                                        <th><?=$lang['ordernumber']?></th>
                                        <th><?=$lang['username']?></th>
                                        <th><?=$lang['method']?></th>
                                        <th><?=$lang['amount']?></th>
                                        <th><?=$lang['point']?></th>
                                        <th><?=$lang['status']?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php
                                foreach ($orders as $row) {
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="mrg-top-15">
                                                <span><?=$row['order_no']?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="list-info mrg-top-10">
                                                <div class="info">
                                                    <span class="title"><?=$row['username']?></span>
                                                    <span class="sub-title"><?=$lang['id']?> <?=$row['member_id']?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="mrg-top-15">
                                                <span><?=$order_type[$row['order_type_id']]?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="mrg-top-15">
                                                <span><?=$row['amount']?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="mrg-top-15">
                                                <span><?=$row['point']?></span>
                                            </div>
                                        </td>
                                        <td>
                                         <?php
                                    if ($row['status'] == 1) {
                                        $class = '';
                                        $text = $lang['success'];
                                    } else if ($row['status'] == 2) {
                                        $class = 'text-danger';
                                        $text = $lang['failure'];
                                    } else {
                                        $class = 'text-warning';
                                        $text = $lang['waiting'];
                                    }
                                    ?>
                                            <div class="mrg-top-15 <?=$class?>">
                                            <?=$text?>
                                            </div>
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
	//清除查詢資料
	$( ".clear" ).click(function(e) {
		if(e.preventDefault){
			e.preventDefault();
		}else{
			window.event.returnValue == false;
		}
		window.location = 'index.php';
	});
	});
</script>