<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';
require_once '../commonFunction.php';
require_once '../class/CreatePage.php';

//確認權限
if (!isset($check_auth['report/sr17_period'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

$filter = array();
$w_jackpots = curlPost('api/dropdown/wjackpot', $filter);

if ($_GET['act'] == 1) {
    $is_manual_draw = check_permission_menuCode('sr17_manual_draw');

    $filter = array();
    $filter['period_id'] = isset($_GET['period_id']) ? $_GET['period_id'] : 0;
    $filter['page'] = isset($_GET['page']) ? $_GET['page'] : 1;
    $filter['per_page'] = 3;
    $record = curlPost('api/w_jackpot/period', $filter);
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
                <h4><?= $lang['statistical_report'] ?> > <?= $lang['menu_report_sr17'] ?> > <?= $lang['menu_sr16_period'] ?></h4>
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
                                                foreach ($w_jackpots['data'] as $row) {
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
                                        <th class='text-center'><?= $lang['winning_numbers'] ?></th>
                                        <th class='text-right'><?= $lang['total_number_of_pots'] ?></th>
                                        <th class='text-right'><?= $lang['number_of_tickets_issued'] ?></th>
                                        <th class='text-right'><?= $lang['bonus'] ?></th>
                                        <th class='text-right'><?= $lang['first_prize'] ?></th>
                                        <th class='text-right'><?= $lang['second_prize'] ?></th>
                                        <th class='text-right'><?= $lang['third_prize'] ?></th>
                                        <th class='text-right'><?= $lang['special_prize'] ?></th>
                                        <th class='text-right'><?= $lang['consolation_prize'] ?></th>
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
                                                    <div class="mrg-right-15 text-center">
                                                        <?php if ($row['draw_number'] != '') { ?>
                                                        <a class="prize_number btn btn-warning"
                                                           data-toggle="modal"
                                                           data-target="#prize_number"
                                                           data-prize_1="<?= $row['draw_number'] ?>"
                                                           data-prize_2="<?= $row['draw_number_2'] ?>"
                                                           data-prize_3="<?= $row['draw_number_3'] ?>"
                                                           data-prize_4="<?= $row['draw_number_4'] ?>"
                                                           data-prize_5="<?= $row['draw_number_5'] ?>">
                                                            <?= $lang['detail'] ?>
                                                        </a>
                                                        <?php } ?>
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
<div class="modal fade" id="prize_number">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content" id="prize_number_content">
        </div>
    </div>
</div>
<script>
$(document).ready(function() {
    $(function () {
        $('[data-toggle="tooltip"]').tooltip()
    });

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
                '<div id="swal-input" class="col-md-10">' +
                '<div class="row">' +
                    '<div class="col-6"><?= $lang['first_prize'] ?></div>' +
                    '<div class="col-6">' +
                        '<input id="swal-input1" name="draw_number" required maxlength="4" onkeyup="value=value.replace(/[^\\d]/g,\'\')">' +
                    '</div>' +
                '</div>' +
                '<div class="row">' +
                    '<div class="col-6"><?= $lang['second_prize'] ?></div>' +
                    '<div class="col-6">' +
                        '<input id="swal-input2" name="draw_number_2" required maxlength="4" onkeyup="value=value.replace(/[^\\d]/g,\'\')">' +
                    '</div>' +
                '</div>' +
                '<div class="row">' +
                    '<div class="col-6"><?= $lang['third_prize'] ?></div>' +
                    '<div class="col-6">' +
                        '<input id="swal-input3" name="draw_number_3" required maxlength="4" onkeyup="value=value.replace(/[^\\d]/g,\'\')">' +
                    '</div>' +
                '</div>' +
                '<div class="row"><div class="col-12"><?= $lang['special_prize'] ?></div></div>' +
                '<div class="row">' +
                '<input id="swal-input40" name="draw_number_4[]" required style="width:20%;" maxlength="4" onkeyup="value=value.replace(/[^\\d]/g,\'\')">' +
                '<input id="swal-input41" name="draw_number_4[]" required style="width:20%;" maxlength="4" onkeyup="value=value.replace(/[^\\d]/g,\'\')">' +
                '<input id="swal-input42" name="draw_number_4[]" required style="width:20%;" maxlength="4" onkeyup="value=value.replace(/[^\\d]/g,\'\')">' +
                '<input id="swal-input43" name="draw_number_4[]" required style="width:20%;" maxlength="4" onkeyup="value=value.replace(/[^\\d]/g,\'\')">' +
                '<input id="swal-input44" name="draw_number_4[]" required style="width:20%;" maxlength="4" onkeyup="value=value.replace(/[^\\d]/g,\'\')">' +
                '</div>' +
                '<div class="row">' +
                '<input id="swal-input45" name="draw_number_4[]" required style="width:20%;" maxlength="4" onkeyup="value=value.replace(/[^\\d]/g,\'\')">' +
                '<input id="swal-input46" name="draw_number_4[]" required style="width:20%;" maxlength="4" onkeyup="value=value.replace(/[^\\d]/g,\'\')">' +
                '<input id="swal-input47" name="draw_number_4[]" required style="width:20%;" maxlength="4" onkeyup="value=value.replace(/[^\\d]/g,\'\')">' +
                '<input id="swal-input48" name="draw_number_4[]" required style="width:20%;" maxlength="4" onkeyup="value=value.replace(/[^\\d]/g,\'\')">' +
                '<input id="swal-input49" name="draw_number_4[]" required style="width:20%;" maxlength="4" onkeyup="value=value.replace(/[^\\d]/g,\'\')">' +
                '</div>' +
                '<div class="row"><div class="col-12"><?= $lang['consolation_prize'] ?></div></div>' +
                '<div class="row">' +
                '<input id="swal-input50" name="draw_number_5[]" required style="width:20%;" maxlength="4" onkeyup="value=value.replace(/[^\\d]/g,\'\')">' +
                '<input id="swal-input51" name="draw_number_5[]" required style="width:20%;" maxlength="4" onkeyup="value=value.replace(/[^\\d]/g,\'\')">' +
                '<input id="swal-input52" name="draw_number_5[]" required style="width:20%;" maxlength="4" onkeyup="value=value.replace(/[^\\d]/g,\'\')">' +
                '<input id="swal-input53" name="draw_number_5[]" required style="width:20%;" maxlength="4" onkeyup="value=value.replace(/[^\\d]/g,\'\')">' +
                '<input id="swal-input54" name="draw_number_5[]" required style="width:20%;" maxlength="4" onkeyup="value=value.replace(/[^\\d]/g,\'\')">' +
                '</div>' +
                '<div class="row">' +
                '<input id="swal-input55" name="draw_number_5[]" required style="width:20%;" maxlength="4" onkeyup="value=value.replace(/[^\\d]/g,\'\')">' +
                '<input id="swal-input56" name="draw_number_5[]" required style="width:20%;" maxlength="4" onkeyup="value=value.replace(/[^\\d]/g,\'\')">' +
                '<input id="swal-input57" name="draw_number_5[]" required style="width:20%;" maxlength="4" onkeyup="value=value.replace(/[^\\d]/g,\'\')">' +
                '<input id="swal-input58" name="draw_number_5[]" required style="width:20%;" maxlength="4" onkeyup="value=value.replace(/[^\\d]/g,\'\')">' +
                '<input id="swal-input59" name="draw_number_5[]" required style="width:20%;" maxlength="4" onkeyup="value=value.replace(/[^\\d]/g,\'\')">' +
                '</div>' +
                '</div>',
            inputAttributes: {
                autocapitalize: 'on'
            },
            confirmButtonText: '<?=$lang['confirm']?>',
            showCancelButton: true,
            cancelButtonText: '<?=$lang['cancel']?>',
            showLoaderOnConfirm: true,
            preConfirm: () => {
                var rno = [];
                rno[1] = $('#swal-input1').val();
                rno[2] = $('#swal-input2').val();
                rno[3] = $('#swal-input3').val();
                rno[40] = $('#swal-input40').val();
                rno[41] = $('#swal-input41').val();
                rno[42] = $('#swal-input42').val();
                rno[43] = $('#swal-input43').val();
                rno[44] = $('#swal-input44').val();
                rno[45] = $('#swal-input45').val();
                rno[46] = $('#swal-input46').val();
                rno[47] = $('#swal-input47').val();
                rno[48] = $('#swal-input48').val();
                rno[49] = $('#swal-input49').val();
                rno[50] = $('#swal-input50').val();
                rno[51] = $('#swal-input51').val();
                rno[52] = $('#swal-input52').val();
                rno[53] = $('#swal-input53').val();
                rno[54] = $('#swal-input54').val();
                rno[55] = $('#swal-input55').val();
                rno[56] = $('#swal-input56').val();
                rno[57] = $('#swal-input57').val();
                rno[58] = $('#swal-input58').val();
                rno[59] = $('#swal-input59').val();
                var rno4 = [];
                rno4[0] = $('#swal-input40').val();
                rno4[1] = $('#swal-input41').val();
                rno4[2] = $('#swal-input42').val();
                rno4[3] = $('#swal-input43').val();
                rno4[4] = $('#swal-input44').val();
                rno4[5] = $('#swal-input45').val();
                rno4[6] = $('#swal-input46').val();
                rno4[7] = $('#swal-input47').val();
                rno4[8] = $('#swal-input48').val();
                rno4[9] = $('#swal-input49').val();
                var rno5 = [];
                rno5[0] = $('#swal-input50').val();
                rno5[1] = $('#swal-input51').val();
                rno5[2] = $('#swal-input52').val();
                rno5[3] = $('#swal-input53').val();
                rno5[4] = $('#swal-input54').val();
                rno5[5] = $('#swal-input55').val();
                rno5[6] = $('#swal-input56').val();
                rno5[7] = $('#swal-input57').val();
                rno5[8] = $('#swal-input58').val();
                rno5[9] = $('#swal-input59').val();

                var tag = 0;
                rno.forEach(function(value, idx){
                    if (tag == 0 && !(/^\d{4}$/).test(value)) { tag = idx; }
                });

                if (tag > 0) {
                    $('#swal-input' + tag).focus();
                    Swal.showValidationMessage(
                        `Request failed: format error`
                    );
                } else {
                    var obj = {
                        period_id: period_id,
                        rno_1: rno[1],
                        rno_2: rno[2],
                        rno_3: rno[3],
                        rno_4: rno4,
                        rno_5: rno5,
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
                    title: '<?= $lang['confirm_information'] ?>',
                    html:
                        '<?= $lang['period'] ?>:' + result.value.periods + '<br />' +
                        '<?= $lang['event_draw_date'] ?>:' + result.value.date + '<br />' +
                        '<?= $lang['sr17_award_name_1'] ?>:' + result.value.rno_1 + '<br />' +
                        '<?= $lang['sr17_award_name_2'] ?>:' + result.value.rno_2 + '<br />' +
                        '<?= $lang['sr17_award_name_3'] ?>:' + result.value.rno_3 + '<br />' +
                        '<?= $lang['sr17_award_name_4'] ?>:<br />' + result.value.rno_4 + '<br />' +
                        '<?= $lang['sr17_award_name_5'] ?>:<br />' + result.value.rno_5,
                    confirmButtonText: '<?=$lang['confirm']?>',
                    showCancelButton: true,
                    cancelButtonText: '<?=$lang['cancel']?>',
                    showLoaderOnConfirm: true,
                    icon: 'info',
                    preConfirm: () => {
                        $.ajax({
                            cache: false,
                            dataType: 'json',
                            data: "action=w_jackpot_ticket_manual_draw" +
                                "&period_id=" + result.value.period_id +
                                "&period_no=" + result.value.rno_1 +
                                "&period_no_2=" + result.value.rno_2 +
                                "&period_no_3=" + result.value.rno_3 +
                                "&period_no_4=" + result.value.rno_4 +
                                "&period_no_5=" + result.value.rno_5,
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
                                console.log(err);
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
    
    //開啟中獎號碼
    $(document).on('click', '.prize_number', function(e) {
        var prize_1 = $(this).attr('data-prize_1');
        var prize_2 = $(this).attr('data-prize_2');
        var prize_3 = $(this).attr('data-prize_3');
        var prize_4 = $(this).attr('data-prize_4');
        var prize_5 = $(this).attr('data-prize_5');

        //先清空內容
        $("#prize_number_content").html('');
        $.post("prize_list.php", {
            'prize_1': prize_1,
            'prize_2': prize_2,
            'prize_3': prize_3,
            'prize_4': prize_4,
            'prize_5': prize_5
        }, function(data) {
            $("#prize_number_content").html(data);
        });
    });

    //開啟中獎清單
    $(document).on('click', '.winning_list', function(e) {
        var period_id = $(this).attr('data-period_id');
        //先清空內容
        $("#winning_content").html('');

        $.post("winning_list.php", {
            'period_id': period_id,
            'type': 2
        }, function(data) {
            $("#winning_content").html(data);
        });
    });

    //下載
    $("#excel").on('click', function(e) {
        var period_id = $("#period_id").val();

        $.blockUI();
        window.location = 'ajax.php?action=sr17_period' +
            '&period_id=' + period_id;
        setTimeout($.unblockUI, 5000);
    });
});

function checkWJackpotNumber(id, val)
{
    var v = $('#' + id).val();
    if (!(/^\d{4}$/).test(v)) {
        $('#' + id).focus();
    }
}

</script>
<style>
    .tooltip-inner {
        max-width: 400px;
        width: 400px;
    }
</style>