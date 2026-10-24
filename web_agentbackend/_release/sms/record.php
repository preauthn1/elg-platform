<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';
require_once '../class/CreatePage.php';

//確認權限
if (!isset($check_auth['sms/record'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

$start_date = isset($_GET['start_time']) ? $_GET['start_time'] : date('Y-m-d H:i:s', strtotime('-1day'));
$end_date = isset($_GET['end_time']) ? $_GET['end_time'] : date('Y-m-d H:i:s');

$filter = array();
$record = curlPost('api/sms/dropdown', $filter);
if ($record['code'] != 1000) {
    header("location:" . BASE_URL . "login.php");
    exit();
} else {
    $sms_gateways = $record['data'];
}

$filter = array();
$filter['agent_username'] = isset($_GET['agent_username']) ? $_GET['agent_username'] : '';
$filter['member_username'] = isset($_GET['member_username']) ? $_GET['member_username'] : '';
$filter['mobile'] = isset($_GET['mobile']) ? $_GET['mobile'] : "";
$filter['sms_gateway_id'] = isset($_GET['sms_gateway_id']) ? $_GET['sms_gateway_id'] : 0;
$filter['start_time'] = $start_date;
$filter['end_time'] = $end_date;
$filter['status'] = isset($_GET['status']) ? $_GET['status'] : '';
$filter['page'] = isset($_GET['page']) ? $_GET['page'] : 1;
$filter['per_page'] = 20;
$record = curlPost('api/sms/record', $filter);
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
                <h4><?= $lang['sms_management'] ?> > <?= $lang['sms_record'] ?></h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div>
                                <form class="form-horizontal mrg-top-40 pdd-right-30">
                                    <div class="form-group row">
                                        <label for="form-1-2" class="col-md-1 control-label">
                                            <?= $lang['sms_gateway_name'] ?>
                                        </label>
                                        <div class="col-md-2">
                                            <select class="selectize" name="sms_gateway_id">
                                                <option value=""><?= $lang['all'] ?></option>
                                                <?php
                                                foreach ($sms_gateways as $sms_gateway) {
                                                    ?>
                                                    <option value="<?= $sms_gateway['id'] ?>"
                                                        <?= ($_GET['sms_gateway_id'] == $sms_gateway['id']) ? 'selected' : '' ?>>
                                                        <?= $sms_gateway['name'] ?>
                                                    </option>
                                                <?php
                                                }
                                                ?>
                                            </select>
                                        </div>
                                        <label for="form-1-2" class="col-md-1 control-label">
                                            <?= $lang['agent_account'] ?>
                                        </label>
                                        <div class="col-md-2">
                                            <input type="text" class="form-control" name="agent_username" id="agent_username"
                                                   value='<?= $_GET['agent_username'] ?>' />
                                        </div>
                                        <label for="form-1-2" class="col-md-1 control-label">
                                            <?= $lang['member_account'] ?>
                                        </label>
                                        <div class="col-md-2">
                                            <input type="text" class="form-control" name="member_username" id="member_username"
                                                   value='<?= $_GET['member_username'] ?>' />
                                        </div>
                                        <label for="form-1-2" class="col-md-1 control-label">
                                            <?= $lang['status'] ?>
                                        </label>
                                        <div class="col-md-2">
                                            <select id="selectize-dropdown" name="status">
                                                <option value="" checked><?= $lang['all'] ?></option>
                                                <option value="1" <?= ($_GET['status'] == '1') ? 'selected' : '' ?>>
                                                    <?= $lang['sent_successfully'] ?>
                                                </option>
                                                <option value="2" <?= ($_GET['status'] == '2') ? 'selected' : '' ?>>
                                                    <?= $lang['failed_to_send'] ?>
                                                </option>
                                                <option value="0" <?= ($_GET['status'] == '0') ? 'selected' : '' ?>>
                                                    <?= $lang['unsent'] ?>
                                                </option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <label for="form-1-2" class="col-md-1 control-label">
                                            <?= $lang['mobile'] ?>
                                        </label>
                                        <div class="col-md-2">
                                            <input type="text" class="form-control" name="mobile" id="mobile"
                                                   value='<?= $_GET['mobile'] ?>' />
                                        </div>
                                        <label for="form-1-2" class="col-md-1 control-label"><?= $lang['between'] ?></label>
                                        <div class="col-md-2">
                                            <input type="text" class="form-control datetime-picker" name="start_time"
                                                id="start_time"
                                                value="<?= isset($_GET['start_time']) ? $_GET['start_time'] : date('Y-m-d 00:00') ?>" />
                                        </div>
                                        <label for="form-1-2" class="col-md-1 control-label"><?= $lang['to'] ?></label>
                                        <div class="col-md-2">
                                            <input type="text" class="form-control datetime-picker" name="end_time"
                                                id="end_time"
                                                value="<?= isset($_GET['end_time']) ? $_GET['end_time'] : date('Y-m-d 23:59') ?>" />
                                        </div>
                                        <div class="col-md-3">
                                            <button class="btn btn-primary"><?= $lang['search'] ?></button>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-4"></div>
                                        <div class="col-md-8">
                                            <?php
                                                require_once '../global/six_types_datepicker.php';
                                            ?>
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
                                        <th><?= $lang['date'] ?></th>
                                        <th><?= $lang['sms_gateway_name'] ?></th>
                                        <th><?= $lang['agent_account'] ?></th>
                                        <th><?= $lang['member_account'] ?></th>
                                        <th><?= $lang['mobile'] ?></th>
                                        <th><?= $lang['content'] ?></th>
                                        <th>transaction_id</th>
                                        <th><?= $lang['status'] ?></th>
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
                                                        <?= $row['created_at'] ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-15">
                                                        <?= $row['sms_gateway_name'] ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-15">
                                                        <?= $row['agent_username'] ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-15">
                                                        <?= $row['username'] ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-15">
                                                        <?= $row['mobile'] ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-15">
                                                        <?= $row['message_content'] ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-15">
                                                        <?= $row['transaction_id'] ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-15 <?= ($row['status'] != 1) ? 'text-danger font-weight-bold' : '' ?>"  >
                                                        <?= $row['status'] == 1 ? $lang['status_1'] : $lang['status_0'] ?>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php
                                        }
                                    } else { ?>
                                        <tr>
                                            <td colspan="9" align='center'>
                                                <div class="mrg-top-15">
                                                    <span class="text-info"><b><?= $lang['no_record']?></b></span>
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
$(document).ready(function() {
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