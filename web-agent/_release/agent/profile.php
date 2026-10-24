<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';
//確認權限
if (!isset($check_auth['agent/edit'])) {
    echo $lang['nopermission'];
    exit;
}
if ($_GET['id'] != '') {
    // 取個人資料
    $data = array();
    $data['id'] = $_GET['id'];
    $result = curlPost('api/agent/view', $data);
}
if ($result['code'] != 1000) {
    if ($result['code'] > 9997) {
        header("location:" . BASE_URL . "login.php");
    } else {
        header('Location:' . $_SERVER['HTTP_REFERER']);
    }
    exit();
}
$agent = $result['data'];
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
                <h4><?=$lang['agnet_management']?> > <?=$lang['agent_account_management']?> > <?=$lang['detailinfo_user']?></h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="widget-profile-1 card">
                                <div class="profile border bottom">
                                    <!--<img class="mrg-top-30" src="../assets/images/others/img-10.jpg" alt="">-->
                                    <h4 class="mrg-top-20 no-mrg-btm text-semibold"><?= $agent['nickname'] ?></h4>
                                    <p><?= $agent['username'] ?></p>
                                </div>
                                <div class="pdd-horizon-30 pdd-vertical-20" style="text-align:center">
                                    <a href="javascript:history.back()"
                                        class="btn btn-success btn-inverse btn-rounded"><?=$lang['btn_back']?></a>
                                    <a href="edit.php?id=<?= $agent['id'] ?>"
                                        class="btn btn-danger btn-inverse btn-rounded"><?=$lang['detailinfo_edit']?></a>
                                </div>
                            </div>
                            <div class="card">
                                <div class="card-heading border bottom">
                                    <h4 class="card-title"><?=$lang['features']?></h4>
                                </div>
                                <div class="card-block">
                                    <div class="row">
                                        <div class="col-md-10">
                                            <ul class="list-unstyled list-info">
                                                <li>
                                                    <span class="thumb-img pdd-top-10">
                                                        <i class="ti-user font-size-30"></i>
                                                    </span>
                                                    <div class="info">
                                                        <b class="text-dark font-size-16"><?=$lang['enable_status']?> (<?=$lang['user_block']?>)</b>
                                                        <p><?=$lang['notallow_agent_control']?></p>
                                                    </div>
                                                </li>
                                            </ul>
                                        </div>
                                        <div class="col-md-2 text-right">
                                            <div
                                                class="toggle-checkbox toggle-success checkbox-inline toggle-sm mrg-top-15">
                                                <input type="checkbox" id="status"
                                                    <?= ($agent['status'] == 1) ? 'checked' : '' ?>>
                                                <label for="status"></label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-block">
                                    <div class="row">
                                        <div class="col-md-10">
                                            <ul class="list-unstyled list-info">
                                                <li>
                                                    <span class="thumb-img pdd-top-10">
                                                        <i class="ti-user font-size-30"></i>
                                                    </span>
                                                    <div class="info">
                                                        <b class="text-dark font-size-16"><?=$lang['allow_member_register']?></b>
                                                        <p><?=$lang['notallow_member_register']?></p>
                                                    </div>
                                                </li>
                                            </ul>
                                        </div>
                                        <div class="col-md-2 text-right">
                                            <div
                                                class="toggle-checkbox toggle-success checkbox-inline toggle-sm mrg-top-15">
                                                <input type="checkbox" id="register"
                                                    <?= ($agent['frontend_register'] == 1) ? 'checked' : '' ?>>
                                                <label for="register"></label>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            </div>
                            <div id="chart"></div>
                        </div>
                        <div class="col-md-8">
                            <div class="card">
                                <div class="card-heading border bottom">
                                    <h4 class="card-title"><?=$lang['basic_information']?></h4>
                                </div>
                                <div class="card-block">
                                    <div class="row">
                                        <div class="col-md-3">
                                            <p class="mrg-top-10 text-dark"> <b><?=$lang['name']?></b></p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="mrg-top-10"><?= $agent['nickname'] ?></p>
                                        </div>
                                    </div>
                                    <hr>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <p class="mrg-top-10 text-dark"> <b><?=$lang['username']?></b></p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="mrg-top-10"><?= $agent['username'] ?></p>
                                        </div>
                                    </div>
                                    <hr>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <p class="mrg-top-10 text-dark"> <b><?=$lang['email']?></b></p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="mrg-top-10"><?= $agent['email'] ?></p>
                                        </div>
                                    </div>
                                    <hr>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <p class="mrg-top-10 text-dark"> <b><?=$lang['country']?>/<?=$lang['city']?></b></p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="mrg-top-10">
                                                <?= $_SESSION['country'][$agent['country_id']] . ' / ' . $agent['city'] ?>
                                            </p>
                                        </div>
                                    </div>
                                    <hr>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <p class="mrg-top-10 text-dark"> <b><?=$lang['region']?> / <?=$lang['postalcode']?></b></p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="mrg-top-10"><?= $agent['city'] . ' / ' . $agent['zip_code'] ?></p>
                                        </div>
                                    </div>
                                    <hr>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <p class="mrg-top-10 text-dark"> <b><?=$lang['address']?></b></p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="mrg-top-10">
                                                <?= $agent['city'] . $agent['district'] . $agent['addrress'] ?></p>
                                        </div>
                                    </div>
                                    <hr>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <p class="mrg-top-10 text-dark"> <b><?=$lang['tel']?></b></p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="mrg-top-10"><?= $agent['mobile'] ?></p>
                                        </div>
                                    </div>
                                    <hr>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <p class="mrg-top-10 text-dark"> <b><?=$lang['status']?></b></p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="mrg-top-10">
                                                <span
                                                    class="status <?= ($agent['status'] == 1) ? 'online' : 'offline' ?> mrg-top-10"></span>
                                                <span
                                                    class="mrg-left-10"><?= ($agent['status'] == 1) ? $lang['enable'] : $lang['disable'] ?></span>
                                            </p>
                                        </div>
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
$(document).ready(function() {
    var id = '<?= $_GET["id"] ?>';
    //代理/會員圖表
    $.post("chart.php", {
        'id': id
    }, function(data) {
        $("#chart").html(data);
    });
    //更改營運商狀態
    $("#status").change(function(e) {
        if (e.preventDefault) {
            e.preventDefault();
        } else {
            window.event.returnValue == false;
        }
        var status = $(this).is(":checked");
        if (status) {
            $(this).prop("checked", true);
            status = 1;
        } else {
            $(this).removeAttr("checked");
            status = 0;
        }
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
                //window.location.reload();
            } else {
                if (data.code == '2002') {
                    alert(data.data);
                } else {
                    alert(data.message);
                }
            }
        }, 'json');
    });

    //更改營運商前台註冊狀態
    $("#register").change(function(e) {
        if (e.preventDefault) {
            e.preventDefault();
        } else {
            window.event.returnValue == false;
        }
        var status = $(this).is(":checked");
        if (status) {
            $(this).prop("checked", true);
            status = 1;
        } else {
            $(this).removeAttr("checked");
            status = 0;
        }
        $.blockUI({
            baseZ: 9000,
            overlayCSS: {
                backgroundColor: '#444444'
            }
        })
        $.post("<?= BASE_URL ?>ajax.php", {
            'action': 'agent_toggle_register',
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

});
</script>