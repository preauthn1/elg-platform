<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';
require_once '../class/CreatePage.php';

if (!isset($check_auth['point_barrel/share_setting'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

if ($_GET['act'] == 1) {
    $filter = array();
    $filter['previous_agent_username'] = isset($_GET['previous_agent_username']) ? $_GET['previous_agent_username'] : '';
    $filter['agent_username'] = isset($_GET['agent_username']) ? $_GET['agent_username'] : '';
    $filter['page'] = isset($_GET['page']) ? $_GET['page'] : 1;
    $filter['per_page'] = 20;
    $record = curlPost('api/point_barrel/agent', $filter);
    if ($record['code'] >= 9997) {
        header('location:' . BASE_URL . 'login.php');
        exit();
    } else if ($record['code'] != 1000) {
        ?>
        <script>
            alert('<?= $record['message']?>');
        </script>
        <?php
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
                <h4><?= $lang['point_barrel_management'] ?> > <?= $lang['ps_share_setting'] ?></h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <h4 class="card-title"><?= $lang['search'] ?></h4>
                            <form class="form-horizontal mrg-top-40 pdd-right-30">
                                <div class="form-group row">
                                    <?php if ($_SESSION['host_id'] == 1) {
                                        ?>
                                        <label for="form-1-2" class="col-md-2 control-label">
                                            <?= $lang['agent_previous'] ?>
                                        </label>
                                        <div class="col-md-3">
                                            <input type="text" class="form-control" name="previous_agent_username"
                                                   value="<?= $_GET['previous_agent_username'] ?>" />
                                        </div>
                                    <?php
                                    }
                                    ?>
                                    <label for="form-1-2" class="col-md-2 control-label">
                                        <?= $lang['agent_account'] ?>
                                    </label>
                                    <div class="col-md-3">
                                        <input type="text" class="form-control" name="agent_username"
                                               value="<?= $_GET['agent_username'] ?>" />
                                    </div>
                                    <div class="<?= ($_SESSION['host_id'] == 1) ? 'col-md-2' : 'col-md-7' ?>">
                                        <input type="hidden" name="act" value="1" />
                                        <input type="hidden" id="min" value="<?= $record['min'] ?>" />
                                        <input type="hidden" id="max" value="<?= $record['max'] ?>" />
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
                            <?php if ($record['code'] == 1000 && $_SESSION['host_id'] != 1 ) {
                                ?>
                                <div class="row">
                                    <label for="form-1-2" class="col-md-6 control-label">
                                        <h1>
                                            <?= $lang['my_superior'] ?>:
                                            <span class="badge badge-info">
                                                <?= $record['my_superior'] ?>
                                            </span>
                                        </h1>
                                    </label>
                                    <label for="form-1-2" class="col-md-6 control-label">
                                        <h1>
                                            <?= $lang['my_shares'] ?>:
                                            <span class="badge badge-info">
                                                <?= $record['my_shares'] ?>
                                            </span>
                                        </h1>
                                    </label>
                                </div>
                            <?php
                            }
                            ?>
                            <div class="table-overflow">
                                <table class="table table-lg table-hover">
                                    <thead>
                                    <tr>
                                        <th><?= $lang['agent_account'] ?></th>
                                        <th><?= $lang['bucket_ratio'] ?></th>
                                        <th><?= $lang['features']?></th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php
                                    if (count($record['data']) > 0 && $record['code'] == 1000) {
                                        foreach ($record['data'] as $row) {
                                            ?>
                                            <tr>
                                                <td>
                                                    <div class="list-info mrg-top-10">
                                                        <div class="info">
                                                            <span class="title">
                                                                <?php
                                                                if ($_SESSION['host_id'] == 1) {
                                                                    $url = './share_setting.php' .
                                                                        '?previous_agent_username=' . $row['username'] .
                                                                        '&agent_username=&act=1';
                                                                    echo '<a href="' . $url . '" >';
                                                                    echo $row['username'];
                                                                    echo '</a>';
                                                                } else {
                                                                    echo $row['username'];
                                                                }
                                                                ?>
                                                            </span>
                                                            <span class="sub-title">
                                                                <?= $lang['id'] ?> <?=$row['agent_id']?>
                                                            </span>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="list-info mrg-top-10">
                                                        <div class="info">
                                                            <input type="number" class="title" size="5"
                                                                   name="pb_text[]"
                                                                   id="pb_text_<?= $row['agent_id'] ?>"
                                                                   data-agentid="<?= $row['agent_id'] ?>"
                                                                   min="<?= $record['min'] ?>"
                                                                   max="<?= $record['max'] ?>"
                                                                   step="1"
                                                                   onKeypress="return (/[\d]/.test(String.fromCharCode(event.keyCode)))"
                                                                   value="<?= $row['point_share'] ?>" />
                                                            <input type="hidden"
                                                                   id="pb_hidden_text_<?= $row['agent_id'] ?>"
                                                                   value="<?= $row['point_share'] ?>">
                                                            <span class="sub-title">
                                                                <?= $record['min'] ?> ~ <?= $record['max'] ?>
                                                            </span>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div id="btn_group_<?= $row['agent_id'] ?>" class="mrg-top-10 disabledEdit">
                                                        <div class="btn-row">
                                                            <button class="btn btn-success js_Save" style="display:none"
                                                                    name="toggle-save[]"
                                                                    data-agentid="<?= $row['agent_id'] ?>" />
                                                                <?= $lang['btn_update']?>
                                                            </button>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php
                                        }
                                    } else { ?>
                                        <tr>
                                            <td colspan="5" align='center'>
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
    $('input[name="pb_text[]"]').change(function() {
        agent_id = $(this).attr('data-agentid');
        share = parseInt($(this).val());
        hshare = $('#pb_hidden_text_' + agent_id).val();
        min = parseInt($('#min').val());
        max = parseInt($('#max').val());
        $("#btn_group_" + agent_id).find(".js_Save").hide();
        if (min <= share && share <= max && share != hshare) {
            $("#btn_group_" + agent_id).find(".js_Save").show();
        }
    });

    $('button[name="toggle-save[]"]').click(function() {
        id = $(this).attr('data-id');
        agent_id = $(this).attr('data-agentid');
        share = $('#pb_text_' + agent_id).val();
        if (share < <?= $record['min'] ?>) {
            $(this).focus();
            alert('min');
        } else if (share > <?= $record['max'] ?>) {
            $(this).focus();
            alert('max');
        } else {
            $.ajax({
                cache: false,
                dataType: 'json',
                data: "action=pb_toggle_share" +
                    "&id=" + id +
                    "&agent_id=" + agent_id +
                    "&share=" + share,
                url: '/ajax.php',
                type: 'POST',
                success: function(data) {
                    if (data == null || data.code != 1000) {
                        alert(data.message);
                    } else {
                        $('#pb_hidden_text_' + agent_id).val(share);
                        $("#btn_group_" + agent_id).find(".js_Save").hide();
                        successAlert();
                    }
                },
                error: function(err) {
                    alert('error');
                }
            });
        }
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