<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';

//確認權限
if (!isset($check_auth['loginevent/index'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

// 取詳細資料
if ($_GET['id']) {
    $data = array();
    $data['id'] = $_GET['id'];
    $result = curlPost('api/loginEvent/view', $data);
    if ($result['code'] == 1000) {
        $event = $result['data'];
        $language_data = array();
        foreach ($event['names'] as $row) {
            $language_data[$row['language_id']] = $row;
        }

        $use_vendors = explode(',', $event['vendor_id']);
    }
}
$start_date = date('Y-m-d 00:00');
$end_date = date('Y-m-d 23:59', strtotime('+1month'));

// 活動類型
$data = array();
$data['key'] = 'login_event_type';
$result = curlPost('api/param/list', $data);
if ($result['code'] != 1000) {
    if ($result['code'] > 9997) {
        header("location:" . BASE_URL . "login.php");
    } else {
        header('Location:' . $_SERVER['HTTP_REFERER']);
    }
    exit();
} else {
    $types = $result['data'];
}

// 語系列表
$data = array();
$result = curlPost('api/languages', $data);
if ($result['code'] != 1000) {
    if ($result['code'] > 9997) {
        header("location:" . BASE_URL . "login.php");
    } else {
        header('Location:' . $_SERVER['HTTP_REFERER']);
    }
    exit();
} else {
    $languages = $result['data'];
}

// 遊戲商列表
$vendors = curlPost('api/vendor/list');

// 營運商列表
$data = array();
$data['include_sub_account'] = 0;
$data['include_self'] = 1;
$data['page'] = 1;
$data['per_page'] = 10000;
$result = curlPost('api/agent/list', $data);
if ($result['code'] != 1000) {
    if ($result['code'] > 9997) {
        header("location:" . BASE_URL . "login.php");
    } else {
        header('Location:' . $_SERVER['HTTP_REFERER']);
    }
    exit();
} else {
    $agents = $result['data'];
}
?>
<div class="page-container">
    <?php
    require_once '../_top.php';
    require_once '../_theme.php';
    ?>
    <style>
        /*因為會被.form-wizard蓋掉，所以要再設定一次*/
        .pill-info .nav-pills>li>a.active {
            background-color: #0f9aee;
        }
    </style>
    <div class="main-content">
        <div class="container-fluid">
            <div class="page-title">
                <h4>
                    <?= $lang['event_exclusive_offers'] ?> > <?= $lang['event_login_management'] ?> >
                    <?= isset($_GET['id']) ? $lang['edit'] : $lang['add'] ?><?= $lang['event'] ?>
                </h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="row relative">
                                <form id="shipping-form" class="width-100">
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
                                            <div id="step1" class="tab-pane fade in active">
                                                <div class="row">
                                                    <div class="col-md-10 mr-auto ml-auto">
                                                        <div class="card">
                                                            <div class="card-block">
                                                                <div class="pill-info nav-vertical nav-stacked">
                                                                    <ul class="nav nav-pills" role="tablist">
                                                                        <li class="nav-item">
                                                                            <a href="#default" class="nav-link active"
                                                                               role="tab"
                                                                               data-toggle="tab"><?= $lang['default'] ?></a>
                                                                        </li>
                                                                        <?php foreach ($languages as $key => $value) { ?>
                                                                            <li class="nav-item">
                                                                                <a href="#pills-vertical-<?= $value['id'] ?>"
                                                                                   class="nav-link" role="tab"
                                                                                   data-toggle="tab"><?= $value['name'] ?></a>
                                                                            </li>
                                                                        <?php } ?>
                                                                    </ul>
                                                                    <div class="tab-content" style="margin-top: 5px">
                                                                        <div role="tabpanel"
                                                                             class="tab-pane fade in active"
                                                                             id="default">
                                                                            <div class="form-group">
                                                                                <p class="mrg-btm-15 font-size-13">
                                                                                    <span style="color:red">*</span>
                                                                                    <?= $lang['event_name'] ?>
                                                                                </p>
                                                                                <input type="text" class="form-control"
                                                                                       placeholder="" name="title"
                                                                                       value="<?= $event['title'] ?>">
                                                                            </div>
                                                                            <div class="form-group">
                                                                                <p class="mrg-btm-15 font-size-13">
                                                                                    <span style="color:red">*</span>
                                                                                    <?= $lang['event_content'] ?>
                                                                                </p>
                                                                                <textarea name="content" class="form-control"><?= $event['content'] ?></textarea>
                                                                            </div>
                                                                        </div>
                                                                        <?php foreach ($languages as $key => $value) { ?>
                                                                            <div role="tabpanel" class="tab-pane fade"
                                                                                 id="pills-vertical-<?= $value['id'] ?>">
                                                                                <div class="form-group">
                                                                                    <p class="mrg-btm-15 font-size-13">
                                                                                        <?= $lang['event_name'] ?>
                                                                                    </p>
                                                                                    <input type="text" class="form-control"
                                                                                           placeholder=""
                                                                                           name="titles[<?= $value['id'] ?>]"
                                                                                           value="<?= $language_data[$value['id']]['title'] ?>">
                                                                                </div>
                                                                                <div class="form-group">
                                                                                    <p class="mrg-btm-15 font-size-13">
                                                                                        <?= $lang['event_content'] ?>
                                                                                    </p>
                                                                                    <textarea class="form-control" name="contents[<?= $value['id'] ?>]"><?= $language_data[$value['id']]['content'] ?></textarea>
                                                                                </div>
                                                                            </div>
                                                                        <?php } ?>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div id="step2" class="tab-pane fade">
                                                <div class="row">
                                                    <div class="col-md-10 mr-auto ml-auto">
                                                        <div class="row">
                                                            <div class="col-md-4">
                                                                <div class="form-group">
                                                                    <label>
                                                                        <span style="color:red">*</span><?= $lang['choose_event_category'] ?>
                                                                    </label>
                                                                    <select class="form-control" id="type" name="type">
                                                                        <option value=""><?= $lang['please_choose'] ?></option>
                                                                        <?php
                                                                        $type_array = [];
                                                                        foreach ($types as $type) {
                                                                            $type_array[$type['value']] = $type['parameter'];
                                                                            ?>
                                                                            <option value="<?= $type['value'] ?>" <?= ($event['type'] == $type['value']) ? 'selected' : '' ?>>
                                                                                <?= $lang['login_event_type.' . $type['value']] ?>
                                                                            </option>
                                                                            <?php
                                                                        }
                                                                        ?>
                                                                    </select>
                                                                </div>
                                                            </div>
                                                            <?php /*選擇活動區間*/ ?>
                                                            <div class="col-md-4">
                                                                <div class="form-group">
                                                                    <label>
                                                                        <span style="color:red">*</span><?= $lang['label_start_time'] ?>
                                                                    </label>
                                                                    <input type="text" class="form-control date-picker" name="start_time"
                                                                           value="<?= $event['start_time'] ? $event['start_time'] : date('Y-m-d 00:00', strtotime('-8hour')) ?>" />
                                                                </div>
                                                            </div>
                                                            <div class="col-md-4">
                                                                <div class="form-group">
                                                                    <label>
                                                                        <span style="color:red">*</span><?= $lang['label_end_time'] ?>
                                                                    </label>
                                                                    <input type="text" class="form-control date-picker" name="end_time"
                                                                           value="<?= $event['end_time'] ? $event['end_time'] : date('Y-m-d 23:59', strtotime('+16hour')) ?>" />
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="row">
                                                            <div class="col-md-4">
                                                                <div class="form-group">
                                                                    <label>
                                                                        <span style="color:red">*</span><?= $lang['not_withdraw_record'] ?>
                                                                    </label>
                                                                    <input type="number" class="form-control allow_numeric" id="how_days" name="how_days"
                                                                           value="<?= $event['how_days'] ?>" />
                                                                </div>
                                                            </div>
                                                            <div class="col-md-4">
                                                                <div class="form-group">
                                                                    <label>
                                                                        <span style="color:red">*</span><?= $lang['trial_amount'] ?>
                                                                    </label>
                                                                    <input type="number" id="point" name="point" class="form-control" value="<?= $event['point'] ?>" />
                                                                </div>
                                                            </div>
                                                            <div class="col-md-4">
                                                                <div class="form-group">
                                                                    <label>
                                                                        <span style="color:red">*</span><?= $lang['unlock_amount'] ?>
                                                                    </label>
                                                                    <input type="number" id="times" name="times" class="form-control" value="<?= $event['times'] ?>" />
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="row">
                                                            <div class="col-md-4">
                                                                <div class="form-group">
                                                                    <label>
                                                                        <span style="color:red">*</span><?= $lang['label_number_of_participants'] ?>
                                                                    </label>
                                                                    <input type="number" id="repeat_hour" name="repeat_hour"
                                                                               class="form-control" value="<?= $event['repeat_hour'] ?>" />
                                                                </div>
                                                            </div>
                                                            <div class="col-md-4">
                                                                <div class="form-group">
                                                                    <label>
                                                                        <span style="color:red">*</span><?= $lang['label_the_number_of_participants'] ?>
                                                                    </label>
                                                                    <input type="number" id="number_of_people" name="number_of_people"
                                                                               class="form-control" value="<?= $event['number_of_people'] ?>" />
                                                                </div>
                                                            </div>
                                                            <div class="col-md-4">
                                                                <div class="form-group">
                                                                    <label>
                                                                        <span style="color:red">*</span><?= $lang['status'] ?>
                                                                    </label>
                                                                    <div class="col-md-10">
                                                                        <div class="radio radio-inline">
                                                                            <input type="radio"
                                                                                   name="status" value="1"
                                                                                   id="r1"
                                                                                <?= ($event['status'] == 1 || !isset($event['status'])) ? 'checked' : '' ?>>
                                                                            <label for="r1">
                                                                                <?= $lang['enable'] ?>
                                                                            </label>
                                                                        </div>
                                                                        <div class="radio radio-inline">
                                                                            <input type="radio"
                                                                                   name="status" value="0"
                                                                                   id="r2"
                                                                                <?= ($event['status'] == '0') ? 'checked' : '' ?>>
                                                                            <label for="r2">
                                                                                <?= $lang['disable'] ?>
                                                                            </label>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="row">
                                                            <div class="col-md-12">
                                                                <div class="form-group">
                                                                    <label>
                                                                        <span style="color:red">*</span><?= $lang['trial_vendor'] ?>
                                                                    </label>
                                                                    <select class="selectize" name="vendor_id[]" multiple="multiple">
                                                                        <?php foreach ($vendors['data'] as $row) { ?>
                                                                            <option value="<?= $row['id'] ?>"
                                                                                <?= (in_array($row['id'], $use_vendors)) ? 'selected' :'' ?>>
                                                                                <?= $row['name'] ?>&nbsp;
                                                                            </option>
                                                                        <?php } ?>
                                                                    </select>
                                                                    <input type="hidden" id="vendor_id" name="vendor_id" value="" />
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="wizard-pager">
                                                <div class="">
                                                    <input type="hidden" name="id" value="<?= $_GET['id'] ?>">
                                                    <input type="hidden" name="action" value="save_login_event">
                                                    <button type="button" class="btn btn-default button-previous btn-rounded">
                                                        <?= $lang['previous'] ?>
                                                    </button>
                                                    <button type="button" class="btn btn-primary button-next pull-right btn-rounded">
                                                        <?= $lang['next'] ?>
                                                    </button>
                                                    <button type="button" class="btn btn-success pull-right btn-rounded"
                                                            id="save">
                                                        <?= $lang['btn_update'] ?>
                                                    </button>
                                                </div>
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
    $(document).ready(function() {
        $('.date-picker').daterangepicker({
            singleDatePicker: true,
            timePicker: true,
            timePicker24Hour: true,
            timePickerIncrement: 1,
            locale: {
                format: 'YYYY-MM-DD HH:mm'
            }
        });

        $('.selectize').selectize({
            plugins: ['remove_button'],
            create: false,
            dropdownParent: 'body'
        });


        $('#action_id').change(function(e) {
            var action = $('#action_id').val();
            if (action == 1 || action == 4) {
                //儲值
                $('#order_type_id').attr('disabled', false);
                $('#vendor_id').attr('disabled', true);
                $('#vendor_id option').eq(0).prop('selected', true);
                $('#game_type_id').attr('disabled', true);
                $('#game_type_id option').eq(0).prop('selected', true);
            } else {
                //遊戲
                $('#order_type_id').attr('disabled', true);
                $('#order_type_id option').eq(0).prop('selected', true);
                $('#vendor_id').attr('disabled', false);
                $('#game_type_id').attr('disabled', false);
            }
        })

        //儲存資料
        $("#save").click(function(e) {
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

            var vendor_id = $("[name='vendor_id[]']").val();
            if (vendor_id != null) {
                vendor_id = vendor_id.toString();
            }
            $('#vendor_id').val(vendor_id);
            $.post("<?= BASE_URL ?>ajax.php", $("#shipping-form").serialize(), function(data) {
                $.unblockUI();
                if (data.code == 1000) {
                    alert(data.message);
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
    });
</script>