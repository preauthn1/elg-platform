<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';
require_once '../class/CreatePage.php';

//確認權限
if (!isset($check_auth['sms/gateway_list'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

$filter = array();
$filter['status'] = isset($_REQUEST['status']) ? $_REQUEST['status'] : 1;
$filter['page'] = isset($_GET['page']) ? $_GET['page'] : 1;
$filter['per_page'] = 20;
$record = curlPost('api/sms/gateway/list', $filter);
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
                <h4>
                    <?= $lang['sms_management'] ?> > <?= $lang['sms_gateway_list'] ?>
                </h4>
            </div>
            <div class="row">
                <a href="gateway_edit.php" class="btn btn-inverse btn-success"><?= $lang['add'] ?></a>
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
                                            <th><?= $lang['sms_gateway_name'] ?></th>
                                            <th><?= $lang['sms_gateway_code'] ?></th>
                                            <th><?= $lang['api_url'] ?></th>
                                            <th><?= $lang['support_country'] ?></th>
                                            <th><?= $lang['memo'] ?></th>
                                            <th><?= $lang['enable_status'] ?></th>
                                            <th></th>
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
                                                        <?= $row['name'] ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-15">
                                                        <?= $row['code'] ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-15">
                                                        <?= $row['url'] ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-15">
                                                        <?php
                                                        if ($row['country_code'] == '') {
                                                            echo '<span class="text-danger">' . $lang['not_set'] . '<span>';
                                                        } else {
                                                            $country_code = explode(',', $row['country_code']);
                                                            $country_name = explode(',', $row['country_name']);
                                                            foreach($country_code as $key => $code) {
                                                                ?>
                                                                <?php /*<span>
                                                                    <img class="thumb-img"
                                                                         data-toggle="tooltip"
                                                                         title="<?= $country_name[$key]?>"
                                                                         src="../../assets/images/countries/<?= $code ?>.svg"
                                                                         style="display:inline-block; width:40px; height:40px" />
                                                                </span>*/?>
                                                                <div class="row">
                                                                    <?= $country_name[$key]?>
                                                                </div>
                                                                <?php
                                                            }
                                                        }
                                                        ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-15">
                                                        <?= $row['memo'] ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-15 <?= ($row['status'] != 1) ? 'text-danger font-weight-bold' : '' ?>"  >
                                                        <?= $row['status'] == 1 ? $lang['enable'] : $lang['disable'] ?>
                                                    </div>
                                                    <div class="mrg-top-10">
                                                        <input type="checkbox" class="toggle" name="toggle-event[]"
                                                               data-toggle="toggle"
                                                               data-pid="<?= $row['id'] ?>"
                                                               data-on="<?= $lang['enable'] ?>"
                                                               data-off="<?= $lang['disable'] ?>"
                                                            <?= ($row['status'] == 1) ? 'checked' : '' ?>>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-10">
                                                        <div class="dropdown inline-block">
                                                            <button class="btn btn-default btn-sm dropdown-toggle"
                                                                    type="button" data-toggle="dropdown">
                                                                <i class="ti-angle-down font-size-9"></i>
                                                            </button>
                                                            <ul class="dropdown-menu">
                                                                <li>
                                                                    <a href="gateway_edit.php?id=<?=$row['id']?>">
                                                                        <?=$lang['edit']?>
                                                                    </a>
                                                                </li>
                                                            </ul>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php
                                        }
                                    } else { ?>
                                        <tr>
                                            <td colspan="7" align='center'>
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

    //$('#identifier').tooltip(options);

    $('input[name="toggle-event[]"]').change(function() {
        $.ajax({
            cache: false,
            dataType: 'json',
            data: "action=sms_gateway_toggle_status" +
                "&id=" + $(this).attr('data-pid') +
                "&status=" + $(this).prop('checked'),
            url: '/ajax.php',
            type: 'POST',
            success: function(data) {
                if (data == null || data.code != 1000) {
                    alert(data.message);
                } else {
                    successAlert();
                }
            },
            error: function(err) {
                alert('error');
            }
        });
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