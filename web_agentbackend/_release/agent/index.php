<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';
//確認權限
if (!isset($check_auth['agent/index'])) {
    echo '您無此權限';
    exit;
}

$isAgentGroupPermission = check_permission_menuCode('agent_group');

$response_api = curlPost('api/agent/group/dropdownList');

$groups = $response_api['data'];

$group_type_csutomized = array_filter($groups, function ($var) {
    return ($var['agent_group_type'] == 1);
});

$group_type_cash_flow= array_filter($groups, function ($var) {
    return ($var['agent_group_type'] == 2);
});

$group_type_chat= array_filter($groups, function ($var) {
    return ($var['agent_group_type'] == 4);
});

?>
<div class="page-container">
    <?php
    require_once '../_top.php';
    require_once '../_theme.php';
    ?>
    <div class="main-content">
        <div class="container-fluid">
            <div class="page-title">
                <h4><?= $lang['agnet_management'] ?> > <?= $lang['agent_account_management'] ?></h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="row">
                                <div class="col-md-10">
                                    <h4 class="card-title"><?= $lang['search'] ?></h4>
                                    <form class="form-horizontal mrg-top-40 pdd-right-30">
                                        <div class="row">
                                            <div class="col-md-5">
                                                <div class="form-group row">
                                                    <label for="form-1-1" class="col-md-4 control-label">
                                                        <?= $lang['username'] ?>
                                                    </label>
                                                    <div class="col-md-8">
                                                        <input type="text" class="form-control" name="username"
                                                               value="<?= $_GET['username'] ?>" />
                                                        <div class="checkbox">
                                                            <input id="fuzzy_query" name="fuzzy_query" type="checkbox"
                                                                   value="1" <?= ($_GET['fuzzy_query'] == 1 ? "checked" : "") ?>>
                                                            <label for="fuzzy_query"><?=$lang['fuzzy_query']?></label>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="form-group row">
                                                    <label for="form-1-1" class="col-md-4 control-label">
                                                        <?= $lang['account_type'] ?>
                                                    </label>
                                                    <div class="col-md-8">
                                                        <select id="selectize-dropdown" name="account_type">
                                                            <option value="all"><?= $lang['all'] ?></option>
                                                            <option value="agent"
                                                                <?= ($_GET['account_type'] == 'agent') ? 'selected' : '' ?>>
                                                                <?= $lang['agent_account'] ?></option>
                                                            <option value="sub"
                                                                <?= ($_GET['account_type'] == 'sub') ? 'selected' : '' ?>>
                                                                <?= $lang['sub_account'] ?></option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row">
                                                    <label for="form-1-1" class="col-md-4 control-label">
                                                        <?= $lang['email'] ?>
                                                    </label>
                                                    <div class="col-md-8">
                                                        <input type="text" class="form-control" name="email"
                                                            value="<?= $_GET['email'] ?>" />
                                                    </div>
                                                </div>
                                                <div class="form-group row">
                                                    <label for="form-1-1"
                                                        class="col-md-4 control-label"><?= $lang['status'] ?></label>
                                                    <div class="col-md-8">
                                                        <select id="selectize-dropdown" name="status">
                                                            <option value=""><?= $lang['all'] ?></option>
                                                            <option value="1"
                                                                <?= ($_GET['status'] == '1') ? 'selected' : '' ?>>
                                                                <?= $lang['enable'] ?></option>
                                                            <option value="0"
                                                                <?= ($_GET['status'] == '0') ? 'selected' : '' ?>>
                                                                <?= $lang['disable'] ?></option>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-5">
                                                <?php
                                                /*有權限的人才看的到這塊 */
                                                if ($isAgentGroupPermission) {
                                                    ?>
                                                <div class="form-group row">
                                                    <label for="form-1-1" class="col-md-4 control-label">
                                                        <?= $lang['agent_group_type_1'] ?>
                                                    </label>
                                                    <div class="col-md-8">
                                                        <select id="selectize-dropdown"
                                                            name="customized_setting_group_id">
                                                            <option value=" "><?= $lang['all'] ?></option>
                                                            <?php foreach ($group_type_csutomized as $item) { ?>
                                                            <option value="<?= $item["id"] ?>"
                                                                <?= ($_GET['customized_setting_group_id'] ==  $item["id"]) ? 'selected' : '' ?>>
                                                                <?= $item['name'] ?>
                                                            </option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row">
                                                    <label for="form-1-1" class="col-md-4 control-label">
                                                        <?= $lang['agent_group_type_2'] ?>
                                                    </label>
                                                    <div class="col-md-8">
                                                        <select id="selectize-dropdown" name="cash_flow_group_id">
                                                            <option value=" "><?= $lang['all'] ?></option>
                                                            <?php foreach ($group_type_cash_flow as $item) { ?>
                                                            <option value="<?= $item["id"] ?>"
                                                                <?= ($_GET['cash_flow_group_id'] ==  $item["id"]) ? 'selected' : '' ?>>
                                                                <?= $item['name'] ?>
                                                            </option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row">
                                                    <label for="form-1-1" class="col-md-4 control-label">
                                                        <?= $lang['agent_group_type_4'] ?>
                                                    </label>
                                                    <div class="col-md-8">
                                                        <select id="selectize-dropdown" name="chat_group_id">
                                                            <option value=" "><?= $lang['all'] ?></option>
                                                            <?php foreach ($group_type_chat as $item) { ?>
                                                            <option value="<?= $item["id"] ?>"
                                                                <?= ($_GET['chat_group_id'] ==  $item["id"]) ? 'selected' : '' ?>>
                                                                <?= $item['name'] ?>
                                                            </option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <?php } ?>
                                            </div>
                                            <div style="clear:both;"></div>
                                        </div>
                                        <div class="form-group row">
                                            <label for="form-1-2" class="col-md-2 control-label"></label>
                                            <div class="col-md-10">
                                                <button class="btn btn-primary"><?= $lang['search'] ?></button>
                                                <input type="hidden" name="act" value="1" />
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
    var fuzzy_query = $("[name='fuzzy_query']").val();
    window.location = 'ajax.php?action=agent_account&username=' + username +
        '&email=' + email +
        '&status=' + status +
        '&fuzzy_query=' + fuzzy_query;
});
</script>