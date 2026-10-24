<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';
if (!isset($check_auth['member/index'])) {
    echo $lang['nopermission'];
    exit;
}

//查詢條件的代理列表
$data = array();
$data['include_sub_account'] = 0;
$data['include_self'] = 1;
$data['page'] = 1;
$data['per_page'] = 10000;
$agents = curlPost('api/agent/list', $data);
$agents = $agents['data'];
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
                <h4><?= $lang['member_lower_management'] ?> > <?= $lang['member_account_management'] ?></h4>
            </div>

            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="row">
                                <div class="col-md-8">
                                    <h4 class="card-title"><?= $lang['search'] ?></h4>
                                    <form class="form-horizontal mrg-top-40 pdd-right-30">
                                        <div class="form-group row">
                                            <label for="form-1-1"
                                                class="col-md-2 control-label"><?= $lang['agent_account'] ?></label>
                                            <div class="col-md-4">
                                                <select class="selectize-class" name="agent_id">
                                                    <option value=""></option>
                                                    <?php
                                                    foreach ($agents as $row) {
                                                    ?>
                                                    <option value="<?= $row['id'] ?>"
                                                        <?= ($_GET['agent_id'] == $row['id']) ? 'selected' : '' ?>>
                                                        <?= $row['username'] ?></option>
                                                    <?php
                                                    }
                                                    ?>
                                                </select>
                                            </div>
                                            <label for="form-1-1"
                                                class="col-md-2 control-label"><?= $lang['username'] ?></label>
                                            <div class="col-md-4">
                                                <input type="text" class="form-control" name="username"
                                                    value="<?= $_GET['username'] ?>" />
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <label for="form-1-1"
                                                class="col-md-2 control-label"><?= $lang['email'] ?></label>
                                            <div class="col-md-4">
                                                <input type="text" class="form-control" name="email"
                                                    value="<?= $_GET['email'] ?>" />
                                            </div>
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
                                            <label for="form-1-2" class="col-md-2 control-label"><?= $lang['status_certification'] ?></label>
                                            <div class="col-md-4">
                                                <select id="selectize-dropdown" name="certification">
                                                    <option value=""><?= $lang['all'] ?></option>
                                                    <option value="1" <?= ($_GET['certification'] == '1') ? 'selected' : '' ?>>
                                                        <?= $lang['pass'] ?></option>
                                                    <option value="0" <?= ($_GET['certification'] == '0') ? 'selected' : '' ?>>
                                                        <?= $lang['not_certified'] ?></option>
                                                </select>
                                            </div>
                                            <div class="col-md-6">
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
<div class="modal fade" id="flow_limit">
    <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content" id="flow_limit_content">
        </div>
    </div>
</div>
<script>
$(document).ready(function() {
    $.blockUI();
    $.get('index_ajax.php' + $(location).attr('search'), function(data) {
        $("#result").html(data);
        $.unblockUI();
    });

    //修改狀態
    $(document).on('click', '.member_toggle_status', function(e) {
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
            'action': 'member_toggle_status',
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
});

//下載
$(document).on('click', '#excel', function(e) {
    var agent_id = $("[name='agent_id']").val();
    var username = $("[name='username']").val();
    var email = $("[name='email']").val();
    var status = $("[name='status']").val();
    var certification = $("[name='certification']").val();
    window.location = 'ajax.php?action=member_account&agent_id=' + agent_id + '&username=' + username + '&email=' + email + '&status=' + status + '&certification=' + certification;
});
</script>