<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';

if (!isset($check_auth['point_barrel/performance_query'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

if ($_GET['act'] == 1) {
    $filter = array();
    $filter['start_time'] = ($_GET['start_date']
            ? $_GET['start_date']
            : date('Y-m-d', strtotime('-1day'))) . ' 16:00:00';
    $filter['end_time'] = ($_GET['end_date']
            ? $_GET['end_date']
            : date('Y-m-d', strtotime('-1day'))) . ' 16:00:00';
    $filter['agent_username'] = isset($_GET['agent_username']) ? $_GET['agent_username'] : '';
    $record = curlPost('api/point_barrel/performance', $filter);
    if ($record['code'] >= 9997) {
        header("location:" . BASE_URL . "login.php");
        exit();
    }
    $summary = $record['summary'];
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
                <h4><?= $lang['point_barrel_management'] ?> > <?= $lang['ps_performance_query'] ?></h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <h4 class="card-title"><?= $lang['search'] ?></h4>
                            <form class="form-horizontal mrg-top-40 pdd-right-30">
                                <div class="form-group row">
                                    <label for="form-1-2" class="col-md-1 control-label">
                                        <?= $lang['between'] ?>
                                    </label>
                                    <div class="col-md-2">
                                        <input type="text" class="form-control date-picker"
                                               name="start_date"
                                               value="<?= $_GET['start_date'] ? $_GET['start_date'] : date('Y-m-d', strtotime('-1day')) ?>" />
                                    </div>
                                    <label for="form-1-2" class="col-md-1 control-label">
                                        <?= $lang['to'] ?>
                                    </label>
                                    <div class="col-md-2">
                                        <input type="text" class="form-control date-picker"
                                               name="end_date"
                                               value="<?= $_GET['end_date'] ? $_GET['end_date'] : date('Y-m-d', strtotime('-1day')) ?>" />
                                    </div>
                                    <label for="form-1-2" class="col-md-1 control-label">
                                        <?= $lang['agent_account'] ?>
                                    </label>
                                    <div class="col-md-3">
                                        <input type="text" class="form-control" name="agent_username"
                                               value="<?= $_GET['agent_username'] ?>" />
                                    </div>
                                    <div class="col-md-2">
                                        <input type="hidden" name="act" value="1" />
                                        <button class="btn btn-primary"><?= $lang['search'] ?></button>
                                        <input type="reset" class="btn btn-default clear"
                                               value="<?= $lang['clear'] ?>" />
                                    </div>
                                </div>
                                <div class="form-group row">
                                    <div class="col-md-1"></div>
                                    <div class="col-md-6">
                                        <a id="yesterday" style="float:left"
                                           class="btn btn-outline-info btn-sm"><?= $lang['yesterday'] ?></a>
                                        <a id="this_week" style="float:left"
                                           class="btn btn-outline-info btn-sm"><?= $lang['this_week'] ?></a>
                                        <a id="last_week" style="float:left"
                                           class="btn btn-outline-info btn-sm"><?= $lang['last_week'] ?></a>
                                        <a id="this_month" style="float:left"
                                           class="btn btn-outline-info btn-sm"><?= $lang['this_month'] ?></a>
                                        <a id="last_month" style="float:left"
                                           class="btn btn-outline-info btn-sm"><?= $lang['last_month'] ?></a>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <h4 id="summary_title" class="card-title"><?= $lang['company_summary'] ?></h4>
                            <div id="summary" class="table-overflow">
                                <table class="table table-lg table-hover">
                                    <thead>
                                        <tr>
                                            <th><?= $lang['vendor'] ?></th>
                                            <th class="text-right"><?= $lang['turnover'] ?></th>
                                            <th class="text-right"><?= $lang['game_spending'] ?></th>
                                            <th class="text-right"><?= $lang['game_profit'] ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php
                                        if (count($summary) > 0 && $record['code'] == 1000) {
                                            foreach ($summary as $row) {
                                                ?>
                                                <tr>
                                                    <td>
                                                        <?= $row['vendor_username'] ?>
                                                    </td>
                                                    <td class="text-right">
                                                        <?= number_format($row['turnover'], 4) ?>
                                                    </td>
                                                    <td class="text-right">
                                                        <span style='color:<?= ($row['game_spending'] < 0) ? 'red' : 'blue' ?>'>
                                                            <?= number_format($row['game_spending'], 4) ?>
                                                        </span>
                                                    </td>
                                                    <td class="text-right">
                                                        <span style='color:<?= ($row['game_profit'] < 0) ? 'red' : 'blue' ?>'>
                                                            <?= number_format($row['game_profit'], 4) ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                                <?php
                                            }
                                        }
                                    ?>
                                    </tbody>
                                </table>
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
                                            <th width="10px"></th>
                                            <th><?= $lang['vendor'] ?></th>
                                            <th class="text-right"><?= $lang['turnover'] ?></th>
                                            <th class="text-right"><?= $lang['net_profit'] ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php
                                    $vendor_id = -1;
                                    if (count($record['data']) > 0 && $record['code'] == 1000) {
                                        foreach ($record['data'] as $row) {
                                            $turnover = ($row['main'] == 1)
                                                ? $row['self_turnover']
                                                : $row['total_turnover'];
                                            $net_profit = ($row['main'] == 1)
                                                ? $row['self_nett_profit']
                                                : $row['total_nett_profit'];

                                            if ($vendor_id != $row['vendor_id'] && $vendor_id != -1) {
                                                echo '</table></div></td></tr>';
                                            }

                                            if ($row['main'] == 1) {
                                                $team_net_profit += $row['total_nett_profit'];
                                                $personal_net_profit += $row['self_nett_profit'];
                                                ?>
                                                <tr>
                                                    <td width="10px">
                                                        <button type="button" class="btn btn-info btn-circle"
                                                                id="show_<?= $row['vendor_id'] ?>" name="show[]"
                                                                data-vid="<?= $row['vendor_id'] ?>">
                                                            <i class="ti-plus"></i>
                                                            <!-- ti-minus ti-close -->
                                                        </button>
                                                        <button type="button" class="btn btn-danger btn-circle"
                                                                id="close_<?= $row['vendor_id'] ?>" name="close[]"
                                                                data-vid="<?= $row['vendor_id'] ?>"
                                                                style="display:none;" >
                                                            <i class="ti-close"></i>
                                                        </button>
                                                    </td>
                                                    <td><?= $row['vendor_username'] ?></td>
                                                    <td class="text-right">
                                                        <span style='color:<?= ($row['total_turnover'] < 0) ? 'red' : 'blue' ?>'>
                                                            <?= number_format($row['total_turnover'], 4) ?>
                                                        </span>
                                                    </td>
                                                    <td class="text-right">
                                                        <span style='color:<?= ($row['total_nett_profit'] < 0) ? 'red' : 'blue' ?>'>
                                                            <?= number_format($row['total_nett_profit'], 4) ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                                <?php
                                            }

                                            if ($vendor_id != $row['vendor_id']) {
                                                ?>
                                                <tr id="detail_<?= $row['vendor_id'] ?>" style="display:none;">
                                                    <td colspan="4">
                                                        <div>
                                                            <table width="100%">
                                                                <tr>
                                                                    <th><?= $lang['agent_account'] ?></th>
                                                                    <th class="text-right"><?= $lang['turnover'] ?></th>
                                                                    <th class="text-right"><?= $lang['holding_shares'] ?></th>
                                                                    <th class="text-right"><?= $lang['net_profit'] ?></th>
                                                                </tr>
                                                <?php
                                            }
                                            ?>
                                            <tr>
                                                <td>
                                                    <a href="./performance_query.php?start_date=<?= $_GET['start_date'] ?>&end_date=<?= $_GET['end_date'] ?>&agent_username=<?= $row['agent_username'] ?>&act=1">
                                                        <?= $row['agent_username'] ?>
                                                    </a>
                                                </td>
                                                <td class="text-right">
                                                    <span style='color:<?= ($turnover < 0) ? 'red' : 'blue' ?>'>
                                                        <?= number_format($turnover, 4) ?>
                                                    </span>
                                                </td>
                                                <td class="text-right">
                                                    <?= $row['share_point'] ?>/<?= $record['pb'] ?>
                                                </td>
                                                <td class="text-right">
                                                    <span style='color:<?= ($net_profit < 0) ? 'red' : 'blue' ?>'>
                                                        <?= number_format($net_profit, 4) ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <?php
                                            $vendor_id = $row['vendor_id'];
                                        }
                                        echo '</table></div></td></tr>';
                                        ?>
                                        <tr>
                                            <td colspan="2">
                                            </td>
                                            <td class="text-right">
                                                <?= $lang['team_net_profit'] ?>
                                            </td>
                                            <td class="text-right">
                                                <span style='color:<?= ($team_net_profit < 0) ? 'red' : 'blue' ?>'>
                                                       <?= number_format($team_net_profit, 4) ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td colspan="2">
                                            </td>
                                            <td class="text-right">
                                                <?= $lang['personal_net_profit'] ?>
                                            </td>
                                            <td class="text-right">
                                                <span style='color:<?= ($personal_net_profit < 0) ? 'red' : 'blue' ?>'>
                                                       <?= number_format($personal_net_profit, 4) ?>
                                                </span>
                                                <br />
                                                <span>
                                                    <?= $lang['net_profit_memo'] ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <?php
                                    } else { ?>
                                        <tr>
                                            <td colspan="4" align='center'>
                                                <div class="mrg-top-15">
                                                    <span class="text-info"><b><?= $lang['no_record']?></b></span>
                                                </div>
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
    $(".date-picker").daterangepicker({
        singleDatePicker: true,
        timePicker: false,
        timePicker24Hour: true,
        timePickerIncrement: 59,
        locale: {
            format: 'YYYY-MM-DD'
        }
    });

    $('.selectize').selectize({
        create: false,
        dropdownParent: 'body'
    });

    $("#summary_title").click(function() {
        if ($("#summary").is(":hidden")) {
            $("#summary").show();
        } else {
            $("#summary").hide();
        }
    });

    $('button[name="show[]"]').click(function() {
        vid = $(this).attr('data-vid');
        $("#show_" + vid).hide();
        $("#close_" + vid).show();
        $("#detail_" + vid).show();
    });

    $('button[name="close[]"]').click(function() {
        vid = $(this).attr('data-vid');
        $("#show_" + vid).show();
        $("#close_" + vid).hide();
        $("#detail_" + vid).hide();
    });

    $('#yesterday').click(function(e) {
        $("[name='start_date']").val("<?= date('Y-m-d', strtotime('-1day +8hour')) ?>");
        $("[name='end_date']").val("<?= date('Y-m-d', strtotime('-1day +8hour')) ?>");
    });

    $('#this_week').click(function(e) {
        $("[name='start_date']").val(
            "<?= date('Y-m-d', strtotime(- (date('N') - 1) . 'day +8hour')) ?>"
        );
        $("[name='end_date']").val(
            "<?= date('Y-m-d', strtotime((7 - date('N')) . 'day +8hour')) ?>"
        );
    });

    $('#last_week').click(function(e) {
        $("[name='start_date']").val(
            "<?= date('Y-m-d', strtotime(- (date('N') + 6) . 'day +8hour')) ?>"
        );
        $("[name='end_date']").val(
            "<?= date('Y-m-d', strtotime(- (date('N')) . 'day +8hour')) ?>"
        );
    });

    $('#this_month').click(function(e) {
        $("[name='start_date']").val("<?= date('Y-m-01', strtotime(' +8hour')) ?>");
        $("[name='end_date']").val("<?= date('Y-m-t', strtotime(' +8hour')) ?>");
    });

    $('#last_month').click(function(e) {
        $("[name='start_date']").val("<?= date('Y-m-01', strtotime('-1month +8hour')) ?>");
        $("[name='end_date']").val("<?= date('Y-m-t', strtotime('-1month +8hour')) ?>");
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
<style>
    .btn-circle {
        width: 30px;
        height: 30px;
        padding: 6px 0px;
        border-radius: 15px;
        text-align: center;
        font-size: 12px;
        line-height: 1.42857;
    }
</style>