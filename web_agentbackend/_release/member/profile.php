<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';
if (!isset($check_auth['member/index'])) {
    echo $lang['nopermission'];
    exit;
}
$is_show_toggle_status = check_permission_menuCode('member_disabled_permissiom');

//會員資料
if ($_GET['id'] != '') {
    // 取個人資料
    $data = array();
    $data['id'] = $_GET['id'];
    $result = curlPost('api/member/view', $data);
}
if ($result['code'] != 1000) {
    if ($result['code'] > 9997) {
        header("location:" . BASE_URL . "login.php");
    } else {
        header('Location:' . $_SERVER['HTTP_REFERER']);
    }
    exit();
} else {
    $member = $result['data'];
}

//營運商列表
$vendors = curlPost('api/vendor/list', $data);
if ($result['code'] != 1000) {
    if ($result['code'] > 9997) {
        header("location:" . BASE_URL . "login.php");
    } else {
        header('Location:' . $_SERVER['HTTP_REFERER']);
    }
    exit();
} else {
    $vendors = $vendors['data'];
}

// 驗證列表
$data = array();
$data['member_id'] = $_GET['id'];
$result = curlPost('api/memberValidation/list', $data);
if ($result['code'] != 1000 && $result['code'] != 1045) { // 不需身份驗證
    if ($result['code'] > 9997) {
        header("location:" . BASE_URL . "login.php");
    } else {
        header('Location:' . $_SERVER['HTTP_REFERER']);
    }
    exit();
}
$validate = $result['data'];

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
                <h4><?= $lang['member_lower_management'] ?> > <?= $lang['member_account_management'] ?> >
                    <?= $lang['detailinfo_user'] ?></h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="widget-profile-1 card">
                                <div class="profile border bottom">
                                    <!--<img class="mrg-top-30" src="../assets/images/others/img-10.jpg" alt="">-->
                                    <h4 class="mrg-top-20 no-mrg-btm text-semibold">
                                        <?= $member['username'] ?>
                                    </h4>
                                    <p><?= $member['first_name'] . ' ' . $member['last_name'] ?></p>
                                </div>
                                <div class="pdd-horizon-30 pdd-vertical-20" style="text-align:center">
                                    <a href="javascript:history.back()"
                                        class="btn btn-success btn-inverse btn-rounded"><?= $lang['btn_back'] ?></a>
                                    <?php if (isset($check_auth['member/edit'])) { ?>
                                    <a href="edit.php?id=<?= $member['id'] ?>"
                                        class="btn btn-danger btn-inverse btn-rounded"><?= $lang['detailinfo_edit'] ?></a>
                                    <?php } ?>
                                </div>
                            </div>
                            <div class="card">
                                <div class="card-heading border bottom">
                                    <h4 class="card-title"><?= $lang['vendor_information'] ?></h4>
                                </div>
                                <div class="card-block">
                                    <div class="row">
                                        <div class="col-md-4">
                                            <select id="selectize-dropdown" class="vendor">
                                                <option value=""><?= $lang['choose_vendors'] ?></option>
                                                <?php foreach ($vendors as $vendor) { ?>
                                                <option value="<?= $vendor['id'] ?>"> <?= $vendor['name'] ?></option>
                                                <?php } ?>
                                            </select>
                                        </div>
                                        <div class="col-md-8">
                                            <p class="mrg-top-10" id='vendor_username'></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div id="deposit"></div><!-- 儲值管道 -->
                            <div id="transaction_in"></div><!-- 轉點遊戲 TOP 10(轉入)-->
                            <div id="transaction_out"></div><!-- 遊戲轉點 TOP 10(轉出) -->
                        </div>
                        <div class="col-md-8">
                            <div class="card">
                                <div class="card-heading border bottom">
                                    <h4 class="card-title"><?= $lang['basic_information'] ?></h4>
                                </div>
                                <div class="card-block">
                                    <div class="row">
                                        <div class="col-md-3">
                                            <p class="mrg-top-10 text-dark"> <b><?= $lang['username'] ?></b></p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="mrg-top-10"><?= $member['username'] ?></p>
                                        </div>
                                    </div>
                                    <hr>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <p class="mrg-top-10 text-dark"> <b><?= $lang['nickname'] ?></b></p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="mrg-top-10"><?= $member['nickname'] ?></p>
                                        </div>
                                    </div>
                                    <hr>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <p class="mrg-top-10 text-dark"> <b><?= $lang['real_name'] ?></b></p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="mrg-top-10">
                                                <?= $member['first_name'] . ' ' . $member['last_name'] ?></p>
                                        </div>
                                    </div>
                                    <hr>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <p class="mrg-top-10 text-dark"> <b><?= $lang['email'] ?></b></p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="mrg-top-10"><?= $member['email'] ?></p>
                                        </div>
                                    </div>
                                    <hr>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <p class="mrg-top-10 text-dark"> <b><?= $lang['birthday'] ?></b></p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="mrg-top-10"><?= $member['birthday'] ?></p>
                                        </div>
                                    </div>
                                    <hr>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <p class="mrg-top-10 text-dark">
                                                <b><?= $lang['country'] ?>/<?= $lang['city'] ?></b></p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="mrg-top-10">
                                                <?= $_SESSION['country'][$member['country_id']] . ' / ' . $member['city'] ?>
                                            </p>
                                        </div>
                                    </div>
                                    <hr>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <p class="mrg-top-10 text-dark"> <b><?= $lang['region'] ?> /
                                                    <?= $lang['postalcode'] ?></b></p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="mrg-top-10"><?= $member['city'] . ' / ' . $member['zip_code'] ?>
                                            </p>
                                        </div>
                                    </div>
                                    <hr>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <p class="mrg-top-10 text-dark"> <b><?= $lang['address'] ?></b></p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="mrg-top-10">
                                                <?= $member['city'] . $member['district'] . $member['addrress'] ?></p>
                                        </div>
                                    </div>
                                    <hr>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <p class="mrg-top-10 text-dark"> <b><?= $lang['tel'] ?></b></p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="mrg-top-10"><?= $member['mobile'] ?></p>
                                        </div>
                                    </div>
                                    <hr>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <p class="mrg-top-10 text-dark"> <b><?= $lang['status'] ?></b></p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="mrg-top-10">
                                                <span
                                                    class="status <?= ($member['status'] == 1) ? 'online' : 'offline' ?> mrg-top-10"></span>
                                                <span
                                                    class="mrg-left-10"><?= ($member['status'] == 1) ? $lang['enable'] : $lang['disable'] ?></span>
                                            </p>
                                        </div>
                                    </div>
                                    <?php
                                    if (is_array($validate)) {
                                    ?>
                                    <hr>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <p class="mrg-top-10 text-dark">
                                                <b><?= $lang['member_personal_certification'] ?></b>
                                            </p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="mrg-top-10">
                                                <span
                                                    class="status <?= ($member['is_validate'] == 1) ? 'online' : 'offline' ?> mrg-top-10"></span>
                                                <span
                                                    class="mrg-left-10"><?= ($member['is_validate'] == 1) ? $lang['verified'] : $lang['not_certified'] . '(' . $lang['unverified_withdrawal'] . ')' ?></span>
                                            </p>
                                        </div>
                                    </div>
                                    <?php
                                    }
                                    ?>
                                </div>
                            </div>

                            <div class="card">
                                <div class="card-heading border bottom">
                                    <h4 class="card-title"><?= $lang['features'] ?></h4>
                                </div>
                                <div class="card-block">
                                    <?php if($is_show_toggle_status) { ?>
                                    <div class="row">
                                        <div class="col-md-10">
                                            <ul class="list-unstyled list-info">
                                                <li>
                                                    <span class="thumb-img pdd-top-10">
                                                        <i class="ti-user font-size-30"></i>
                                                    </span>
                                                    <div class="info">
                                                        <b class="text-dark font-size-16"><?= $lang['enable_status'] ?>
                                                            (<?= $lang['user_block'] ?>)</b>
                                                        <p><?= $lang['notallow_block_member_login'] ?></p>
                                                    </div>
                                                </li>
                                            </ul>
                                        </div>
                                        <div class="col-md-2 text-right">
                                            <div class="toggle-checkbox toggle-success checkbox-inline toggle-sm mrg-top-15">
                                                <input type="checkbox" id="status"
                                                    <?= ($member['status'] == 1) ? 'checked' : '' ?>>
                                                <label for="status"></label>
                                            </div>
                                        </div>
                                    </div>
                                    <?php } ?>
                                    <?php
                                    if (!is_array($validate)) {
                                    ?>
                                    <div class="row">
                                        <div class="col-md-10">
                                            <ul class="list-unstyled list-info">
                                                <li>
                                                    <span class="thumb-img pdd-top-10">
                                                        <i class="ti-user font-size-30"></i>
                                                    </span>
                                                    <div class="info">
                                                        <b
                                                            class="text-dark font-size-16"><?= $lang['status_certification'] ?></b>
                                                        <p><?= $lang['unverified_withdrawal'] ?></p>
                                                    </div>
                                                </li>
                                            </ul>
                                        </div>
                                        <div class="col-md-2 text-right">
                                            <div
                                                class="toggle-checkbox toggle-success checkbox-inline toggle-sm mrg-top-15">
                                                <input type="checkbox" id="is_validate"
                                                    <?= ($member['is_validate'] == 1) ? 'checked' : '' ?>>
                                                <label for="is_validate"></label>
                                            </div>
                                        </div>
                                    </div>
                                    <?php
                                    }
                                    ?>
                                </div>
                            </div>
                            <?php
                            if (is_array($validate)) {
                            ?>
                            <div class="card">
                                <div class="card-heading border bottom">
                                    <h4 class="card-title"><?= $lang['verify_review_data'] ?></h4>
                                </div>
                                <div class="card-block">
                                    <div class="table-overflow">
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th><?= $lang['type'] ?></th>
                                                    <th><?= $lang['picture'] ?></th>
                                                    <th><?= $lang['status'] ?></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                    foreach ($validate as $row) {
                                                    ?>
                                                <tr>
                                                    <td><?= $row['name'] ?></td>
                                                    <td>
                                                        <?php
                                                                if ($row['image'] != '') {
                                                                ?>
                                                        <img src="<?= $row['image'] ?>" alt="">
                                                        <?php
                                                                } else {
                                                                    echo $lang['not_uploaded'];
                                                                }
                                                                ?>
                                                    </td>
                                                    <td>
                                                        <?php
                                                                if ($row['status'] == '0') {
                                                                ?>
                                                        <a href="#" data-toggle="modal" data-target="#validate"
                                                            data-uid="<?= $row['id'] ?>"
                                                            class="validate"><?= $lang['pending'] ?></a>
                                                        <?php
                                                                } else if ($row['status'] == '1') {
                                                                    echo $lang['passed'];
                                                                } else if ($row['status'] == '2') {
                                                                    echo $lang['rejected'] . '，' . $lang['reason'] . '：' . $row['remark'];
                                                                } else {
                                                                    echo $lang['not_uploaded'];
                                                                }
                                                                ?>
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
                            <?php
                            }
                            ?>
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
<div class="modal fade" id="validate">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content" id="validate_content">
        </div>
    </div>
</div>
<script>
$(document).ready(function() {
    var id = "<?= $_GET['id'] ?>";
    //圖表
    $.post("deposit.php", {
        'id': id
    }, function(data) {
        $("#deposit").html(data);
    });
    $.post("transaction.php", {
        'id': id,
        'type': 1
    }, function(data) {
        $("#transaction_in").html(data);
    });
    $.post("transaction.php", {
        'id': id,
        'type': 2
    }, function(data) {
        $("#transaction_out").html(data);
    });

    //更改會員狀態
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

    //更改會員認證狀態
    $("#is_validate").change(function(e) {
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
            'action': 'member_toggle_validate',
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


    //查詢各遊戲商會員帳號
    $(".vendor").change(function(e) {
        if (e.preventDefault) {
            e.preventDefault();
        } else {
            window.event.returnValue == false;
        }
        var vendor_id = $(this).val();
        var member_id = "<?= $_GET['id'] ?>";
        $.blockUI({
            baseZ: 9000,
            overlayCSS: {
                backgroundColor: '#444444'
            }
        })
        $.post("<?= BASE_URL ?>ajax.php", {
            'action': 'get_vendor_username',
            'vendor_id': vendor_id,
            'member_id': member_id
        }, function(data) {
            $.unblockUI();
            if (data.code == '1000') {
                $('#vendor_username').html("<?= $lang['username'] ?>:" + data.data.username);
            } else {
                if (data.code == '2002') {
                    $('#vendor_username').html(data.data);
                    // alert(data.data);
                } else {
                    $('#vendor_username').html(data.message);
                    // alert(data.message);
                }
            }
        }, 'json');
    });


    //開啟認證審核框
    $('.validate').on('click', function(e) {
        var id = $(this).attr('data-uid');
        $.post("validate.php", {
            'id': id
        }, function(data) {
            $("#validate_content").html(data);

        });
    })


});
</script>