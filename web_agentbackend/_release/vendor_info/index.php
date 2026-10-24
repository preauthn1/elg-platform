<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';
require_once '../class/CreatePage.php';

//確認權限
if (!isset($check_auth['vendor_info/index'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

//查詢條件的代理列表
$filter = array();
$filter['type'] = 1;
$vendors = curlPost('api/vendor/dropdownList', $filter);

$vendor_info_id = $_GET['vendor_info_id'];

if ($_GET['act'] == 1) {
    $filter = array();
    $filter['vendor_id'] = isset($_GET['vendor_id']) ? trim($_GET['vendor_id']) : 0;
    $filter['status'] = isset($_GET['status']) ? $_GET['status'] : '';
    $filter['vendor_info_id'] = isset($_GET['vendor_info_id']) ? trim($_GET['vendor_info_id']) : '';
    $filter['page'] = isset($_GET['page']) ? $_GET['page'] : 1;
    $filter['per_page'] = 20;

    $result = curlPost('api/vendor_info/list', $filter);
    if ($result['code'] != 1000) {
        header("location:" . BASE_URL . "login.php");
        exit();
    }
}
$page = new CreatePage();
?>
<div class="page-container">
    <?php
    require_once '../_top.php';
    require_once '../_theme.php';
    ?>
    <div class="main-content">
        <div class="container-fluid">
            <div class="page-title">
                <h4><?= $lang['menu_new_vendor'] ?> > <?= $lang['menu_vendor_info_management'] ?></h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="form-group row">

                                        <div>
                                            <button class="btn btn-primary btn-inverse btn-rounded" type="button"
                                                onclick="showSearchAgentVInfoModal()">
                                                查詢代理vendor_info
                                            </button>

                                            <a href="edit.php"
                                                class="btn btn-danger btn-inverse btn-rounded"><?= $lang['add_vendor_info'] ?>
                                            </a>

                                        </div>
                                    </div>
                                    <form class="form-horizontal mrg-top-40 pdd-right-30">
                                        <div class="form-group row">
                                            <label for="form-1-1" class="col-md-1 control-label">
                                                <?= $lang['label_vendor'] ?>
                                            </label>
                                            <div class="col-md-3">
                                                <select class="selectize-class" name="vendor_id">
                                                    <option value=" "><?= $lang['all'] ?></option>
                                                    <?php
                                                    foreach ($vendors['data'] as $row) {
                                                        ?>
                                                    <option value="<?= $row['id'] ?>"
                                                        <?= ($_GET['vendor_id'] == $row['id']) ? 'selected' : '' ?>>
                                                        <?= $row['name'] ?>
                                                    </option>
                                                    <?php
                                                    }
                                                    ?>
                                                </select>
                                            </div>
                                            <label for="form-1-1" class="col-md-1 control-label">
                                                <?= $lang['status'] ?>
                                            </label>
                                            <div class="col-md-3">
                                                <select id="selectize-dropdown" name="status">
                                                    <option value=""><?= $lang['all'] ?></option>
                                                    <option value="1" <?= ($_GET['status'] == '1') ? 'selected' : '' ?>>
                                                        <?= $lang['enable'] ?>
                                                    </option>
                                                    <option value="0" <?= ($_GET['status'] == '0') ? 'selected' : '' ?>>
                                                        <?= $lang['disable'] ?>
                                                    </option>
                                                </select>
                                            </div>

                                        </div>


                                        <div class="form-group row">

                                            <label for="form-1-1" class="col-md-1 control-label">
                                                ID
                                            </label>
                                            <div class="col-md-3">
                                                <input type="text" name="vendor_info_id" class="form-control"
                                                    value="<?= $vendor_info_id ?>">
                                            </div>

                                            <div class="col-md-4">

                                                <button class="btn btn-primary"><?= $lang['search'] ?>
                                                </button>

                                                <button class="btn" type="button"
                                                    onclick="clearForm()"><?= $lang['clear'] ?>
                                                </button>

                                                <input type="hidden" name="act" value="1" />
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
                                <table class="table table-lg table-hover">
                                    <thead>
                                        <tr>
                                            <th><?= $lang['name'] ?></th>
                                            <th><?= $lang['label_vendor'] ?></th>
                                            <th><?= $lang['currency_code'] ?></th>
                                            <th><?= $lang['deposit_vendor_info'] ?></th>
                                            <th><?= $lang['prepared_account'] ?></th>
                                            <th><?= $lang['account_prefix'] ?></th>
                                            <th><?= $lang['status'] ?></th>
                                            <th><?= $lang['features'] ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                    foreach ($result['data'] as $row) {
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="list-info mrg-top-10">
                                                    <div class="info">
                                                        <span class="title">
                                                            <?=$row['name']?>
                                                        </span>
                                                        <span class="sub-title">
                                                            <?= $lang['id'] ?>: <?=$row['id']?>
                                                        </span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="list-info mrg-top-10">
                                                    <div class="info">
                                                        <span class="title">
                                                            <?=$row['vendor_name']?>
                                                        </span>
                                                        <span class="sub-title">
                                                            vendor_id: <?=$row['vendor_id']?>
                                                        </span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="relative mrg-top-15">
                                                    <span class="pdd-left-20">
                                                        <?= $row['currency'] ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-5"
                                                    style="display: flex;align-items: center;justify-content: center;">
                                                    <button href="#" data-toggle="modal" data-target="#info"
                                                        data-info='<?= $row['info'] ?>' id="btn_info"
                                                        class="btn btn-info">
                                                        <?= $lang['detail'] ?>
                                                    </button>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span class="text-info">
                                                        <?= $row['prepared_account_number'] ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span>
                                                        <?= $row['account_prefix'] ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <div
                                                    class="mrg-top-15 <?= ($row['status'] != 1) ? 'text-danger' : '' ?>">
                                                    <?= ($row['status'] == 1) ? $lang['enable'] : (($row['status'] == 2) ? $lang['in_development'] : $lang['disable']) ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-10 text-center">
                                                    <div class="dropdown inline-block">
                                                        <button class="btn btn-default btn-sm dropdown-toggle"
                                                            type="button" data-toggle="dropdown">
                                                            <i class="ti-angle-down font-size-9"></i>
                                                        </button>
                                                        <ul class="dropdown-menu">
                                                            <li>
                                                                <a href="edit.php?id=<?= $row['id'] ?>">
                                                                    <?= $lang['edit'] ?>
                                                                </a>
                                                            </li>
                                                            <li>
                                                                <a href="#" class="vendor_toggle_status"
                                                                    id="<?= $row['id'] ?>"
                                                                    status="<?= ($row['status'] != 1) ? 1 : 0 ?>">
                                                                    <?= ($row['status'] != 1) ? $lang['enable'] : $lang['disable'] ?>
                                                                </a>
                                                            </li>
                                                        </ul>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php
                                    }
                                    ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php
                            if (count($result['data']) > 0) {
                                ?>
                            <div class="dataTables_info" id="dt-opt_info" role="status" aria-live="polite">
                                <?= $lang['total'] ?> <?= $result['pagination']['total'] ?> <?= $lang['count'] ?>
                            </div>
                            <div class="dataTables_paginate paging_simple_numbers" id="dt-opt_paginate">
                                <?= $page->createPager($result['pagination']['pages'], $result['pagination']['current'], $lang) ?>
                            </div>
                            <?php } ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="info">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content" id="info_list">
            </div>
        </div>
    </div>

    <div id="search_agent_vendor_info_modal" class="modal fade" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content" style="width:850px;">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <b>
                            查詢代理vendor_info
                        </b>
                    </h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body" style="padding:35px;">

                    <div>

                        <div class="row">

                            <div class="col-md-5">

                                <div style="padding-right:20px;">
                                    <input id="target_username" type="text" class="form-control" placeholder="請輸入代理帳號">
                                </div>

                            </div>

                            <div class="col-md-1">
                                <button type="button" class="btn btn-primary" onclick="searchAgentVInfoByUsername()">
                                    搜尋
                                </button>
                            </div>
                            <div class="col-md-6">
                            </div>
                        </div>


                    </div>

                    <div id="search_agent_vendor_info_modal_content">
                    </div>

                </div>

                <div class="modal-footer">

                </div>
            </div>
        </div>
    </div>






    <?php
    require_once '../_bottom.php';
    ?>
</div>
<?php
require_once '../_footer.php';
?>
<script>
$(document).ready(function() {
    //修改狀態
    $(document).on('click', '.vendor_toggle_status', function(e) {
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
            'action': 'vendor_toggle_status',
            'id': id,
            'status': status
        }, function(data) {
            $.unblockUI();
            if (data.code == '1000') {
                // window.location.reload();
                alert(data.data);
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

//換頁
$(document).on('click', '.paginate_button', function(e) {
    if (e.preventDefault) {
        e.preventDefault();
    } else {
        window.event.returnValue == false;
    }
    var page = $(this).attr('data-dt-idx');
    var is_disable = $(this).hasClass('disabled');
    if (!is_disable) {
        var result = $(location).attr('search').split('&page')[0]
        if (result == '') {
            window.location = $(location).attr('href') + '?page=' + page;
        } else {
            window.location = result + '&page=' + page;
        }
    }
});

//開啟審核視窗
$(document).on('click', '#btn_info', function(e) {
    //先清內容
    $("#info_list").html('');
    var data = $(this).attr('data-info');
    console.log(data);
    $.post("info_list.php", {
        'data': data,
    }, function(data) {
        $("#info_list").html(data);
    });
});


function clearForm() {
    location.href = "/vendor_info/index.php";
}

function showSearchAgentVInfoModal() {
    $("#search_agent_vendor_info_modal").modal("show");
}

function searchAgentVInfoByUsername() {

    $.blockUI();

    var target_username = $("#target_username").val();
    var url = 'agent_vendor_info_ajax.php?username=' + target_username;

    $.get(url, function(data) {
        $("#search_agent_vendor_info_modal_content").html(data);
        $.unblockUI();
    });


}
</script>