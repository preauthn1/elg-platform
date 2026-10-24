<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';

//確認權限
if (!isset($check_auth['advertisement/index'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

$start_date = $_GET['dates']
    ? substr($_GET['dates'], 0, 10)
    : date('Y-m-d', strtotime('-1month'));
$end_date = $_GET['dates']
    ? substr($_GET['dates'], -10)
    : date('Y-m-d');
$path_ids = $_GET['p'] ? $_GET['p'] : '';
// 版位列表
$data = array();
$data['page'] = 1;
$data['per_page'] = 1000;
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
$data['start_time'] = $start_date . ' 00:00:00';
$data['end_time'] = $end_date . ' 23:59:59';
foreach ($path_ids as $path_id) {
    $data['path_id'] = $path_id;
    $result = curlPost('api/advertisement/list', $data);
    if ($result['code'] != 1000) {
        if ($result['code'] > 9997) {
            header("location:" . BASE_URL . "login.php");
        } else {
            header('Location:' . BASE_URL . "index.php");
        }
        exit();
    } else {
        array_push($advertisements, $result['data']);
    }
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

                <a href="/image/upload.php" class="btn btn-inverse btn-warning">
                    <?= $lang['menu_system_image_upload'] ?>
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
                                                <select id="selectize-tags-1" name="p[]" multiple="multiple"
                                                    class="selectize">
                                                    <?php
                                                    foreach ($paths as $path) {
                                                        ?>
                                                    <option value="<?= $path['id'] ?>"
                                                        <?= empty($path_ids) ? "selected" :  in_array($path['id'], $path_ids) ? "selected" : ""?>>
                                                        <?= $path['name'] ?></option>
                                                    <?php
                                                    }
                                                    ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <label for="form-1-2" class="col-md-2 control-label">
                                                <?= $lang['search_time'] ?>
                                            </label>
                                            <div class="col-md-3">
                                                <input type="text" id="date-range-picker" name="dates"
                                                    class="form-control" placeholder="Date range picker" />
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <label for="form-1-2" class="col-md-2 control-label">&nbsp;</label>
                                            <div class="col-md-3">
                                                <button class="btn btn-primary" id="search">
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

            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="table-overflow">
                                <table id="dt-opt" class="table table-lg table-hover">
                                    <thead>
                                        <tr>
                                            <th><?= $lang['type'] ?></th>
                                            <th><?= $lang['choose_language'] ?></th>
                                            <th><?= $lang['picture'] ?></th>
                                            <th><?= $lang['label_start_time'] ?></th>
                                            <th><?= $lang['label_end_time'] ?></th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        foreach ($advertisements as $advertisement) {
                                            $advertisement = $advertisement[0];
                                            if (count($advertisement['images']) > 0) {
                                                foreach ($advertisement['images'] as $image) {
                                                    ?>
                                        <tr>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span>
                                                        <?= $advertisement['name'] ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span>
                                                        <?= $image['language_name'] ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="list-info mrg-top-10">
                                                    <img class="thumb-img" src="<?= $image['image'] ?>" alt="">
                                                </div>
                                            </td>
                                            <?php /* <td>
                                                <div class="relative mrg-top-15">
                                                    <span class="pdd-left-20"><?= $image['url'] ?></span>
                            </div>
                            </td> */?>
                            <td>
                                <div class="mrg-top-15">
                                    <span class="text-info">
                                        <b>
                                            <?= substr($image['start_time'], 0, -3) ?>
                                        </b>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <div class="mrg-top-15">
                                    <span class="text-info">
                                        <b>
                                            <?= substr($image['end_time'], 0, -3) ?>
                                        </b>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <a href="edit.php?id=<?= $image['id'] ?>" class="btn btn-default btn-sm">
                                    <?= $lang['edit'] ?>
                                </a>
                            </td>
                            </tr>
                            <?php
                                                }
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
    $('#date-range-picker').daterangepicker({
        startDate: '<?= $start_date ?>',
        endDate: '<?= $end_date ?>',
        locale: {
            format: 'YYYY-MM-DD'
        }
    });
});
</script>