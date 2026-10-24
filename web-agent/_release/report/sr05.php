<?php
require_once '../_init.php';
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
$data['source'] = $_GET['source'] ? $_GET['source'] : 0;
$data['start_date'] = $start_date;
$data['end_date'] = $end_date;
$member = curlPost('api/report/sr05', $data);
if ($member['code'] >= 9997) {
    header("location:" . BASE_URL . "login.php");
    exit();
}
require_once '../_header.php';
require_once '../_menu.php';
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
                <h4>統計報表查詢 > SR05 客服轉入轉出明細表</h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="row">
                                <div class="col-md-10">
                                    <form class="form-horizontal mrg-top-40 pdd-right-30">
                                        <div class="form-group row">
                                            <label for="form-1-1" class="col-md-3 control-label">
                                                <select class="selectize" name="type">
                                                    <option value="1" <?= ($_GET['type'] == 1) ? 'selected' : '' ?>>
                                                        代理商帳號</option>
                                                    <option value="2" <?= ($_GET['type'] == 2) ? 'selected' : '' ?>>會員帳號
                                                    <option value="3" <?= ($_GET['type'] == 3) ? 'selected' : '' ?>>會員暱稱
                                                    <option value="4" <?= ($_GET['type'] == 4) ? 'selected' : '' ?>>單號
                                                    </option>
                                                </select>
                                            </label>
                                            <div class="col-md-7">
                                                <input type="text" class="form-control" name="username"
                                                    value='<?= $_GET['username'] ?>' />
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <label for="form-1-1" class="col-md-3 control-label">來源</label>
                                            <div class="col-md-7">
                                                <select class="selectize" name="source">
                                                    <option value="0">全部</option>
                                                    <option value="1" <?= ($_GET['source'] == 1) ? 'selected' : '' ?>>客服
                                                    </option>
                                                    <option value="2" <?= ($_GET['source'] == 2) ? 'selected' : '' ?>>
                                                        線上支付</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <label for="form-1-2" class="col-md-3 control-label">期間</label>
                                            <div class="col-md-7">
                                                <input type="text" id="range" class="form-control" name="range"
                                                    placeholder="Date range picker" />
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <label for="form-1-2" class="col-md-2 control-label"></label>
                                            <div class="col-md-10">
                                                <button class="btn btn-primary">Search</button>
                                                <!-- <input type="reset" class="btn btn-default" value="Clear" /> -->
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
                            <h4>會員列表</h4>
                            <button type="button" class="btn btn-success" id="excel" style="float:right">Excel</button>
                            <div class="table-overflow">
                                <!-- <table id="dt-opt" class="table table-lg table-hover"> -->
                                <table class="table table-lg table-hover">
                                    <thead>
                                        <tr>
                                            <th>編號</th>
                                            <th>單號</th>
                                            <th>日期</th>
                                            <th>代理商帳號</th>
                                            <th>狀態</th>
                                            <th>會員帳號</th>
                                            <th>會員暱稱</th>
                                            <th>變更點數</th>
                                            <th>當下點數</th>
                                            <th>來源</th>
                                            <th>執行者帳號</th>
                                            <th>訂單狀態</th>
                                            <th>備註</th>
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
                                                    <span><?= $row['id'] ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span><?= $row['transaction_no'] ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span><?= $row['created_at'] ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span class="text-info"><b><?= $row['agent_username'] ?></b></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span><?= ($row['type'] == 1 ? '轉入' : '轉出') ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span class="text-info"><b><?= $row['member_username'] ?></b></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span><?= $row['member_nickname'] ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span><?= $row['point'] ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span><?= $row['balance'] ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span><?= ($row['source'] == 1) ? '客服' : '線上支付' ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span><?= $row['operator_username'] ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span><?= ($row['status'] == 1) ? '成功' : '失敗' ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span><?= $row['remark'] ?></span>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php
                                                }
                                            } else { ?>
                                        <tr>
                                            <td colspan="13" align='center'>
                                                <div class="mrg-top-15">
                                                    <span class="text-info"><b>暫無記錄</b></span>
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
    //變更日期格式
    $('#range').daterangepicker({
        // timePicker: true,
        // timePicker24Hour: true,
        // timePickerIncrement: 5,
        startDate: '<?= $start_date ?>',
        endDate: '<?= $end_date ?>',
        locale: {
            format: 'YYYY-MM-DD'
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

    //下載
    $("#excel").on('click', function(e) {
        var type = $("[name='type']").val();
        var username = $("[name='username']").val();
        var sorurce = $("[name='source']").val();
        var range = $("[name='range']").val();
        window.location = 'ajax.php?action=sr05&type=' + type + '&username=' + username + '&source=' +
            sorurce + '&range=' + range;
    });

});
</script>