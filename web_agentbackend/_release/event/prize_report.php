<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';

//確認權限
if (!isset($check_auth['event/prize_report'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

//先取下拉選單用的值
// 活動列表
$data = array();
$data['page'] = 1;
$data['per_page'] = 1000;
$result = curlPost('api/dropdown/event', $data);
if ($result['code'] != 1000) {
    if ($result['code'] > 9997) {
        header("location:" . BASE_URL . "login.php");
    } else {
        header('Location:' . BASE_URL . "index.php");
    }
    exit();
} else {
    $events = $result['data'];
}
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
                <h4><?= $lang['event_exclusive_offers'] ?> > <?= $lang['menu_event_report'] ?></h4>
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
                                                    <!-- <option value="1" <?= ($_GET['type'] == 1) ? 'selected' : '' ?>>
                                                        總公司帳號
                                                    </option> -->
                                                    <option value="2" <?= ($_GET['type'] == 2) ? 'selected' : '' ?>>
                                                        <?= $lang['agent_account'] ?>
                                                    </option>
                                                    <option value="3" <?= ($_GET['type'] == 3) ? 'selected' : '' ?>>
                                                        <?= $lang['member_account'] ?>
                                                    </option>
                                                </select>
                                            </label>
                                            <div class="col-md-2">
                                                <input type="text" class="form-control" name="username"
                                                    value='<?= $_GET['username'] ?>' />
                                            </div>
                                            <label for="form-1-2" class="col-md-1 control-label"><?= $lang['event_name'] ?></label>
                                            <div class="col-md-5">
                                                <select class="selectize" name="event_id">
                                                    <?php foreach ($events as $row) { ?>
                                                    <option value="<?= $row['id'] ?>"
                                                        <?= ($_GET['event_id'] == $row['id']) ? 'selected' : '' ?>>
                                                        <?= $row['id'] . ' - ' . $row['title'] ?></option>
                                                    <?php } ?>
                                                </select>
                                            </div>
                                            <div class="col-md-2">
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
            <div class="row" id="detail"></div>
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
    $('#range').daterangepicker({
        // timePicker: true,
        // timePicker24Hour: true,
        // timePickerIncrement: 60,
        startDate: '<?= $start ?>',
        endDate: '<?= $end ?>',
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

    // AJAX獲取會員明細
    function getDetail(param) {
        $.get('prize_report_ajax.php' + param, function(data) {
            $("#detail").html(data);
            $.unblockUI();
        });
    }

    $.blockUI();
    getDetail($(location).attr('search'));

    //下載
    $(document).on('click', '#excel', function(e) {
        window.location = '../report/ajax.php?action=prize_report&' + $(location).attr('search');
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
                param = '?page=' + page;
            } else {
                param = result + '&page=' + page;
            }
            getDetail(param);
        }
    });
});
</script>