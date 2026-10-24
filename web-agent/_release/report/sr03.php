<?php
require_once '../_init.php';
$start_date = $_GET['range'] ? substr($_GET['range'], 0, 10) : date('Y-m-d', strtotime('-7day'));
$end_date = $_GET['range'] ? substr($_GET['range'], 13, 10) : date('Y-m-d');
$data = array();
if ($_GET['username'] != '') {
    $data['agent_username'] = $_GET['username'];
}
$data['source'] = 0;
$data['start_date'] = $start_date;
$data['end_date'] = $end_date;
$user = curlPost('api/report/sr03User', $data);
$agent = curlPost('api/report/sr03Agent', $data);
$member = curlPost('api/report/sr03Member', $data);
if ($user['code'] >= 9997 || $agent['code'] >= 9997 || $member['code'] >= 9997) {
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
                <h4>統計報表查詢 > SR03 開洗分統計表</h4>
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
                                                代理商帳號
                                            </label>
                                            <div class="col-md-7">
                                                <input type="text" class="form-control" name="username"
                                                    value='<?= $_GET['username'] ?>' />
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
            <!-- 表格 User-->
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <h4>總公司</h4>
                            <div class="table-overflow">
                                <table class="table table-lg table-hover">
                                    <thead>
                                        <tr>
                                            <th>總公司帳號</th>
                                            <th>會員總開分</th>
                                            <th>會員總洗分</th>
                                            <th>總開分-總洗分</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        if (count($user['data']) > 0) {
                                            foreach ($user['data'] as $row) {
                                                ?>
                                        <tr>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span class="text-info"><b>admin</b></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span><?= $row['total_in'] ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span><?= $row['total_out'] ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span><?= ($row['total_in'] - $row['total_out']) ?></span>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php
                                                }
                                            } else { ?>
                                        <tr>
                                            <td colspan="4" align='center'>
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
            <!-- 表格 agent-->
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <h4>代理商</h4>
                            <div class="table-overflow">
                                <table class="table table-lg table-hover">
                                    <thead>
                                        <tr>
                                            <th>代理商</th>
                                            <th>會員總開分</th>
                                            <th>會員總洗分</th>
                                            <th>總開分-總洗分</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        if (count($agent['data']) > 0) {
                                            foreach ($agent['data'] as $row) {
                                                ?>
                                        <tr>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span class="text-info"><b><?= $row['agent_username'] ?></b></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span><?= $row['total_in'] ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span><?= $row['total_out'] ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span><?= ($row['total_in'] - $row['total_out']) ?></span>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php
                                                }
                                            } else { ?>
                                        <tr>
                                            <td colspan="4" align='center'>
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
                                            <th>會員帳號</th>
                                            <th>會員總開分</th>
                                            <th>會員總洗分</th>
                                            <th>總開分-總洗分</th>
                                            <th>代理商帳號</th>
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
                                                    <span class="text-info"><b><?= $row['member_username'] ?></b></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span><?= $row['total_in'] ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span><?= $row['total_out'] ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span><?= ($row['total_in'] - $row['total_out']) ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span><?= ($row['agent_username']) ?></span>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php
                                                }
                                            } else { ?>
                                        <tr>
                                            <td colspan="6" align='center'>
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
        var username = $("[name='username']").val();
        var range = $("[name='range']").val();
        window.location = 'ajax.php?action=sr03&username=' + username + '&range=' + range;
    });

});
</script>