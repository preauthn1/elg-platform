<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';
require_once '../class/CreatePage.php';

//if (!isset($check_auth['transfer_limit/index'])) {
//    echo display_error_msg_html($lang['nopermission']);
//    exit;
//}

$filter = array();
$filter['type'] = isset($_GET['type']) ? $_GET['type'] : 0;
$filter['value'] = isset($_GET['value']) ? $_GET['value'] : "";
$filter['page'] = isset($_GET['page']) ? $_GET['page'] : 1;
$filter['per_page'] = 20;
$record = curlPost('api/system_maintenance/list', $filter);
if ($record['code'] >= 9997) {
    header("location:" . BASE_URL . "login.php");
    exit();
}
$maintenance_status = curlPost('api/has_maintenance', $filter);
if ($maintenance_status['code'] == 1000) {
    $has_maintenance = $maintenance_status['data'] == true ? 1 : 0;
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
                <h4><?= $lang['menu_system_maintenance'] ?>
                    > <?= $lang['menu_switch_and_whitelist'] ?></h4>
            </div>
            <div class="row">
                <div class="mrg-top-15">
                    <h3>維護開關:</h3>
                </div>
                <span>
                <div class="toggle-checkbox checkbox-inline toggle-sm mrg-top-15">
                    <input type="checkbox"
                           name="toggle_status" <?= $has_maintenance == 1 ? "checked" : "" ?>
                           id="toggle_backend_maintenance"
                           data-status="<?= $has_maintenance == 1 ? 0 : 1 ?>">
                    <label for="toggle_backend_maintenance"></label>
                </div>
                </span>
            </div>
            <div class="row">
                <button href="#" data-toggle="modal" data-target="#new" id="create"
                        class="btn btn-success"><?= $lang['add'] ?></button>
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="">
                                <form class="form-horizontal mrg-top-40 pdd-right-30">
                                    <div class="form-group row">
                                        <label for="form-1-1" class="col-md-2 control-label">
                                            <select class="selectize" name="type">
                                                <option value="0" <?= ($_GET['type'] == 0) ? 'selected' : '' ?>>
                                                    <?= $lang['all'] ?>
                                                </option>
                                                <option value="1" <?= ($_GET['type'] == 1) ? 'selected' : '' ?>>
                                                    IP
                                                </option>
                                                <option value="2" <?= ($_GET['type'] == 2) ? 'selected' : '' ?>>
                                                    <?= $lang['agent_account'] ?>
                                                </option>
                                            </select>
                                        </label>
                                        <div class="col-md-4">
                                            <input type="text" class="form-control" name="value"
                                                   id="value"
                                                   value='<?= $_GET['value'] ?>'/>
                                        </div>
                                        <div class="col-md-3">
                                            <button class="btn btn-primary"><?= $lang['search'] ?></button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <h4></h4>
                            <div class="table-overflow">
                                <table class="table table-lg table-hover">
                                    <thead>
                                    <tr>
                                        <th><?= $lang['num'] ?></th>
                                        <th><?= $lang['type'] ?></th>
                                        <th><?= $lang['whitelist'] ?></th>
                                        <th></th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php
                                    if (count($record['data']) > 0 && $record['code'] == 1000) {
                                        foreach ($record['data'] as $row) {
                                            ?>
                                            <tr>
                                                <td>
                                                    <div class="mrg-top-15">
                                                        <?= $row['id'] ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-15">
                                                        <span style="color:<?= $row['type'] == 1 ? "blue" : "green" ?>">
                                                        <?= $row['type'] == 1 ? "IP" : $lang['agent_account'] ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-15">
                                                        <?= $row['value'] ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-15">

                                                        <button onclick="deleteById(<?= $row['id'] ?>)"
                                                                class="btn btn-danger"><?= $lang['delete'] ?></button>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php
                                        }
                                    } else { ?>
                                        <tr>
                                            <td colspan="9" align='center'>
                                                <div class="mrg-top-15">
                                                    <span class="text-info"><b><?= $lang['no_record'] ?></b></span>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php }
                                    ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php
                            if (count($record['data']) > 0) {
                                ?>
                                <div class="dataTables_info" id="dt-opt_info" role="status" aria-live="polite">
                                    <?= $lang['total'] ?> <?= $record['pagination']['total'] ?> <?= $lang['count'] ?>
                                </div>
                                <div class="dataTables_paginate paging_simple_numbers" id="dt-opt_paginate">
                                    <?= $page->createPager($record['pagination']['pages'], $record['pagination']['current'], $lang) ?>
                                </div>
                            <?php } ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</div>

<div class="modal fade" id="image">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content" id="order_list">
        </div>
    </div>
</div>
<div class="modal fade" id="new">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content" id="add_list">
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
    $(document).ready(function () {
        $('.datetime-picker').daterangepicker({
            singleDatePicker: true,
            timePicker: true,
            timePicker24Hour: true,
            timePickerIncrement: 1,
            locale: {
                format: 'YYYY-MM-DD HH:mm'
            }
        });

        $('.selectize').selectize({
            create: false,
            dropdownParent: 'body'
        });

        //換頁
        $(document).on('click', '.paginate_button', function (e) {
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
        $(document).on('click', '#create', function (e) {
            //先清內容
            $("#add_list").html('');
            $.post("add_list.php", {}, function (data) {
                $("#add_list").html(data);
            });
        });

        $(document).on('change', 'input[id=toggle_backend_maintenance]', function () {
            var status = $(this).attr('data-status');
            console.log(status);
            $.post("<?= BASE_URL ?>ajax.php", {
                'action': 'toggle_backend_maintenance',
                'toggle_backend_maintenance': status
            }, function (data) {
                var data = JSON.parse(data);
                alert(data.message);
                if (data.code == 1000) {
                    window.location.reload();
                }
            });
        });
    });

    //開啟審核視窗
    function deleteById(id) {
        var id = id;
        $.post("<?= BASE_URL ?>ajax.php", {
            'action': 'delete_maintenance_whitelist',
            'id': id
        }, function (data) {
            var data = JSON.parse(data);
            if (data.code == '1000') {
                alert(data.message);
                location.reload();
            } else {
                if (data.message == '2002') {
                    alert(data.data);
                } else {
                    alert(data.message);
                }
            }
        });
    }
</script>