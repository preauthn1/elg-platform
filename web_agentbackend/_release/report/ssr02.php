<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';
require_once '../commonFunction.php';

//確認權限
if (!isset($check_auth['report/ssr02'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

$cash_flow_request = array();
$cash_flows_result = curlPost('api/agent/cashFlows', $cash_flow_request);
$cash_flows_array = array();
if ($cash_flows_result['code'] == 1000) {
    $cash_flows_array = $cash_flows_result['data'];
}

$event_reward_result = curlPost('api/agent/eventReward');
$event_rewards = array();
if ($event_reward_result['code'] == 1000) {
    $event_rewards = $event_reward_result['data'];
}

$data = array();
if ($_GET['type'] == 1) {
    $data['agent_username'] = $_GET['username'];
} else if ($_GET['type'] == 2) {
    $data['member_username'] = $_GET['username'];
} else if ($_GET['type'] == 3) {
    $data['member_nickname'] = $_GET['username'];
} else if ($_GET['type'] == 4) {
    $data['transaction_no'] = $_GET['username'];
} else if ($_GET['type'] == 5) {
    $data['execution_username'] = $_GET['username'];
}

$data['source_temp'] = $_GET['source_temp'] ? $_GET['source_temp'] : 0;
$data['source'] = $_GET['source'] ? $_GET['source'] : 0;
$data['cash_flow_id'] = $_GET['cash_flow_id'] ? $_GET['cash_flow_id'] : "";
$data['filter'] = $_GET['filter'] ? $_GET['filter'] : 1;
$data['deposit_to'] = $_GET['deposit_to'] ? $_GET['deposit_to'] : 0;
$data['start_date'] = $start_date . ':00';
$data['end_date'] = $end_date . ':59';
$data['start_date'] = ($_GET['start_time'] ? $_GET['start_time'] : date('Y-m-d 00:00', strtotime('+8hour'))) . ':00';
$data['end_date'] = ($_GET['end_time'] ? $_GET['end_time'] : date('Y-m-d 23:59', strtotime('+8hour'))) . ':59';
$data['is_created_period'] = $_GET['is_created_period'] ? $_GET['is_created_period'] : 0;

$agent = curlPost('api/report/transactionTotalNew', $data);
if ($agent['code'] >= 9997) {
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
                <h4><?= $lang['statistical_report'] ?> > <?= $lang['ssr02'] ?></h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="row">
                                <div class="col-md-12">
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
                                                    <option value="5" <?= ($_GET['type'] == 5) ? 'selected' : '' ?>>
                                                        <?= $lang['execution_account'] ?>
                                                    </option>
                                                </select>
                                            </label>
                                            <div class="col-md-2">
                                                <input type="text" class="form-control" name="username"
                                                    value='<?= $_GET['username'] ?>' />
                                            </div>
                                            <label for="form-1-2" class="col-md-1 control-label">
                                                <?= $lang['between'] ?>
                                            </label>
                                            <div class="col-md-3">
                                                <input type="text" class="form-control datetime-picker"
                                                    name="start_time"
                                                    value="<?= $_GET['start_time'] ? $_GET['start_time'] : date('Y-m-d 00:00', strtotime('+8hour')) ?>" />
                                            </div>
                                            <label for="form-1-2" class="col-md-1 control-label">
                                                <?= $lang['to'] ?>
                                            </label>
                                            <div class="col-md-3">
                                                <input type="text" class="form-control datetime-picker" name="end_time"
                                                    value="<?= $_GET['end_time'] ? $_GET['end_time'] : date('Y-m-d 23:59', strtotime('+8hour')) ?>" />
                                            </div>
                                            <label for="form-1-1" class="col-md-2 control-label">
                                                <?= $lang['source'] ?>
                                            </label>
                                            <div class="col-md-2">
                                                <select class="selectize" id="source_temp" name="source_temp">
                                                    <option value="0">
                                                        <?= $lang['all'] ?>
                                                    </option>
                                                    <option value="1" <?= ($_GET['source_temp'] == 1) ? 'selected' : '' ?>>
                                                        <?= $lang['cs_system'] ?>
                                                    </option>
                                                    <?php
                                                    $last_cash_flow_type = 0;
                                                    foreach ($cash_flows_array as $item) {
                                                        $cash_flow_type = ($item['type'] == 1 || $item['type'] == 2) ? 3 : 4;
                                                        $selectValue = $cash_flow_type . '.' . $item['cash_flow_id'];
                                                        if ($last_cash_flow_type != $cash_flow_type) {
                                                            if ($last_cash_flow_type != 0) {
                                                                echo '</optgroup>';
                                                            }
                                                            ?>
                                                            <optgroup label="<?= ($cash_flow_type == 3 ? $lang['deposit'] : $lang['withdrawal']) ?>">
                                                        <?php
                                                        }
                                                        ?>
                                                        <option value="<?= $selectValue ?>"
                                                            <?= ($_GET['source_temp'] ===  $selectValue) ? 'selected' : '' ?>>
                                                            <?= '　' . $item['name'] ?>
                                                        </option>
                                                        <?php
                                                        $last_cash_flow_type = $cash_flow_type;
                                                    }

                                                    //活動獎勵
                                                    foreach ($event_rewards as $item) {
                                                        $type = 5;
                                                        $selectValue = $type . '.' . $item['event_reward_id'];
                                                        echo '</optgroup>';
                                                        ?>
                                                        <optgroup label="<?= $lang['reward'] ?>">
                                                            <option value="<?= $selectValue ?>"
                                                                <?= ($_GET['source_temp'] ===  $selectValue) ? 'selected' : '' ?>>
                                                                <?= '　' . $lang['transaction_type.' . $item['event_reward_id']] ?>
                                                            </option>
                                                    <?php } ?>
                                                    </optgroup>
                                                </select>
                                                <input type="hidden" id="source" name="source"
                                                    value="<?= $_GET['source'] ?>">
                                                <input type="hidden" id="cash_flow_id" name="cash_flow_id"
                                                    value="<?= $_GET['cash_flow_id'] ?>">
                                            </div>
                                            <div class="col-md-1"></div>
                                            <div class="col-md-7">
                                                <a id="today" style="float:left"
                                                    class="btn btn-outline-info btn-sm"><?= $lang['today'] ?></a>
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
                                            <label for="form-1-1"
                                                class="col-md-2 control-label"><?= $lang['filter'] ?></label>
                                            <div class="col-md-2">
                                                <select class="selectize" name="filter" id="filter"
                                                        data-hide-disabled="true">
                                                    <option value="1" <?= ($_GET['filter'] == 1) ? 'selected' : '' ?>>
                                                        <?= $lang['agent_cs_deposit_withdrawal'] ?>
                                                    </option>
                                                    <option value="2" <?= ($_GET['filter'] == 2) ? 'selected' : '' ?>>
                                                        <?= $lang['all_members'] ?>
                                                    </option>
                                                    <option value="3" <?= ($_GET['filter'] == 3) ? 'selected' : '' ?>>
                                                        <?= $lang['cs_to_agent_transfer'] ?>
                                                    </option>
                                                </select>
                                            </div>
                                            <div class="col-md-2">
                                                <select class="selectize" name="deposit_to">
                                                    <option value="0"
                                                        <?= ($_GET['deposit_to'] == 0) ? 'selected' : '' ?>>
                                                        <?= $lang['all'] ?>
                                                    </option>
                                                    <option value="1"
                                                        <?= ($_GET['deposit_to'] == 1) ? 'selected' : '' ?>>
                                                        <?= $lang['only_agent'] ?>
                                                    </option>
                                                    <option value="2"
                                                        <?= ($_GET['deposit_to'] == 2) ? 'selected' : '' ?>>
                                                        <?= $lang['only_member'] ?>
                                                    </option>
                                                </select>
                                            </div>
                                            <div class="col-md-1"><?= $lang['is_created_period'] ?></div>
                                            <div class="col-md-2">
                                                <select class="selectize" name="is_created_period">
                                                    <option value="0"
                                                        <?= ($_GET['is_created_period'] == 0) ? 'selected' : '' ?>>
                                                        <?= $lang['option_no'] ?>
                                                    </option>
                                                    <option value="1"
                                                        <?= ($_GET['is_created_period'] == 1) ? 'selected' : '' ?>>
                                                        <?= $lang['option_yes'] ?>
                                                    </option>
                                                </select>
                                            </div>
                                            <div class="col-md-1">
                                                <button class="btn btn-primary"><?= $lang['search'] ?></button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- 表格 總計-->
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="row">
                                <?php
                                $total_in = 0;
                                $total_out = 0;
                                $total_reward = 0;
                                foreach ($agent['data'] as $row) {
                                    $total_in += $row['total_in'];
                                    $total_out += $row['total_out'];
                                    $total_reward += $row['total_reward'];
                                }
                                ?>
                                <div class="col-sm-6 col-xl-3">
                                    <div class="alert alert-primary">
                                        <span class="display-6 lh-1 text-warning mb-0">
                                            <i class="fas"></i>
                                        </span>
                                        <div class="ms-4 h6 fw-normal">
                                            <p class="mb-0">
                                                <?= $lang['total_deposit'] ?>
                                            </p>
                                            <hr />
                                            <div class="d-flex">
                                                <h5>
                                                    <?php if ($_SESSION['toggle_thousands'] == 0) {
                                                        amount_thousands_off($total_in);
                                                    } else {
                                                        amount_thousands_on($total_in);
                                                    }?>
                                                </h5>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-6 col-xl-3">
                                    <div class="alert alert-danger">
                                        <span class="display-6 lh-1 text-blue mb-0">
                                            <i class="fas"></i>
                                        </span>
                                        <div class="ms-4 h6 fw-normal">
                                            <p class="mb-0">
                                                <?= $lang['total_withdrawl'] ?>
                                            </p>
                                            <hr />
                                            <div class="d-flex">
                                                <h5>
                                                    <?php if ($_SESSION['toggle_thousands'] == 0) {
                                                        amount_thousands_off($total_out);
                                                    } else {
                                                        amount_thousands_on($total_out);
                                                    }?>
                                                </h5>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-6 col-xl-3">
                                    <div class="alert alert-secondary">
                                        <span class="display-6 lh-1 text-purple mb-0">
                                            <i class="fas"></i>
                                        </span>
                                        <div class="ms-4 h6 fw-normal">
                                            <p class="mb-0">
                                                <?= $lang['total_deposit'] ?>-<?= $lang['total_withdrawl'] ?>
                                            </p>
                                            <hr />
                                            <div class="d-flex">
                                                <h5>
                                                    <?php if ($_SESSION['toggle_thousands'] == 0) {
                                                        amount_thousands_off(($total_in - $total_out));
                                                    } else {
                                                        amount_thousands_on(($total_in - $total_out));
                                                    }?>
                                                </h5>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-sm-6 col-xl-3">
                                    <div class="alert alert-success">
                                        <span class="display-6 lh-1 text-info mb-0">
                                            <i class="fas"></i>
                                        </span>
                                        <div class="ms-4 h6 fw-normal">
                                            <p class="mb-0">
                                                <?= $lang['total_reward'] ?>
                                            </p>
                                            <hr />
                                            <div class="d-flex">
                                                <h5>
                                                    <?php if ($_SESSION['toggle_thousands'] == 0) {
                                                        amount_thousands_off($total_reward);
                                                    } else {
                                                        amount_thousands_on($total_reward);
                                                    }?>
                                                </h5>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <!--
                            <div class="table-overflow">
                                <table class="table table-lg table-hover">
                                    <thead>
                                        <tr>
                                            <th class="text-right">
                                                <?= $lang['total_deposit'] ?>
                                            </th>
                                            <th class="text-right">
                                                <?= $lang['total_withdrawl'] ?>
                                            </th>
                                            <th class="text-right">
                                                <?= $lang['total_deposit'] ?>-<?= $lang['total_withdrawl'] ?>
                                            </th>
                                            <th class="text-right">
                                                <?= $lang['total_reward'] ?>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $total_in = 0;
                                        $total_out = 0;
                                        $total_reward = 0;
                                        foreach ($agent['data'] as $row) {
                                            $total_in += $row['total_in'];
                                            $total_out += $row['total_out'];
                                            $total_reward += $row['total_reward'];
                                        }
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="mrg-top-15 text-right">
                                                    <?php if ($_SESSION['toggle_thousands'] == 0) {
                                                        amount_thousands_off($total_in);
                                                    } else {
                                                        amount_thousands_on($total_in);
                                                    }?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15 text-right">
                                                    <?php if ($_SESSION['toggle_thousands'] == 0) {
                                                        amount_thousands_off($total_out);
                                                    } else {
                                                        amount_thousands_on($total_out);
                                                    }?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15 text-right">
                                                    <?php if ($_SESSION['toggle_thousands'] == 0) {
                                                        amount_thousands_off(($total_in - $total_out));
                                                    } else {
                                                        amount_thousands_on(($total_in - $total_out));
                                                    }?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15 text-right">
                                                    <?php if ($_SESSION['toggle_thousands'] == 0) {
                                                        amount_thousands_off($total_reward);
                                                    } else {
                                                        amount_thousands_on($total_reward);
                                                    }?>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            -->
                        </div>
                    </div>
                </div>
            </div>
            <!-- 表格 明細-->
            <div class="row" id="result"></div>
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

    $('#today').click(function(e) {
        $("[name='start_time']").val("<?= date('Y-m-d 00:00', strtotime('+8hour')) ?>");
        $("[name='end_time']").val("<?= date('Y-m-d 23:59', strtotime('+8hour')) ?>");
    });

    $('#yesterday').click(function(e) {
        $("[name='start_time']").val("<?= date('Y-m-d 00:00', strtotime('-1day +8hour')) ?>");
        $("[name='end_time']").val("<?= date('Y-m-d 23:59', strtotime('-1day +8hour')) ?>");
    });

    $('#this_week').click(function(e) {
        $("[name='start_time']").val(
            "<?= date('Y-m-d 00:00', strtotime(- (date('N') - 1) . 'day +8hour')) ?>"
        );
        $("[name='end_time']").val(
            "<?= date('Y-m-d 23:59', strtotime((7 - date('N')) . 'day +8hour')) ?>"
        );
    });
    $('#last_week').click(function(e) {
        $("[name='start_time']").val(
            "<?= date('Y-m-d 00:00', strtotime(- (date('N') + 6) . 'day +8hour')) ?>"
        );
        $("[name='end_time']").val(
            "<?= date('Y-m-d 23:59', strtotime(- (date('N')) . 'day +8hour')) ?>"
        );
    });
    $('#this_month').click(function(e) {
        $("[name='start_time']").val("<?= date('Y-m-01 00:00', strtotime(' +8hour')) ?>");
        $("[name='end_time']").val("<?= date('Y-m-t 23:59', strtotime(' +8hour')) ?>");
    });
    $('#last_month').click(function(e) {
        $("[name='start_time']").val("<?= date('Y-m-01 00:00', strtotime('-1month +8hour')) ?>");
        $("[name='end_time']").val("<?= date('Y-m-t 23:59', strtotime('-1month +8hour')) ?>");
    });

    //下載
    $(document).on('click', '#excel', function(e) {
        var type = $("[name='type']").val();
        var username = $("[name='username']").val();
        var sorurce = $("[name='source']").val();
        var filter = $("[name='filter']").val();
        var deposit_to = $("[name='deposit_to']").val();
        var start_time = $("[name='start_time']").val();
        var end_time = $("[name='end_time']").val();
        var is_created_period = $("[name='is_created_period']").val();
        var cash_flow_id = $("#cash_flow_id").val();

        window.location = 'ajax.php?action=transactionListNew' +
            '&type=' + type +
            '&username=' + username +
            '&source=' + sorurce +
            '&filter=' + filter +
            '&deposit_to=' + deposit_to +
            '&cash_flow_id=' + cash_flow_id +
            '&start_date=' + start_time +
            '&end_date=' + end_time +
            '&is_created_period=' + is_created_period;
    });

    // AJAX獲取代理明細
    function getAgentDetail(param) {
        $.get('ssr02_agent_ajax.php' + param, function(data) {
            $("#result").html(data);
            $.unblockUI();
        });
    }

    // AJAX獲取會員明細
    function getDetail(param) {
        $.get('ssr02_ajax.php' + param, function(data) {
            $("#result").html(data);
            $.unblockUI();
        });
    }

    $.blockUI();
    getDetail($(location).attr('search'));

    //下拉選單change (Source)
    $("#source_temp").change(function() {
        var myValue = $(this).val();
        //如果是deposit的選項會以 3.1, 3.2, 3.3, withdrawal的選項會以 4.1, 4.2, 4.3 這樣子做為下拉選單值 (瞎)
        var isTypeOfDeposit = myValue.includes('3.');
        var isTypeOfWithdrawal = myValue.includes('4.');
        var isTypeOfEvent = myValue.includes('5.');

        $("#source").val(myValue);
        $("#cash_flow_id").val('');

        var filter_selectize = $('#filter')[0].selectize;
        var sel_value = filter_selectize.getValue();
        var options = [];
        filter_selectize.clearOptions();
        if (isTypeOfDeposit || isTypeOfWithdrawal || isTypeOfEvent) {
            //逗號第一碼是 type  第二碼是cash_flow_id
            const split_text = myValue.split('.');
            $("#source").val(split_text[0]);
            $("#cash_flow_id").val(split_text[1]);

            options.push({value: 2, text: "<?= $lang['all_members'] ?>"});
            sel_value = 2;
        } else {
            options.push({value:1, text:"<?= $lang['agent_cs_deposit_withdrawal'] ?>"});
            options.push({value:2, text:"<?= $lang['all_members'] ?>"});
            options.push({value:3, text:"<?= $lang['cs_to_agent_transfer'] ?>"});
        }
        filter_selectize.addOption(options);
        filter_selectize.setValue(sel_value);
    });
    $("#source_temp").change();

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
                param = '?page=' + page;
            } else {
                param = result + '&page=' + page;
            }
            getDetail(param);
        }
    });

});
</script>