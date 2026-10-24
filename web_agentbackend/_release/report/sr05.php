<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';

//確認權限
if (!isset($check_auth['report/sr05'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

$start_date = $_GET['range'] ? substr($_GET['range'], 0, 10) : date('Y-m-d', strtotime('-7day'));
$end_date = $_GET['range'] ? substr($_GET['range'], 13, 10) : date('Y-m-d');
$data = array();
if ($_GET['type'] == 1) {
    $data['agent_username'] = $_GET['username'];
} else if ($_GET['type'] == 2) {
    $data['member_username'] = $_GET['username'];
} else if ($_GET['type'] == 3) {
    $data['member_nickname'] = $_GET['username'];
} else if ($_GET['type'] == 4) {
    $data['transaction_no'] = $_GET['username'];
}
$data['start_date'] = $start_date;
$data['end_date'] = $end_date;
$member = curlPost('api/report/sr05', $data);
if ($member['code'] >= 9997) {
    header("location:" . BASE_URL . "login.php");
    exit();
}
?>
<div class="page-container">
    <?php
    require_once '../_top.php';
    require_once '../_theme.php';
    ?>
    <div class="main-content">
        <div class="container-fluid">
            <div class="page-title">
                <h4><?= $lang['statistical_report']?> > <?= $lang['sr05'] ?></h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="row">
                                <div class="col-md-10">
                                    <form class="form-horizontal mrg-top-40 pdd-right-30">
                                        <div class="form-group row">
                                            <label for="form-1-1" class="col-md-2 control-label">
                                                <select class="selectize" name="type">
                                                    <option value="1" <?= ($_GET['type'] == 1) ? 'selected' : '' ?>>
                                                        <?= $lang['agent_account'] ?>
                                                    </option>
                                                    <option value="2" <?= ($_GET['type'] == 2) ? 'selected' : '' ?>>
                                                        <?= $lang['member_account'] ?>
                                                    </option>
                                                    <option value="3" <?= ($_GET['type'] == 3) ? 'selected' : '' ?>>
                                                        <?= $lang['member_nickname'] ?>
                                                    </option>
                                                    <option value="4" <?= ($_GET['type'] == 4) ? 'selected' : '' ?>>
                                                        <?= $lang['ordernum'] ?>
                                                    </option>
                                                </select>
                                            </label>
                                            <div class="col-md-3">
                                                <input type="text" class="form-control" name="username"
                                                    value='<?= $_GET['username'] ?>' />
                                            </div>
                                            <label for="form-1-2" class="col-md-2 control-label">
                                                <?= $lang['between'] ?>
                                            </label>
                                            <div class="col-md-4">
                                                <input type="text" id="range" class="form-control" name="range"
                                                    placeholder="Date range picker" />
                                            </div>
                                             <div class="col-md-1">
                                                <button class="btn btn-primary">
                                                    <?= $lang['search'] ?>
                                                </button>
                                            </div>
                                        </div>
                                    </form>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- 表格 Member-->
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <h4><?= $lang['member_list'] ?></h4>
                            <button type="button" class="btn btn-success" id="excel" style="float:right">Excel</button>
                            <div class="table-overflow">
                                <table class="table table-lg table-hover">
                                    <thead>
                                        <tr>
                                            <th><?= $lang['num'] ?></th>
                                            <th><?= $lang['ordernum'] ?></th>
                                            <th><?= $lang['date'] ?></th>
                                            <th><?= $lang['agent_account'] ?></th>
                                            <th><?= $lang['status'] ?></th>
                                            <th><?= $lang['member_account'] ?></th>
                                            <th><?= $lang['member_nickname'] ?></th>
                                            <th class="text-right"><?= $lang['change_point'] ?></th>
                                            <th class="text-right"><?= $lang['moment_point'] ?></th>
                                            <th><?= $lang['execution_account'] ?></th>
                                            <th><?= $lang['order_status'] ?></th>
                                            <th><?= $lang['memo'] ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        if (count($member['data']) > 0) {
                                            foreach ($member['data'] as $row) {
                                                ?>
                                        <tr>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span>
                                                        <?= $row['id'] ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span>
                                                        <?= $row['transaction_no'] ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span>
                                                        <?= $row['created_at'] ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span class="text-info">
                                                        <b><?= $row['agent_username'] ?></b>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span>
                                                        <?= ($row['type'] == 1 ? $lang['transferin'] : $lang['transferout']) ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span class="text-info"><b><?= $row['member_username'] ?></b></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span>
                                                        <?= $row['member_nickname'] ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15 text-right">
                                                    <span>
                                                        <?= $row['point'] ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15 text-right">
                                                    <span>
                                                        <?= $row['balance'] ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span>
                                                        <?= $row['operator_username'] ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span>
                                                        <?= ($row['status'] == 1) ? $lang['success'] : $lang['failure'] ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span>
                                                        <?= $row['remark'] ?>
                                                    </span>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php
                                                }
                                            } else { ?>
                                        <tr>
                                            <td colspan="12" align='center'>
                                                <div class="mrg-top-15">
                                                    <span class="text-info">
                                                        <b><?= $lang['no_record'] ?></b>
                                                    </span>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php }
                                        ?>
                                    </tbody>
                                </table>
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
$(document).ready(function() {
    //變更日期格式
    $('#range').daterangepicker({
        startDate: '<?= $start_date ?>',
        endDate: '<?= $end_date ?>',
        locale: {
            format: 'YYYY-MM-DD'
        }
    });

    $('.selectize').selectize({
        create: false,
        dropdownParent: 'body'
    });

    //下載
    $("#excel").on('click', function(e) {
        var type = $("[name='type']").val();
        var username = $("[name='username']").val();
        var range = $("[name='range']").val();
        window.location = 'ajax.php?action=sr05' +
            '&type=' + type +
            '&username=' + username +
            '&range=' + range;
    });
});
</script>