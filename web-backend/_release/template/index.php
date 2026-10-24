<?php
require_once '../_init.php';
$data = [
    "page" => 1,
    "per_page" => 100
];
$result = curlPost('api/template/list', $data);
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
                <h4>系統管理 > 版型管理</h4>
            </div>
            
            <div class="row">
             <?php
            foreach ($result['data'] as $row) {
                ?>
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-block">
                            <h5 class="mrg-btm-10"><?=$row['name']?></h5>
                            <img class="img-fluid mrg-horizon-auto mrg-btm-15" src="<?=$row['thumbnail']?>" alt="">
                            <div class="row">
                                <div class="col-lg-8 col-md-12">
                                </div>
                                <div class="col-lg-4 col-md-12 text-right pdd-top-5">
                                    <button data-toggle="modal" data-target="#template_modal" class="btn btn-sm btn-primary edit" data-uid="<?=$row['id']?>">編輯</button>
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
        <!-- Modal START-->
        <div class="modal slide-in-right modal-right fade " id="template_modal">
            <div class="modal-dialog" role="document">
                <div class="modal-content" id="template_content">
                </div>
            </div>
        </div>
        <!-- Modal END-->
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
	//開啟編輯框
	$(document).on('click', '.edit', function(e){
		var id = $(this).attr('data-uid');
		$.post( "edit.php",{'id':id}, function( data ) {
			$( "#template_content" ).html(data);
		});
	})
});
</script>