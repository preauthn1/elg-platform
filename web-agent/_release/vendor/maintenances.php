<?php
require_once '../_init.php';
require_once '../class/CreatePage.php';
/*
if (!isset($check_auth['vendor/maintenance'])) {
    echo $lang['nopermission'];
    exit;
}
// 取vendor資料
$filter = array();
$result = curlPost('api/dropdown/vendor', $filter);
if ($result['code'] == 1000) {
    $vendors = $result['data'];
}

*/
$filter = array();
$filter['start_time'] = ($_GET['start_date'] ? $_GET['start_date'] : date('Y-m-d 00:00', strtotime('-1day'))) . ':00';
$filter['end_time'] = ($_GET['end_date'] ? $_GET['end_date'] : date('Y-m-d 00:00')) . ':00';

$result = curlPost('api/vendor/maintenance', ['agent_id' => $agent_id]);
if ($result['code'] != 1000) {
    if ($result['code'] > 9997) {
        header("location:" . BASE_URL . "login.php");
    } else {
        echo $result['message'];
        exit();
        header('Location:' . $_SERVER['HTTP_REFERER']);
    }
    exit();
}

require_once '../_header.php';
require_once '../_menu.php';
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
                <h4><?= $lang['system_management'] ?> > 遊戲商維護</h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="row">
                                <div class="col-md-12">
                                    <form class="form-horizontal mrg-top-40 pdd-right-30">
                                        <div class="form-group row">
                                            <label for="form-1-2" class="col-md-1 control-label"><?= $lang['between'] ?></label>
                                            <div class="col-md-2">
                                                <input type="text" class="form-control date-picker" name="start_date"
                                                       value="<?= $_GET['start_date'] ? $_GET['start_date'] : date('Y-m-d 00:00') ?>" />
                                            </div>
                                            <label for="form-1-2"
                                                   class="col-md-1 control-label"><?= $lang['to'] ?></label>
                                            <div class="col-md-2">
                                                <input type="text" class="form-control date-picker" name="end_date"
                                                       value="<?= $_GET['end_date'] ? $_GET['end_date'] : date('Y-m-d 23:59') ?>" />
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <div class="col-md-12">
                                                <button class="btn btn-primary"><?= $lang['search'] ?></button>
                                                <button class="btn btn-success"><?= $lang['add'] ?></button>
                                                <input type="reset" class="btn btn-default clear" value="<?= $lang['clear'] ?>" />
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
                                        <th>vendor id</th>
                                        <th>type</th>
                                        <th>start time</th>
                                        <th>end time</th>
                                        <th>status</th>
                                        <th>created at</th>
                                        <th>update at</th>
                                        <th></th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php
                                    if (count($record['data']) > 0) {
                                        foreach ($record['data'] as $row) {
                                            ?>
                                            <tr>
                                                <td><?= $row['vendor_name'] ?></td>
                                                <td><?= $row['type'] ?></td>
                                                <td><?= $row['start_time'] ?></td>
                                                <td><?= $row['end_time'] ?></td>
                                                <td><?= $row['status'] ?></td>
                                                <td><?= $row['created_at'] ?></td>
                                                <td><?= $row['update_at'] ?></td>
                                                <td><?= $row['admin'] ?></td>
                                                <td>

                                                </td>
                                            </tr>
                                            <?php
                                        }
                                    }
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
                                <?php
                            }
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
require_once '../_bottom.php';
require_once '../_footer.php';
?>
<script>
    $(document).ready(function() {
        //清除查詢資料
        $(".clear").click(function(e) {
            if (e.preventDefault) {
                e.preventDefault();
            } else {
                window.event.returnValue == false;
            }
            window.location = 'member_search.php';
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
    });
</script>