<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';
require_once '../class/CreatePage.php';
require_once '../commonFunction.php';

//if (!isset($check_auth['transfer_limit/index'])) {
//    echo display_error_msg_html($lang['nopermission']);
//    exit;
//}

$data = array();
$data['type'] = isset($_GET['type']) ? $_GET['type'] : 0;
$data['page'] = isset($_GET['page']) ? $_GET['page'] : 1;
$data['per_page'] = 20;
$result = curlPost('api/announcement/list', $data);
if ($result['code'] >= 9997) {
    header("location:" . BASE_URL . "login.php");
    exit();
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
                <h4><?= $lang['menu_system_management'] ?>
                    > <?= $lang['menu_backend_announcement'] ?></h4>
            </div>
            <div class="row">
                <a href="edit.php" class="btn btn-success"><?= $lang['add'] ?></a>
                <div class="col-md-12">

                    <div class="card">
                        <div class="card-block">
                            <div class="">
                                <form class="form-horizontal mrg-top-40 pdd-right-30">
                                    <div class="form-group row">
                                        <label for="form-1-2"
                                               class="col-md-2 control-label"><?= $lang['type'] ?>
                                        </label>
                                        <div class="col-md-4">
                                            <select class="form-control" data-live-search="true"
                                                    name="type">
                                                <option value="0"><?= $lang['all'] ?></option>
                                                <option value="1" <?= ($_GET['type'] == '1') ? 'selected' : '' ?>>
                                                    <?= $lang['general_announcement'] ?>
                                                </option>
                                                <option value="2" <?= ($_GET['type'] == '2') ? 'selected' : '' ?>>
                                                    <?= $lang['vendor_announcement'] ?>
                                                </option>
                                                <option value="3" <?= ($_GET['type'] == '3') ? 'selected' : '' ?>>
                                                    <?= $lang['emergency_announcement'] ?>
                                                </option>
                                                <option value="4" <?= ($_GET['type'] == '4') ? 'selected' : '' ?>>
                                                    <?= $lang['important_announcement'] ?>
                                                </option>
                                            </select>
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
                                        <th><?= $lang['content'] ?></th>
                                        <th></th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php
                                    if (count($result['data']) > 0 && $result['code'] == 1000) {
                                        foreach ($result['data'] as $row) {
                                            ?>
                                            <tr>
                                                <td>
                                                    <div class="mrg-top-15">
                                                        <?= $row['id'] ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-10">
                                                        <?php
                                                        switch ($row['type']) {
                                                            case 1 :
                                                                echo $lang['general_announcement'];
                                                                break;
                                                            case 2:
                                                                echo $lang['vendor_announcement'];
                                                                break;
                                                            case 3:
                                                                echo $lang['emergency_announcement'];
                                                                break;
                                                            case 4:
                                                                echo $lang['important_announcement'];
                                                                break;
                                                            default:
                                                                echo $row['type'];
                                                                break;
                                                        }

                                                        ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-15">
                                                        <p class="ellipsis"><?= $row['content'] ?></p>
                                                    </div>
                                                </td>
                                                <td align="center">
                                                    <div class="mrg-top-15">
                                                        <div class="dropdown inline-block">
                                                            <button class="btn btn-default btn-sm dropdown-toggle"
                                                                    type="button"
                                                                    data-toggle="dropdown">
                                                                <i class="ti-angle-down font-size-9"></i>
                                                            </button>
                                                            <ul class="dropdown-menu">
                                                                <li>
                                                                    <a href="edit.php?id=<?= $row['id'] ?>"><?= $lang['edit'] ?></a>
                                                                </li>
                                                                <li class="divider"></li>
                                                                <li>
                                                                    <a href="#" class="delete_announcement"
                                                                       id="<?= $row['id'] ?>">
                                                                        <?= $lang['delete'] ?>
                                                                    </a>
                                                                </li>

                                                            </ul>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php
                                        }
                                    } else { ?>
                                        <tr>
                                            <td colspan="4" align='center'>
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

        //刪除公告
        $(document).on('click', '.delete_announcement', function (e) {
            if (e.preventDefault) {
                e.preventDefault();
            } else {
                window.event.returnValue == false;
            }
            var id = $(this).attr('id');
            $.blockUI({
                baseZ: 9000,
                overlayCSS: {
                    backgroundColor: '#444444'
                }
            });
            $.post("<?= BASE_URL ?>ajax.php", {
                'action': 'delete_announcement',
                'id': id
            }, function (data) {
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
    })
</script>
<style>
    .table {
        width: 100%;
        height: 100%;
        table-layout: fixed;
    }

    .ellipsis {
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
    }
</style>