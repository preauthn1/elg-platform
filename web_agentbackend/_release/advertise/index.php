<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';

//確認權限
if (!isset($check_auth['advertise/index'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

//$start_time = $_GET['start_time']
//    ? substr($_GET['start_time'], 0, 10)
//    : date('Y-m-d', strtotime('-1month'));
//$end_time = $_GET['end_time']
//    ? substr($_GET['end_time'], -10)
//    : date('Y-m-d');
$path_ids = $_GET['path_id'] ? $_GET['path_id'] : array();

// 版位列表
$data = array();
$data['page'] = 1;
$data['per_page'] = 1000;
$data['status'] = 1;
$result = curlPost('api/advertisementPath/list', $data);
if ($result['code'] != 1000) {
    if ($result['code'] > 9997) {
        header("location:" . BASE_URL . "login.php");
    } else {
        header('Location:' . BASE_URL . "index.php");
    }
    exit();
} else {
    $paths = $result['data'];
}

// 廣告列表
$advertisements = array();
$data = array();
//$data['start_time'] = $start_time . ' 00:00:00';
////$data['end_time'] = $end_time . ' 23:59:59';
$data['path_id'] = $path_ids;
$result = curlPost('api/advertise/list', $data);
if ($result['code'] != 1000) {
    if ($result['code'] > 9997) {
        header("location:" . BASE_URL . "login.php");
    } else {
        header('Location:' . BASE_URL . "index.php");
    }
    exit();
} else {
    $advertises = $result['data'];
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
                <h4><?= $lang['system_management'] ?> > <?= $lang['menu_advertisement'] ?></h4>
            </div>
            <div class="row">
                <a href="edit.php" class="btn btn-inverse btn-success">
                    <?= $lang['add'] ?>
                </a>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="row">
                                <div class="col-md-12">
                                    <h4 class="card-title"><?= $lang['search'] ?></h4>
                                    <form class="form-horizontal mrg-top-40 pdd-right-30">
                                        <div class="form-group row">
                                            <label for="form-1-1" class="col-md-2 control-label">
                                                <?= $lang['type'] ?>
                                            </label>
                                            <div class="col-md-6">
                                                <select class="selectize" name="path_id[]" multiple="multiple" >
                                                    <?php foreach ($paths as $path) { ?>
                                                    <option value="<?= $path['id'] ?>"
                                                        <?= empty($path_ids) ? "selected" :  in_array($path['id'], $path_ids) ? "selected" : ""?>>
                                                        <?= $path['name'] ?>
                                                    </option>
                                                    <?php } ?>
                                                </select>
                                            </div>
                                            <label for="form-1-2" class="col-md-2 control-label">&nbsp;</label>
                                            <div class="col-md-2">
                                                <button class="btn btn-primary" id="search">
                                                    <?= $lang['search'] ?>
                                                </button>
                                            </div>
                                        </div>
                                        <!--
                                        <div class="form-group row">
                                            <label for="form-1-2" class="col-md-2 control-label">
                                                <?= $lang['search_time'] ?>
                                            </label>
                                            <div class="col-md-2">
                                                <input type="text" class="form-control date-picker" name="start_time"
                                                       value="<?= $_GET['start_time'] ? $_GET['start_time'] : date('Y-m-d 00:00', strtotime('-8hour')) ?>" />
                                            </div>
                                            <label for="form-1-2"
                                                   class="col-md-1 control-label"><?= $lang['to'] ?></label>
                                            <div class="col-md-2">
                                                <input type="text" class="form-control date-picker" name="end_time"
                                                       value="<?= $_GET['end_time'] ? $_GET['end_time'] : date('Y-m-d 23:59', strtotime('+16hour')) ?>" />
                                            </div>
                                        </div>
                                        -->
                                        <div class="form-group row">
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
                                <table id="dt-opt2" class="table table-lg table-hover">
                                    <thead>
                                        <tr>
                                            <th><?= $lang['id'] ?></th>
                                            <th><?= $lang['layout_location'] ?></th>
                                            <th><?= $lang['hyperlink'] ?></th>
                                            <th><?= $lang['content'] ?></th>
                                            <th><?= $lang['label_start_time'] ?></th>
                                            <th><?= $lang['label_end_time'] ?></th>
                                            <th><?= $lang['agent'] ?></th>
                                            <!--<th><?= $lang['choose_language'] ?></th>-->
                                            <th><?= $lang['picture'] ?></th>
                                            <th><?= $lang['status'] ?></th>
                                            <th><?= $lang['operator'] ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($advertises as $advertise) {
                                            $details = $advertise['detail'];
                                            ?>
                                        <tr>
                                            <td>
                                                <div>
                                                    <span>
                                                        <?= $advertise['id'] ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span>
                                                        <?= $advertise['advertisement_path_name'] ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span>
                                                        <?= $lang['advertisement_type_' . $advertise['link_type']] ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span>
                                                        <?= $advertise['content'] ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span class="text-info">
                                                        <b>
                                                            <?= substr($advertise['start_time'], 0, -9) ?>
                                                        </b>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span class="text-info">
                                                        <b>
                                                            <?= substr($advertise['end_time'], 0, -9) ?>
                                                        </b>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <?= $advertise['username'] ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="list-info">
                                                    <?php foreach($details as $detail) { ?>
                                                        <img class="thumb-img"
                                                             data-toggle="modal" data-target="#image_modal"
                                                             data-src="<?= $detail['image_url'] ?>"
                                                             src="<?= $detail['image_url'] ?>"
                                                             title="<?= $detail['name'] ?>"
                                                             alt="<?= $detail['name'] ?>" />
                                                    <?php } ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15 <?= ($advertise['status'] != 1) ? 'text-danger' : '' ?>">
                                                    <?= (($advertise['status'] == 1) ? $lang['enable'] : $lang['disable']) ?>
                                                </div>
                                            </td>
                                            <td>
                                                <a href="edit.php?id=<?= $advertise['id'] ?>" class="btn btn-default btn-sm">
                                                    <?= $lang['edit'] ?>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
        <!--
        <div class="modal fade" id="image_modal">
            <div class="modal-dialog modal-lg" role="document">
                <div class="modal-content" id="show_image">
                </div>
            </div>
        </div>-->
    <?php require_once '../_bottom.php'; ?>
</div>
<?php require_once '../_footer.php'; ?>
<script>
$(document).ready(function() {
    $('#date-range-picker').daterangepicker({
        startDate: '<?= $start_date ?>',
        endDate: '<?= $end_date ?>',
        locale: {
            format: 'YYYY-MM-DD'
        }
    });

    $(".date-picker").daterangepicker({
        singleDatePicker: true,
        singleDatePicker: true,
        timePicker: true,
        timePicker24Hour: true,
        timePickerIncrement: 59,
        locale: {
            format: 'YYYY-MM-DD'
        }
    });

    $('.selectize').selectize({
        plugins: ['remove_button'],
        create: false,
        dropdownParent: 'body'
    });

    //開啟視窗
    $(document).on('click', '.thumb-img', function (e) {
        console.log('aaa');
        $("#show_image").html('<img scr="' + $(this).attr('data-src') + '" />');

    });
});
</script>