<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';
if (!isset($check_auth['event/index'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

$disable = '';
$agent_ids = [];

$result = curlPost('api/announcement/getGeneralAgent');
if ($result['code'] != 1000) {
    if ($result['code'] > 9997) {
        header("location:" . BASE_URL . "login.php");
    } else {
        header('Location:' . $_SERVER['HTTP_REFERER']);
    }
    exit();
} else {
    $general_agents = $result['data']['host'];
    $general_agent_child = $result['data']['child'];
}

// 取詳細資料
if ($_GET['id']) {
    $data = array();
    $data['id'] = $_GET['id'];
    $result = curlPost('api/announcement/view', $data);
    if ($result['code'] == 1000) {
        $announcement = $result['data']['announcement'];

        $agent_ids = $result['data']['agent_ids'];
        $select_all = true;
        $all_agents = array_merge($general_agents, $general_agent_child);
        foreach ($all_agents as $row) {
            if(!in_array($row['id'], $agent_ids)){
                $select_all = false;
                break;
            }
        }
    }
} else {
    $select_all = true;
}

?>
<div class="page-container">
    <?php
    require_once '../_top.php';
    require_once '../_theme.php';
    ?>
    <style>
        /*因為會被.form-wizard蓋掉，所以要再設定一次*/
        .pill-info .nav-pills > li > a.active {
            background-color: #0f9aee;
        }
    </style>
    <div class="main-content">
        <div class="container-fluid">
            <div class="page-title">
                <h4>
                    <?= $lang['menu_system_management'] ?> > <?= $lang['menu_backend_announcement'] ?> >
                    <?= isset($_GET['id']) ? $lang['edit'] : $lang['add'] ?><?= $lang['menu_backend_announcement'] ?>
                </h4>
            </div>
            <div class="row">
                <a href="javascript:history.back()"
                   class="btn btn-success btn-inverse btn-rounded">
                    <?= $lang['btn_back'] ?>
                </a>
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="row relative">
                                <form id="shipping-form" class="width-100" method="get">
                                    <div id="rootwizard" class="form-wizard col-md-12 ml-auto mr-auto">
                                        <ul class="nav nav-pills nav-fill">
                                            <li class="nav-item">
                                                <a href="#step1" data-toggle="tab">
                                                    <span class="step">1</span>
                                                    <span class="title"><?= $lang['event_and_content'] ?></span>
                                                </a>
                                            </li>
                                            <li class="nav-item">
                                                <a href="#step2" data-toggle="tab">
                                                    <span class="step">2</span>
                                                    <span class="title"><?= $lang['event_condition_setting'] ?></span>
                                                </a>
                                            </li>
                                        </ul>
                                        <div id="bar" class="progress progress-info" style="width: 75%">
                                            <div class="progress-bar" role="progressbar" aria-valuenow="0"
                                                 aria-valuemin="0" aria-valuemax="100" style="width: 0%;"></div>
                                        </div>
                                        <div class="tab-content">
                                            <?php /*名稱內容*/ ?>
                                            <div id="step1" class="tab-pane fade in active">
                                                <div class="row">
                                                    <div class="col-md-10 mr-auto ml-auto">
                                                        <div class="row">
                                                            <div class="col-md-12">
                                                                <div class="form-group">
                                                                    <label>
                                                                        <span style="color:red">*</span>
                                                                        <?= $lang['choose_announcement_category'] ?>
                                                                    </label>
                                                                    <select class="form-control" data-live-search="true"
                                                                            name="type_id">
                                                                        <option value="1" <?= $announcement['type'] == 1 ? 'selected' : '' ?>>
                                                                            <?= $lang['general_announcement'] ?>
                                                                        </option>
                                                                        <option value="2" <?= $announcement['type'] == 2 ? 'selected' : '' ?>>
                                                                            <?= $lang['vendor_announcement'] ?>
                                                                        </option>
                                                                        <option value="3" <?= $announcement['type'] == 3 ? 'selected' : '' ?>>
                                                                            <?= $lang['emergency_announcement'] ?>
                                                                        </option>
                                                                        <option value="4" <?= $announcement['type'] == 4 ? 'selected' : '' ?>>
                                                                            <?= $lang['important_announcement'] ?>
                                                                        </option>
                                                                    </select>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="tab-content" style="margin-top: 5px">
                                                            <div role="tabpanel"
                                                                 class="tab-pane fade in active"
                                                                 id="default">
                                                                <div class="form-group">
                                                                    <p class="mrg-btm-15 font-size-13">
                                                                        <span style="color:red">*</span>
                                                                        <?= $lang['announcement_content'] ?>
                                                                    </p>
                                                                    <textarea name="content"
                                                                              class="form-control"><?= $announcement['content'] ?></textarea>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php /*設定條件*/ ?>
                                            <div id="step2" class="tab-pane fade">
                                                <div class="row">
                                                    <div class="col-md-10 mr-auto ml-auto">
                                                        <div class="row" id="action_type_deposit">
                                                            <div class="col-md-12">
                                                                <div class="form-group">
                                                                    <label>
                                                                        <span style="color:red">*</span>
                                                                        <?= $lang['choose_agent_announcement'] ?>
                                                                    </label>
                                                                    <div>
                                                                        <fieldset class="form-group border p-3">
                                                                            <legend class="w-auto px-3">
                                                                                <div class="checkbox">
                                                                                    <input id="child_id_0"
                                                                                           name="child_id[]"
                                                                                           type="checkbox"
                                                                                           value="0" <?= $select_all ? 'checked' : '' ?>>
                                                                                    <label for="child_id_0">
                                                                                        <?= $lang['all'] ?>
                                                                                    </label>
                                                                                </div>
                                                                            </legend>
                                                                            <div id="host" class="col-md-12">
                                                                                <?php
                                                                                foreach ($general_agents as $general_agent) { ?>
                                                                                    <div class="row">
                                                                                        <div class="checkbox">
                                                                                            <input id="host_id_<?= $general_agent['id'] ?>"
                                                                                                   name="host_id[]"
                                                                                                   type="checkbox"
                                                                                                   data-id="<?= $general_agent['id'] ?>"
                                                                                                   value="<?= $general_agent['id'] ?>"
                                                                                                <?php
                                                                                                if ($select_all || in_array($general_agent['id'], $agent_ids, false)) {
                                                                                                    echo 'checked';
                                                                                                }
                                                                                                ?>
                                                                                            >
                                                                                            <label for="host_id_<?= $general_agent['id'] ?>">
                                                                                                <b>
                                                                                                    <?= $general_agent['username'] ?>
                                                                                                </b>
                                                                                            </label>
                                                                                        </div>
                                                                                    </div>
                                                                                    <?php if (count($general_agent_child[$general_agent['id']]) > 0) { ?>
                                                                                        <div class="row col-md-12">
                                                                                            <?php foreach ($general_agent_child[$general_agent['id']] as $child) {
                                                                                                ?>
                                                                                                <div class="checkbox col-md-3">
                                                                                                    <input id="child_id_<?= $child['id'] ?>"
                                                                                                           name="child_id[]"
                                                                                                           type="checkbox"
                                                                                                           data-parent="<?= $child['parent'] ?>"
                                                                                                           value="<?= $child['id'] ?>"
                                                                                                        <?php
                                                                                                        if ($select_all || in_array($child['id'], $agent_ids, false)) {
                                                                                                            echo 'checked';
                                                                                                        }
                                                                                                        ?>
                                                                                                    >
                                                                                                    <label for="child_id_<?= $child['id'] ?>">
                                                                                                        <?= $child['username'] ?>
                                                                                                    </label>
                                                                                                </div>
                                                                                                <?php
                                                                                            } ?>
                                                                                        </div>
                                                                                    <?php } ?>
                                                                                <?php } ?>
                                                                            </div>
                                                                        </fieldset>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="wizard-pager">
                                                <div class="">
                                                    <input type="hidden" name="id" value="<?= $_GET['id'] ?>">
                                                    <input type="hidden" name="action"
                                                           value="save_backend_announcement">
                                                    <button type="button"
                                                            class="btn btn-default button-previous btn-rounded">
                                                        <?= $lang['previous'] ?>
                                                    </button>
                                                    <button type="button"
                                                            class="btn btn-primary button-next pull-right btn-rounded">
                                                        <?= $lang['next'] ?>
                                                    </button>
                                                    <button type="button"
                                                            class="btn btn-success pull-right btn-rounded"
                                                            id="save">
                                                        <?= $lang['btn_update'] ?>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php require_once '../_bottom.php'; ?>
</div>
<?php require_once '../_footer.php'; ?>
<script>
    $(document).ready(function () {
        $('.selectize').selectize({
            plugins: ['remove_button'],
            create: false,
            dropdownParent: 'body'
        });

        $('.date-picker').daterangepicker({
            singleDatePicker: true,
            timePicker: true,
            timePicker24Hour: true,
            timePickerIncrement: 1,
            locale: {
                format: 'YYYY-MM-DD HH:mm'
            }
        });

        //儲存資料
        $("#save").click(function (e) {
            if (e.preventDefault) {
                e.preventDefault();
            } else {
                window.event.returnValue == false;
            }

            $.blockUI({
                baseZ: 9000,
                overlayCSS: {
                    backgroundColor: '#444444'
                }
            });
            $.post("<?= BASE_URL ?>ajax.php", $("#shipping-form").serialize(), function (data) {
                $.unblockUI();
                if (data.code == 1000) {
                    history.back();
                } else {
                    if (data.code == '2002') {
                        alert(data.data);
                    } else {
                        alert(data.message);
                    }
                }
            }, 'json');
        });

        $('input[name="child_id[]"]').on('click', function (event) {
            var check = $(this).prop("checked");
            var parent = $(this).attr("data-parent");
            var val = $(this).val();
            if (val == 0) {
                $('input[name="host_id[]"]').each(function (index) {
                    $(this).prop('checked', check);
                });
                $('input[name="child_id[]"]').each(function (index) {
                    $(this).prop('checked', check);
                });
            } else {
                var check_group = true;
                $('input[data-parent="' + parent + '"]').each(function (index) {
                    if ($(this).prop("checked") == false) {
                        check_group = false;
                        $("#host_id_0").prop('checked', check_group);
                    }
                });
                // 子帳號取消勾選,主帳號不取消
                // $('input[data-id="' + parent + '"]').prop('checked', check_group);
            }
        });

        $('input[name="host_id[]"]').on('click', function (event) {
            var checked = $(this).prop("checked");
            $('input[data-parent="' + $(this).attr("data-id") + '"]').prop('checked', checked);
            if (checked == false) {
                $("#host_id_0").prop('checked', checked);
            }
        });

        action_type_deposit();
    });

    function action_type_deposit() {
        $("#host_id_0").prop('checked', true);
        // $('input[name="host_id[]"]').each(function (index) {
        //     $(this).prop('checked', true);
        // });
        $('input[name="child_id[]"]').each(function (index) {
            if ($(this).prop("checked") == false) {
                $("#host_id_0").prop('checked', false);
                $('input[data-id="' + $(this).attr("data-parent") + '"]').prop('checked', false);
            }
        });
    }

    // function disable_deposit() {
    //     $('input[name="cf_id[]"]').attr('disabled', 'disabled');
    //     $('input[name="order_type_id[]"]').attr('disabled', 'disabled');
    //     $("#order_type_id_0").attr('disabled', false);
    //     $("#order_type").addClass("disabledStyle");
    // }
    //
    // function enable_deposit() {
    //     $('input[name="cf_id[]"]').attr('disabled', false);
    //     $('input[name="order_type_id[]"]').attr('disabled', false);
    //     $("#order_type").removeClass('disabledStyle');
    // }
</script>