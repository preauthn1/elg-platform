<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';
require_once '../class/CreatePage.php';

if (!isset($check_auth['point_barrel/cost_setting'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

$filter = array();
$filter['page'] = isset($_GET['page']) ? $_GET['page'] : 1;
$filter['per_page'] = 5;
$record = curlPost('api/point_barrel/cost', $filter);
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
                <h4><?= $lang['point_barrel_management'] ?> > <?= $lang['ps_cost_setting'] ?></h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div id="summary" class="table-overflow">
                                <table class="table table-lg table-hover">
                                    <thead>
                                    <tr>
                                        <th><?= $lang['date'] ?></th>
                                        <th class="text-right"><?= $lang['vendor'] ?></th>
                                        <th class="text-right"><?= $lang['cost'] ?></th>
                                        <th class="text-right"><?= $lang['memo'] ?></th>
                                        <th class="text-right"><?= $lang['features'] ?></th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php
                                    if (count($record['data']) > 0 && $record['code'] == 1000) {
                                        foreach ($record['data'] as $row) {
                                            ?>
                                            <tr>
                                                <td>
                                                    <?= $row['summary_time'] ?>
                                                </td>
                                                <td class="text-right">
                                                    <?= $row['agent_username'] ?>
                                                </td>
                                                <td class="text-right">
                                                    <span style='color:<?= ($row['game_profit'] < 0) ? 'red' : 'blue' ?>'>
                                                        <?= number_format($row['game_profit'], 4) ?>
                                                    </span>
                                                </td>
                                                <td class="text-right">
                                                    <?= $row['memo'] ?>
                                                </td>
                                                <td class="text-right">
                                                    <?php
                                                    //未結算前才能編輯
                                                    if ($row['settle'] == 0) {
                                                        ?>
                                                        <button name="edit[]" class="btn btn-success"
                                                                data-id="<?= $row['id'] ?>"
                                                                data-summary_time="<?= $row['summary_time'] ?>">
                                                            <?= $lang['edit'] ?>
                                                        </button>
                                                       <?php
                                                        //編輯後才能結算
                                                        if ($row['edit'] == 1) {
                                                            ?>
                                                            <button name="settle[]" class="btn btn-danger"
                                                                    data-agent_id="<?= $row['agent_id'] ?>"
                                                                    data-username="<?= $row['agent_username'] ?>"
                                                                    data-summary_time="<?= $row['summary_time'] ?>">
                                                                <?= $lang['settle'] ?>
                                                            </button>
                                                            <?php
                                                        }
                                                    } else {
                                                        ?>
                                                        <a href="./performance_query.php?start_date=<?= $row['summary_time'] ?>&end_date=<?= $row['summary_time'] ?>&agent_username=<?= $row['agent_username'] ?>&act=1">
                                                            <?= $lang['settlement'] ?>
                                                        </a>
                                                        <?php
                                                    }
                                                    ?>
                                                </td>
                                            </tr>
                                            <?php
                                        }
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
    $(".date-picker").daterangepicker({
        singleDatePicker: true,
        timePicker: false,
        timePicker24Hour: true,
        timePickerIncrement: 59,
        locale: {
            format: 'YYYY-MM-DD'
        }
    });

    $('button[name="settle[]"]').click(function() {
        var agent_id = $(this).attr("data-agent_id");
        var username = $(this).attr("data-username");
        var summary_time = $(this).attr("data-summary_time");
        Swal.fire({
            title: '<?= $lang['settle'] ?> - <?= $lang['are_you_sure'] ?>',
            html: `
        <div><?= $lang['vendor'] ?>:${username}</div>
        <div><?= $lang['date'] ?>:${summary_time}</div>
      `,
            showCancelButton: true,
            confirmButtonText: '<?= $lang['confirm'] ?>',
            cancelButtonText: '<?= $lang['cancel'] ?>',
            showLoaderOnConfirm: true,
            icon: 'warning',
            preConfirm: () => {
                $.ajax({
                    cache: false,
                    dataType: 'json',
                    data: "action=point_barrel_settle" +
                        "&summary_time=" + summary_time +
                        "&agent_id=" + agent_id,
                    url: '/ajax.php',
                    type: 'POST',
                    success: (data) => {
                        if (data == null || data.code != 1000) {
                            Swal.fire(
                                '<?=$lang['settle'] ?>',
                                '<?= $lang['failure'] ?>',
                                'error'
                            ).then(() => {
                                window.location.reload();
                            });
                        } else {
                            Swal.fire(
                                '<?=$lang['settle'] ?>',
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
                            '<?=$lang['settle'] ?>',
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

    $('button[name="edit[]"]').click(function() {
        var id = $(this).attr("data-id");
        var summary_time = $(this).attr("data-summary_time");
        var strCost;
        var strMemo;

        Swal.mixin({
            confirmButtonText: 'Next &rarr;',
            showCancelButton: true,
            progressSteps: ['1', '2']
        }).queue([
            {
                title: summary_time + ' Settlement <?= $lang['cost'] ?>',
                html: `<input type="number" id="swal-cost" class="swal2-input"
onkeyup="javascript:if(this.value > 0) {this.value = this.value * -1;}"
onKeypress="return (/[\\d]/.test(String.fromCharCode(event.keyCode)))" >`,
                preConfirm: function()
                {
                    strCost = $('#swal-cost').val();
                }
            },
            {
                title: summary_time + ' Settlement <?= $lang['memo'] ?>',
                input: 'textarea',
                preConfirm: function(value)
                {
                    strMemo = value;
                }
            },
        ]).then((result) => {
            if (result.value) {
                Swal.fire({
                    title: '<?= $lang['are_you_sure'] ?>',
                    html: `
        <div><?= $lang['cost'] ?>:${strCost}</div>
        <div><?= $lang['memo'] ?>:${strMemo}</div>
      `,
                    showCancelButton: true,
                    confirmButtonText: '<?= $lang['confirm'] ?>',
                    cancelButtonText: '<?= $lang['cancel'] ?>',
                    icon: 'info',
                    preConfirm: () => {
                        $.ajax({
                            cache: false,
                            dataType: 'json',
                            data: "action=point_barrel_set_cost" +
                                "&id=" + id +
                                "&cost=" + strCost +
                                "&memo=" + strMemo,
                            url: '/ajax.php',
                            type: 'POST',
                            success: (data) => {
                                if (data == null || data.code != 1000) {
                                    Swal.fire(
                                        '<?=$lang['ps_cost_setting'] ?>',
                                        '<?= $lang['failure'] ?>',
                                        'error'
                                    ).then(() => {
                                        window.location.reload();
                                    });
                                } else {
                                    Swal.fire(
                                        '<?=$lang['ps_cost_setting'] ?>',
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
                                    '<?=$lang['ps_cost_setting'] ?>',
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
<style>
    input::-webkit-outer-spin-button,
    input::-webkit-inner-spin-button {
        -webkit-appearance: none;
    }
    input[type="number"]{
        -moz-appearance: textfield;
    }
</style>