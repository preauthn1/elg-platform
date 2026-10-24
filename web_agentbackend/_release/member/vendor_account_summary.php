<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';

//檢查權限
if (!check_permission_menuCode('member_vendor_account_summary')) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

?>
<style>
@media (min-width: 1200px) {
    .modal-xl {
        max-width: 1140px;
    }
}
</style>
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
                <h4><?= $lang['member_lower_management'] ?> > <?= $lang['menu_member_vendor_account_summary'] ?></h4>
            </div>

            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="row">

                                <div class="col-md-2">

                                    <label>
                                        請輸入會員帳號
                                    </label>

                                    <input type="text" class="form-control" id="username" placeholder="member username"
                                        name="username" value="">

                                </div>

                                <div class="col-md-1">

                                    <button type="button" class="btn" onclick="search()" style="margin-top: 25px;">
                                        <?=$lang["search"] ?>
                                    </button>

                                </div>


                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row" id="result">
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
<div class="modal fade" id="flow_limit">
    <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content" id="flow_limit_content">
        </div>
    </div>
</div>
<script>
$(document).ready(function() {

});


function search() {

    let username = $("#username").val();

    if (username === undefined || username == "") {
        alert('please input username.');
    }

    $.blockUI();

    let url = "vendor_account_summary_ajax.php?username=" + username;

    $.get(url, function(data) {
        $("#result").html(data);
        $.unblockUI();
    });


}
</script>