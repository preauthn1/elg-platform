<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';
require_once '../class/CreatePage.php';

//確認權限
if (!isset($check_auth['cost/index'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

$filter = array();
$filter['type'] = isset($_GET['type']) ? $_GET['type'] : 0;
$filter['page'] = isset($_GET['page']) ? $_GET['page'] : 1;
$filter['per_page'] = 10;
$record = curlPost('api/cost/list', $filter);
if ($record['code'] != 1000) {
    if ($record['code'] > 9997) {
        header("location:" . BASE_URL . "login.php");
    } else {
        header('Location:' . $_SERVER['HTTP_REFERER']);
    }
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
                <h4><?= $lang['system_management'] ?> > <?= $lang['menu_cost_list'] ?></h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <h4 class="card-title"><?= $lang['search'] ?></h4>
                            <form class="form-horizontal mrg-top-40 pdd-right-30">
                                <div class="form-group row">
                                    <label for="form-1-2" class="col-md-1 control-label">
                                        <?= $lang['type'] ?>
                                    </label>
                                    <div class="col-md-3">
                                        <select class="selectize" id="type" name="type">
                                            <option value="0" <?= ($_GET['type'] == 0) ? 'selected' : '' ?>>
                                                <?= $lang['all'] ?>
                                            </option>
                                            <option value="1" <?= ($_GET['type'] == 1) ? 'selected' : '' ?>>
                                                <?= $lang['cost_type_1'] ?>
                                            </option>
                                            <option value="2" <?= ($_GET['type'] == 2) ? 'selected' : '' ?>>
                                                <?= $lang['cost_type_2'] ?>
                                            </option>
                                            <option value="3" <?= ($_GET['type'] == 3) ? 'selected' : '' ?>>
                                                <?= $lang['cost_type_3'] ?>
                                            </option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <input type="hidden" name="act" value="1" />
                                        <button class="btn btn-primary"><?= $lang['search'] ?></button>
                                        <input type="reset" class="btn btn-default clear"
                                               value="<?= $lang['clear'] ?>" />
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
                            <div class="table-overflow">
                                <table class="table table-lg table-hover">
                                    <thead>
                                    <tr>
                                        <th><?= $lang['type'] ?></th>
                                        <th><?= $lang['name'] ?></th>
                                        <th><?= $lang['cost'] ?></th>
                                        <th></th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php
                                    foreach ($record['data'] as $row) {
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="relative mrg-top-15">
                                                    <?= $lang['cost_type_' . $row['type']]?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="list-info mrg-top-10">
                                                    <div class="info">
                                                        <span class="title">
                                                            <?= $row['oname'] . ' ' ?>
                                                            <b><?= $row['name'] ?></b>
                                                        </span>
                                                        <span class="sub-title">
                                                            <?= $lang['id'] ?><?=$row['id']?>
                                                        </span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="relative mrg-top-15">
                                                    <span class="pdd-left-20">
                                                        <?= $row['cost'] . (($row['type'] != 3) ? '%' : '')?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <button name="edit[]" class="btn btn-success"
                                                        data-id="<?= $row['id'] ?>"
                                                        data-type="<?= $row['type'] ?>"
                                                        data-oname="<?= $row['oname'] ?>"
                                                        data-name="<?= $row['name'] ?>">
                                                    <?= $lang['edit'] ?>
                                                </button>
                                            </td>
                                        </tr>
                                        <?php
                                    }
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
    <?php
    require_once '../_bottom.php';
    ?>
</div>
<?php
require_once '../_footer.php';
?>
<script>
    $( document ).ready(function() {
        $('.selectize').selectize({
            create: false,
            dropdownParent: 'body'
        });

        $('button[name="edit[]"]').click(function() {
            var id = $(this).attr("data-id");
            var type = $(this).attr("data-type");
            var oname = $(this).attr("data-oname");
            var name = $(this).attr("data-name");
            console.log(oname, name);
            var title = (type == 1)
                ? "<?= $lang['cost_type_1'] ?>"
                : ((type == 2) ? "<?= $lang['cost_type_2'] ?>" : "<?= $lang['cost_type_3'] ?>");
            Swal.fire({
                title: '<?= $lang['edit'] ?> - ' + title + '<?= $lang['cost'] ?>',
                html: `<div>` + oname + ` - <b>` + name + `</b></div><input type="number" id="swal-cost" class="swal2-input">`,
                showCancelButton: true,
                confirmButtonText: '<?= $lang['confirm'] ?>',
                cancelButtonText: '<?= $lang['cancel'] ?>',
                showLoaderOnConfirm: true,
                icon: 'warning',
                preConfirm: () => {
                    cost = $('#swal-cost').val();
                    $.ajax({
                        cache: false,
                        dataType: 'json',
                        data: "action=cost_setting" +
                            "&type=" + type +
                            "&id=" + id +
                            "&cost=" + cost,
                        url: '/ajax.php',
                        type: 'POST',
                        success: (data) => {
                            if (data == null || data.code != 1000) {
                                Swal.fire(
                                    '<?=$lang['cost'] ?>',
                                    '<?= $lang['failure'] ?>',
                                    'error'
                                ).then(() => {
                                    window.location.reload();
                                });
                            } else {
                                Swal.fire(
                                    '<?=$lang['cost'] ?>',
                                    '<?= $lang['success'] ?>',
                                    'success'
                                ).then(() => {
                                    window.location.reload();
                                });
                            }
                        },
                        error: (err) => {
                            alert(err);
                            Swal.fire(
                                '<?=$lang['cost'] ?>',
                                '<?= $lang['failure'] ?>' + err.message,
                                'error'
                            ).then(() => {
                                window.location.reload();
                            });
                        }
                    })
                },
                allowOutsideClick: () => !Swal.isLoading()
            })
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