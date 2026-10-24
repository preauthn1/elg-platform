<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';
require_once '../class/CreatePage.php';

//確認權限
if (!isset($check_auth['report/sr12'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

//先取下拉選單用的值
$vendors = curlPost('api/vendor/list');
$game_type = curlPost('api/gameType/list', ['status' => 1]);

$fillter = array();
$fillter['vendor_id'] = $vendors['data'][0]['id'];
$fillter['type_id'] = $game_type['data'][0]['id'];
$fillter['page'] = 1;
$fillter['per_page'] = 1000;
$games = curlPost('api/game/list', $fillter);

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
                <h4><?= $lang['statistical_report'] ?> > <?= $lang['sr12_1'] ?></h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="row">
                                <div class="col-md-12">
                                    <form class="form-horizontal mrg-top-40 pdd-right-30">
                                        <div class="form-group row">
                                            <label for="form-1-1" class="col-md-1 control-label">
                                                <select class="selectize" name="type">
                                                    <option value="1" <?= ($_GET['type'] == 1) ? 'selected' : '' ?>>
                                                        <?= $lang['agent_account'] ?>
                                                    </option>
                                                    <option value="2" <?= ($_GET['type'] == 2) ? 'selected' : '' ?>>
                                                        <?= $lang['member_account'] ?>
                                                    </option>
                                                    <option value="3" <?= ($_GET['type'] == 3) ? 'selected' : '' ?>>
                                                        <?= $lang['ordernum'] ?>
                                                    </option>
                                                </select>
                                            </label>
                                            <div class="col-md-2">
                                                <input type="text" class="form-control" name="username"
                                                    value='<?= $_GET['username'] ?>' />
                                            </div>
                                            <label for="form-1-2"
                                                class="col-md-1 control-label"><?= $lang['platform'] ?></label>
                                            <div class="col-md-2">
                                                <select class="selectize" name="vendor_id">
                                                    <?php foreach ($vendors['data'] as $row) {
                                                        ?>
                                                    <option value="<?= $row['id'] ?>"
                                                        <?= ($_GET['vendor_id'] == $row['id']) ? 'selected' : '' ?>>
                                                        <?= $row['name'] ?></option>
                                                    <?php
                                                    } ?>
                                                </select>
                                            </div>

                                            <label for="form-1-2"
                                                class="col-md-1 control-label"><?= $lang['game_type'] ?></label>
                                            <div class="col-md-2">
                                                <select class="selectize" name="game_type">
                                                    <option value="0"
                                                        <?= ($_GET['game_type'] == 0) ? 'selected' : '' ?>>
                                                        <?= $lang['all'] ?></option>
                                                    <?php foreach ($game_type['data'] as $row) { ?>
                                                    <option value="<?= $row['id'] ?>"
                                                        <?= ($_GET['game_type'] == $row['id']) ? 'selected' : '' ?>>
                                                        <?= $row['name'] ?></option>
                                                    <?php } ?>
                                                </select>
                                            </div>

                                            <!-- <label for="form-1-2"
                                                class="col-md-1 control-label"><?= $lang['game'] ?></label>
                                            <div class="col-md-2">
                                                <select class="selectize" name="game_id">
                                                    <option value="0"><?= $lang['all'] ?></option>
                                                    <?php foreach ($games['data'] as $row) { ?>
                                                    <option value="<?= $row['id'] ?>"
                                                        <?= ($_GET['game_id'] == $row['id']) ? 'selected' : '' ?>>
                                                        <?= $row['name'] ?>
                                                    </option>
                                                    <?php } ?>
                                                </select>
                                            </div> -->

                                            <label for="form-1-2"
                                                class="col-md-1 control-label"><?= $lang['field_sort'] ?></label>
                                            <div class="col-md-2">
                                                <select class="selectize" name="sort_by">
                                                    <option value="1" <?= ($_GET['sort_by'] == 1) ? 'selected' : '' ?>>
                                                        <?= $lang['bet_time'] ?>
                                                    </option>
                                                    <option value="2" <?= ($_GET['sort_by'] == 2) ? 'selected' : '' ?>>
                                                        <?= $lang['bet_amount'] ?>
                                                    </option>
                                                </select>
                                            </div>
                                            <label for="form-1-2" class="col-md-1 control-label">
                                                <?= $lang['between'] ?>
                                                <h3 class="text-danger small">*
                                                    <?= $lang['keep_for_three_months'] ?>
                                                </h3>
                                            </label>
                                            <div class="col-md-2">
                                                <input type="text" class="form-control datetime-picker"
                                                    name="start_time"
                                                    value="<?= $_GET['start_time'] ? $_GET['start_time'] : date('Y-m-d 00:00') ?>" />
                                            </div>
                                            <label for="form-1-2"
                                                class="col-md-1 control-label"><?= $lang['to'] ?></label>
                                            <div class="col-md-2">
                                                <input type="text" class="form-control datetime-picker" name="end_time"
                                                    value="<?= $_GET['end_time'] ? $_GET['end_time'] : date('Y-m-d 23:59') ?>" />
                                            </div>
                                            <label for="form-1-2" class="col-md-1 control-label">
                                                <?= $lang['search_time'] ?>
                                            </label>
                                            <div class="col-md-2">
                                                <select class="selectize" name="whitch_time">
                                                    <option value="1"
                                                        <?= ($_GET['whitch_time'] == 1) ? 'selected' : '' ?>>
                                                        <?= $lang['bet_time'] ?>
                                                    </option>
                                                    <option value="2"
                                                        <?= ($_GET['whitch_time'] == 2) ? 'selected' : '' ?>>
                                                        <?= $lang['settle_time'] ?>
                                                    </option>
                                                </select>
                                            </div>
                                            <label for="form-1-2" class="col-md-1 control-label">
                                                <?= $lang['pagesize'] ?>
                                            </label>
                                            <div class="col-md-2">
                                                <select class="selectize" name="per_page">
                                                    <?php for ($i = 1; $i <= 6; $i++) {
                                                        $value = $i * 25;
                                                    ?>
                                                    <option value="<?= $value ?>"
                                                        <?= ($_GET['per_page'] == $value) ? 'selected' : '' ?>>
                                                        <?= $value ?>
                                                    </option>
                                                    <?php } ?>
                                                </select>
                                            </div>
                                            <div class="col-md-1"></div>
                                            <div class="col-md-6">
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

                                            <div class="col-md-4">
                                                <button type="button" class="btn btn-success" id="excel"
                                                    style="float:right">Excel</button>
                                                <button class="btn btn-primary"><?= $lang['search'] ?></button>
                                                <input type="hidden" name="act" value="1" />
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <div class="col-md-12 text-danger">
                                                <?= $lang['query_agent_show_direct_members'] ?>
                                            </div>
                                        </div>
                                    </form>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- 表格 遊戲記錄-->
            <div class="row" id="result">
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
        // sortField: {
        //     field: 'text',
        //     direction: 'asc'
        // },
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

    $.blockUI();
    $.get('sr12_ajax.php' + $(location).attr('search'), function(data) {
        $("#result").html(data);
        $.unblockUI();
    });
    //下載
    $("#excel").on('click', function(e) {
        $.blockUI();
        var vendor_id = $("[name='vendor_id']").val();
        var game_type = $("[name='game_type']").val();
        var type = $("[name='type']").val();
        var username = $("[name='username']").val();
        var sort_by = $("[name='sort_by']").val();
        var whitch_time = $("[name='whitch_time']").val();
        var start_time = $("[name='start_time']").val();
        var end_time = $("[name='end_time']").val();
        window.location = 'ajax.php?action=sr12&vendor_id=' + vendor_id + '&game_type=' + game_type +
            '&type=' + type + '&username=' + username + '&sort_by=' + sort_by + '&whitch_time=' +
            whitch_time + '&start_time=' + start_time + '&end_time=' + end_time;
        setTimeout($.unblockUI, 5000);
    });

});
</script>