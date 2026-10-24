<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';
require_once '../class/CreatePage.php';
require_once '../commonFunction.php';

if (!isset($check_auth['transfer_limit/index'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

$filter = array();
$filter['agent_group_name'] = isset($_GET['agent_group_name']) ? $_GET['agent_group_name'] : "";
$filter['page'] = isset($_GET['page']) ? $_GET['page'] : 1;
$filter['per_page'] = 20;
$record = curlPost('api/transfer_limit/list', $filter);
if ($record['code'] >= 9997) {
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
                <h4><?= $lang['menu_agent_transfer_limit'] ?>
                    > <?= $lang['menu_agent_transfer_limit_management'] ?></h4>
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
                                        <label for="form-1-2"
                                               class="col-md-2 control-label"><?= $lang['group_name'] ?>
                                        </label>
                                        <div class="col-md-4">
                                            <input type="text" class="form-control" name="agent_group_name"
                                                   id="member_username"
                                                   value='<?= $_GET['agent_group_name'] ?>'/>
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
                                        <th><?= $lang['group_name'] ?></th>
                                        <th><?= $lang['limit_amount'] ?></th>
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
                                                        <?= $row['name'] ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-15">
                                                        <?php if ($_SESSION['toggle_thousands'] == 0) {
                                                            amount_thousands_off($row['limit']);
                                                        } else {
                                                            amount_thousands_on($row['limit']);
                                                        }?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-15">
                                                        <a href="edit.php?id=<?= $row['id'] ?>"
                                                           class="btn btn-warning"><?= $lang['edit'] ?></a>
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

        //下載
        $("#excel").on('click', function (e) {
            var member_username = $("#member_username").val();
            var start_time = $("#start_time").val();
            var end_time = $("#end_time").val();

            $.blockUI();
            window.location = 'ajax.php?action=sr14' +
                '&member_username=' + member_username +
                '&start_time=' + start_time +
                '&end_time=' + end_time;
            setTimeout($.unblockUI, 5000);
        });


        //開啟審核視窗
        $(document).on('click', '#review', function (e) {
            //先清內容
            $("#order_list").html('');
            var id = $(this).attr('data-uid');
            $.post("limit_list.php", {
                'id': id,
            }, function (data) {
                $("#order_list").html(data);
            });
        });

        //開啟審核視窗
        $(document).on('click', '#create', function (e) {
            //先清內容
            $("#add_list").html('');
            $.post("add_list.php", {}, function (data) {
                console.log(data);
                $("#add_list").html(data);
            });
        });
    });
</script>