<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';
require_once '../class/CreatePage.php';
require_once '../commonFunction.php';

//確認權限
if (!isset($check_auth['report/sr16_period'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

$filter = array();
$luckydraw = curlPost('api/dropdown/luckydraw', $filter);

if ($_GET['act'] == 1) {
    $is_manual_draw = check_permission_menuCode('sr16_manual_draw');

    $filter = array();
    $filter['period_id'] = isset($_GET['period_id']) ? $_GET['period_id'] : 0;
    $filter['page'] = isset($_GET['page']) ? $_GET['page'] : 1;
    $filter['per_page'] = 3;
    $record = curlPost('api/v_jackpot/period', $filter);
    if ($record['code'] >= 9997) {
        header("location:" . BASE_URL . "login.php");
        exit();
    }
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
                <h4><?= $lang['statistical_report'] ?> > <?= $lang['menu_report_sr16'] ?> > <?= $lang['menu_sr16_period'] ?></h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="">
                                <form class="form-horizontal mrg-top-40 pdd-right-30">
                                    <div class="form-group row">
                                        <label for="form-1-2" class="col-md-1 control-label">
                                            <?= $lang['period'] ?>
                                        </label>
                                        <div class="col-md-5">
                                            <select class="selectize" name="period_id" id="period_id">
                                                <?php
                                                foreach ($luckydraw['data'] as $row) {
                                                    ?>
                                                    <option value="<?= $row['id'] ?>"
                                                        <?= ($_GET['period_id'] == $row['id']) ? 'selected' : '' ?>>
                                                        <?= str_replace('{N}', $row['no'], $lang['periods']) ?> - <?= $row['start_time']?> ~ <?= $row['end_time']?>
                                                    </option>
                                                    <?php
                                                }
                                                ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <input type="hidden" name="act" value="1" />
                                            <button type="button" class="btn btn-success" id="excel" style="float:right">Excel</button>
                                            <button class="btn btn-primary"><?= $lang['search'] ?></button>
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
                                        <th><?= $lang['period'] ?></th>
                                        <th><?= $lang['winning_numbers'] ?></th>
                                        <th class='text-right'><?= $lang['total_number_of_pots'] ?></th>
                                        <th class='text-right'><?= $lang['number_of_tickets_issued'] ?></th>
                                        <th class='text-right'><?= $lang['bonus'] ?></th>
                                        <th class='text-right'><?= $lang['ultimate_pot'] ?></th>
                                        <th class='text-right'><?= $lang['first_prize'] ?></th>
                                        <th class='text-right'><?= $lang['second_prize'] ?></th>
                                        <th class='text-right'><?= $lang['third_prize'] ?></th>
                                        <th class='text-right'><?= $lang['fourth_prize'] ?></th>
                                        <th class='text-right'>&nbsp;</th>
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
                                                        <?= str_replace('{N}', $row['no'], $lang['periods']) ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-15">
                                                        <?= $row['draw_number'] == '' ? '' : $row['draw_number'] ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-15 text-right">
                                                        <?php
                                                        if ($_SESSION['toggle_thousands'] == 0) {
                                                            amount_thousands_off($row['v_jackpot'], 2);
                                                        } else {
                                                            amount_thousands_on($row['v_jackpot'], 2);
                                                        }
                                                        ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-15 text-right">
                                                        <?= number_format($row['tickets'], 0) ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-15 text-right">
                                                        <?php
                                                        if ($_SESSION['toggle_thousands'] == 0) {
                                                            amount_thousands_off($row['point'], 2);
                                                        } else {
                                                            amount_thousands_on($row['point'], 2);
                                                        }
                                                        ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-15 text-right">
                                                        <?= $row['reward_item_1'] ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-15 text-right">
                                                        <?= $row['reward_item_2'] ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-15 text-right">
                                                        <?= $row['reward_item_3'] ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-15 text-right">
                                                        <?= $row['reward_item_4'] ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-15 text-right">
                                                        <?= $row['reward_item_5'] ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="mrg-top-10 text-center">
                                                        <?php
                                                        if ($row['draw_number'] != '') {
                                                            ?>
                                                            <button class="winning_list btn btn-info"
                                                                    data-toggle="modal"
                                                                    data-target="#winning_list"
                                                                    data-period_id="<?= $row['id'] ?>">
                                                                <?= $lang['winning_list'] ?>
                                                            </button>
                                                            <?php
                                                        } else if ($is_manual_draw) {
                                                            ?>
                                                            <button class="manual_draw btn btn-danger"
                                                                    <?= ($row['manual_draw'] == 1) ? '' : 'disabled'; ?>
                                                                    data-period_id="<?= $row['id'] ?>"
                                                                    data-period_no="<?= $row['no'] ?>"
                                                                    data-period_date="<?= $row['draw_time'] ?>">
                                                                <?= $lang['manual_draw'] ?>
                                                            </button>
                                                            <?php
                                                        }
                                                        ?>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php
                                        }
                                    } else { ?>
                                        <tr>
                                            <td colspan="10" align='center'>
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
<div class="modal fade" id="winning_list">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content" id="winning_content">
        </div>
    </div>
</div>
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

    $(document).on('click', '.manual_draw', function(e) {
        var period_id = $(this).attr('data-period_id');
        var period_no = $(this).attr('data-period_no');
        var period_date = $(this).attr('data-period_date');
        Swal.fire({
            title: ('<?=$lang['periods']?>').replace("{N}", period_no) + ' <?=$lang['manual_draw']?>',
            html:
                '<input id="swal-input1" class="swal2-input" required style="width:25%;" maxlength="6">' +
                '　—　' +
                '<input id="swal-input2" class="swal2-input" required style="width:12%;" maxlength="1">',
            inputAttributes: {
                autocapitalize: 'off'
            },
            confirmButtonText: '<?=$lang['confirm']?>',
            showCancelButton: true,
            cancelButtonText: '<?=$lang['cancel']?>',
            showLoaderOnConfirm: true,
            preConfirm: () => {
                var a = $('#swal-input1').val();
                var b = $('#swal-input2').val();
                if (!(/^\d{6}$/).test(a)) {
                    $('#swal-input1').focus();
                    Swal.showValidationMessage(
                        `Request failed: format error`
                    );
                } else if (!(/^\d{1}$/).test(b)) {
                    $('#swal-input2').focus();
                    Swal.showValidationMessage(
                        `Request failed: format error`
                    );
                } else {
                    var obj = {
                        period_id: period_id,
                        number: a + '-' + b,
                        periods: ('<?=$lang['periods']?>').replace("{N}", period_no),
                        date: period_date
                    };
                    //return JSON.stringify(obj);
                    return obj;
                }
            },
            allowOutsideClick: () => !Swal.isLoading()
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    title: ('<?=$lang['confirm_draw_number'] ?>').replace('{code}', result.value.number),
                    html:
                        '<?=$lang['period']?>:' + result.value.periods + '<br />' +
                        '<?=$lang['time']?>:' + result.value.date,
                    confirmButtonText: '<?=$lang['confirm']?>',
                    showCancelButton: true,
                    cancelButtonText: '<?=$lang['cancel']?>',
                    showLoaderOnConfirm: true,
                    icon: 'info',
                    preConfirm: () => {
                        $.ajax({
                            cache: false,
                            dataType: 'json',
                            data: "action=lucky_ticket_manual_draw" +
                                "&period_id=" + result.value.period_id +
                                "&period_no=" + result.value.number,
                            url: '/ajax.php',
                            type: 'POST',
                            success: (data) => {
                                if (data == null || data.code != 1000) {
                                    Swal.fire(
                                        '<?=$lang['manual_draw'] ?>',
                                        '<?= $lang['failure'] ?>',
                                        'error'
                                    ).then(() => {
                                        window.location.reload();
                                    });
                                } else {
                                    Swal.fire(
                                        '<?=$lang['manual_draw'] ?>',
                                        '<?= $lang['success'] ?>',
                                        'success'
                                    ).then(() => {
                                        window.location.reload();
                                    });
                                }
                            },
                            error: (err) => {
                                Swal.fire(
                                    '<?=$lang['manual_draw'] ?>',
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
            }
        });
    });

    //開啟中獎清單
    $(document).on('click', '.winning_list', function(e) {
        var period_id = $(this).attr('data-period_id');

        //先清空內容
        $("#winning_content").html('');

        $.post("winning_list.php", {
            'period_id': period_id
        }, function(data) {
            $("#winning_content").html(data);
        });
    });


    //下載
    $("#excel").on('click', function(e) {
        var period_id = $("#period_id").val();

        $.blockUI();
        window.location = 'ajax.php?action=sr16_period' +
            '&period_id=' + period_id;
        setTimeout($.unblockUI, 5000);
    });
});
</script>