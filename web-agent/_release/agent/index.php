<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';
//確認權限
if (!isset($check_auth['agent/index'])) {
    echo '您無此權限';
    exit;
}
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
                <h4><?= $lang['agnet_management'] ?> > <?= $lang['agent_account_management'] ?></h4>
            </div>
            <!-- <a href="edit.php" class="btn btn-inverse btn-success"><?= $lang['add_agent'] ?></a> -->
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="row">
                                <div class="col-md-7">
                                    <h4 class="card-title"><?= $lang['search'] ?></h4>
                                    <form class="form-horizontal mrg-top-40 pdd-right-30">
                                        <div class="form-group row">
                                            <label for="form-1-1"
                                                class="col-md-2 control-label"><?= $lang['username'] ?></label>
                                            <div class="col-md-6">
                                                <input type="text" class="form-control" name="username"
                                                    value="<?= $_GET['username'] ?>" />
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <label for="form-1-1"
                                                class="col-md-2 control-label"><?= $lang['email'] ?></label>
                                            <div class="col-md-10">
                                                <input type="text" class="form-control" name="email"
                                                    value="<?= $_GET['email'] ?>" />
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <label for="form-1-1"
                                                class="col-md-2 control-label"><?= $lang['status'] ?></label>
                                            <div class="col-md-4">
                                                <select id="selectize-dropdown" name="status">
                                                    <option value=""><?= $lang['all'] ?></option>
                                                    <option value="1" <?= ($_GET['status'] == '1') ? 'selected' : '' ?>>
                                                        <?= $lang['enable'] ?></option>
                                                    <option value="0" <?= ($_GET['status'] == '0') ? 'selected' : '' ?>>
                                                        <?= $lang['disable'] ?></option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <label for="form-1-2" class="col-md-2 control-label"></label>
                                            <div class="col-md-10">
                                                <button class="btn btn-primary"><?= $lang['search'] ?></button>
                                                <input type="reset" class="btn btn-default clear"
                                                    value="<?= $lang['clear'] ?>" />
                                            </div>
                                        </div>
                                    </form>
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
<div class="modal fade" id="domain_list">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content" id="domain_content">
        </div>
    </div>
</div>
<?php if ($_SESSION['has_transaction']) { ?>
<div class="modal fade" id="transaction">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content" id="transaction_content">
        </div>
    </div>
</div>
<?php } ?>
<script>
$(document).ready(function() {
    $.blockUI();
    $.get('index_ajax.php' + $(location).attr('search'), function(data) {
        $("#result").html(data);
        $.unblockUI();
    });

    //修改狀態
    $(document).on('click', '.agent_toggle_status', function(e) {
        if (e.preventDefault) {
            e.preventDefault();
        } else {
            window.event.returnValue == false;
        }
        var id = $(this).attr('id');
        var status = $(this).attr('status');
        $.blockUI({
            baseZ: 9000,
            overlayCSS: {
                backgroundColor: '#444444'
            }
        })
        $.post("<?= BASE_URL ?>ajax.php", {
            'action': 'agent_toggle_status',
            'id': id,
            'status': status
        }, function(data) {
            $.unblockUI();
            if (data.code == '1000') {
                window.location.reload();
            } else {
                if (data.code == '2002') {
                    alert(data.data);
                } else {
                    alert(data.message);
                }
            }
        }, 'json');
    });

    //清除查詢資料
    $(".clear").click(function(e) {
        if (e.preventDefault) {
            e.preventDefault();
        } else {
            window.event.returnValue == false;
        }
        window.location = 'index.php';
    });

    //開啟域名列表框
    $(document).on('click', '.domain', function(e) {
        var id = $(this).attr('data-uid');
        $.post("domain_list.php", {
            'id': id
        }, function(data) {
            $("#domain_content").html(data);
        });
    })

    //開啟轉帳視窗
    $(document).on('click', '.transaction', function(e) {
        var id = $(this).attr('data-uid');
        var type = $(this).attr('data-type');
        $.post("doTransaction.php", {
            'id': id,
            'type': type,
        }, function(data) {
            $("#transaction_content").html(data);
        });
    })

    //複製推廣註冊網址
    $('.copy').on('click', function(e) {
        if (e.preventDefault) {
            e.preventDefault();
        } else {
            window.event.returnValue == false;
        }
        console.log($(this).attr('data-code'));
        console.log(window.clipboardData);
        if (window.clipboardData) {
            window.clipboardData.clearData();
            window.clipboardData.setData("Text", '...');
            alert("复制成功！")
        }
        // 	var $temp = $(this);
        // 	  $("body").append($temp);
        // 	  $temp.val($(this).attr('data-code')).select();
        // 	  document.execCommand("copy");
        // 	  $temp.remove();
        // 		var code = $(this).attr('data-code');
        // 		window.clipboardData.setData("Text",code);
        // 		alert(code);
    })

});

//下載
$(document).on('click', '#excel', function(e) {
        var username = $("[name='username']").val();
        var email = $("[name='email']").val();
        var status = $("[name='status']").val();
        window.location = 'ajax.php?action=agent_account&username=' + username + '&email=' + email + '&status=' + status;
        });    
</script>