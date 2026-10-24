<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';
if (!isset($check_auth['event/index'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

$event_started = false;
$disable = '';
// 取詳細資料
if ($_GET['id']) {
    $data = array();
    $data['id'] = $_GET['id'];
    $result = curlPost('api/event/view', $data);
    if ($result['code'] == 1000) {
        $event = $result['data'];

        $detail = $event['details'][0];
        $detail['order_type_id'] = array();
        $select_all = false;
        foreach ($event['details'] as $row) {
            if ($row['order_type_id'] == 0) $select_all = true;
            $detail['order_type_id'][] = $row['order_type_id'];
        }
        $language_data = array();
        foreach ($event['languages'] as $row) {
            $language_data[$row['language_id']] = $row;
        }
        //活動已開始, 無法修改
        if ($detail['start_time'] < date('Y-m-d H:i:s')) {
            $event_started = true;
            $disable = 'disabled';
        }
    }
} else {
    $select_all = true;
}
$start_time = isset($detail['start_time']) ? $detail['start_time'] : date('Y-m-d 00:00');
$end_time = isset($detail['end_time']) ? $detail['end_time'] : date('Y-m-d 23:59', strtotime('+1month'));
$prize_expire_date =  $event['prize_expire_date']
    ? substr($event['prize_expire_date'], 0, 16)
    : date('Y-m-d 23:59', strtotime('+1month'));
$prize_draw_date =  $event['prize_draw_date']
    ? substr($event['prize_draw_date'], 0, 16)
    : date('Y-m-d 23:59', strtotime('+1month'));

// 活動類型
$data = array();
$data['key'] = 'event_type';
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

// 行為列表
$data = array();
$data['key'] = 'event_action';
$result = curlPost('api/param/list', $data);
if ($result['code'] != 1000) {
    if ($result['code'] > 9997) {
        header("location:" . BASE_URL . "login.php");
    } else {
        header('Location:' . $_SERVER['HTTP_REFERER']);
    }
    exit();
} else {
    $actions = $result['data'];
}

// 儲值方式列表
$result = curlPost('api/orderType/list');
if ($result['code'] != 1000) {
    if ($result['code'] > 9997) {
        header("location:" . BASE_URL . "login.php");
    } else {
        header('Location:' . $_SERVER['HTTP_REFERER']);
    }
    exit();
} else {
    $order_types = $result['data'];
}

// 遊戲商列表
$result = curlPost('api/vendor/list');
if ($result['code'] != 1000) {
    if ($result['code'] > 9997) {
        header("location:" . BASE_URL . "login.php");
    } else {
        header('Location:' . $_SERVER['HTTP_REFERER']);
    }
    exit();
} else {
    $vendors = $result['data'];
}
// 遊戲類型列表
$result = curlPost('api/gameType/list');
if ($result['code'] != 1000) {
    if ($result['code'] > 9997) {
        header("location:" . BASE_URL . "login.php");
    } else {
        header('Location:' . $_SERVER['HTTP_REFERER']);
    }
    exit();
} else {
    $game_types = $result['data'];
}

// 獎勵方式列表
$filter = array();
$filter['status'] = 1;
$result = curlPost('api/prizeType/list', $filter);
if ($result['code'] != 1000) {
    if ($result['code'] > 9997) {
        header("location:" . BASE_URL . "login.php");
    } else {
        header('Location:' . $_SERVER['HTTP_REFERER']);
    }
    exit();
} else {
    $prize_types = $result['data'];
}

// 營運商列表
$result = curlPost('api/agent/dropdownList');
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
                    <?= $lang['event_exclusive_offers'] ?> > <?= $lang['event_management'] ?> >
                    <?= isset($_GET['id']) ? $lang['edit'] : $lang['add'] ?><?= $lang['event'] ?>
                </h4>
            </div>
            <div class="row">
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
                                            <li class="nav-item">
                                                <a href="#step3" data-toggle="tab">
                                                    <span class="step">3</span>
                                                    <span class="title"><?= $lang['event_reward_setting'] ?></span>
                                                </a>
                                            </li>
                                            <li class="nav-item">
                                                <a href="#step4" data-toggle="tab">
                                                    <span class="step">4</span>
                                                    <span class="title"><?= $lang['event_reward_content_setting'] ?></span>
                                                </a>
                                            </li>
                                            <li class="nav-item">
                                                <a href="#step5" data-toggle="tab">
                                                    <span class="step">5</span>
                                                    <span class="title"><?= $lang['flow_limit'] ?></span>
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
                                                                        <?= $lang['event_choose_event_category'] ?>
                                                                    </label>
                                                                    <select class="form-control" <?= $disable ?> data-live-search="true" name="type_id">
                                                                        <?php foreach ($types as $type) { ?>
                                                                            <option value="<?= $type['value'] ?>"
                                                                                <?= ($type['value'] == $event['type_id']) ? 'selected' : '' ?>>
                                                                                <?= $lang['event_type.' . $type['parameter']] ?>
                                                                            </option>
                                                                        <?php } ?>
                                                                    </select>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="card">
                                                            <div class="card-block">
                                                                <div class="pill-info nav-vertical nav-stacked">
                                                                    <ul class="nav nav-pills" role="tablist">
                                                                        <li class="nav-item">
                                                                            <a href="#default" class="nav-link active"
                                                                               role="tab"
                                                                               data-toggle="tab"><?= $lang['default'] ?></a>
                                                                        </li>
                                                                        <?php
                                                                        foreach ($languages as $key => $value) {
                                                                            ?>
                                                                            <li class="nav-item">
                                                                                <a href="#pills-vertical-<?= $value['id'] ?>"
                                                                                   class="nav-link" role="tab"
                                                                                   data-toggle="tab"><?= $value['name'] ?></a>
                                                                            </li>
                                                                            <?php
                                                                        }
                                                                        ?>
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
                                                                                       name="title" <?= $disable ?>
                                                                                       value="<?= $event['title'] ?>">
                                                                            </div>
                                                                            <div class="form-group">
                                                                                <p class="mrg-btm-15 font-size-13">
                                                                                    <span style="color:red">*</span>
                                                                                    <?= $lang['event_content'] ?>
                                                                                </p>
                                                                                <textarea name="content" <?= $disable ?> class="form-control"><?= $event['content'] ?></textarea>
                                                                            </div>
                                                                        </div>
                                                                        <?php
                                                                        foreach ($languages as $key => $value) {
                                                                            ?>
                                                                            <div role="tabpanel" class="tab-pane fade"
                                                                                 id="pills-vertical-<?= $value['id'] ?>">
                                                                                <div class="form-group">
                                                                                    <p class="mrg-btm-15 font-size-13">
                                                                                        <?= $lang['event_name'] ?>
                                                                                    </p>
                                                                                    <input type="text" class="form-control" <?= $disable ?>
                                                                                           name="titles[<?= $value['id'] ?>]"
                                                                                           value="<?= $language_data[$value['id']]['title'] ?>">
                                                                                </div>
                                                                                <div class="form-group">
                                                                                    <p class="mrg-btm-15 font-size-13">
                                                                                        <?= $lang['event_content'] ?>
                                                                                    </p>
                                                                                    <textarea class="form-control" <?= $disable ?> name="contents[<?= $value['id'] ?>]"><?= $language_data[$value['id']]['content'] ?></textarea>
                                                                                </div>
                                                                            </div>
                                                                            <?php
                                                                        }
                                                                        ?>
                                                                    </div>
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
                                                        <div class="row">
                                                            <div class="col-md-6">
                                                                <div class="form-group">
                                                                    <input type="hidden" name="detail_id"
                                                                           value="<?= $detail['id'] ?>" />
                                                                    <label>
                                                                        <span style="color:red">*</span>
                                                                        <?= $lang['choose_event_category'] ?>
                                                                    </label>
                                                                    <select class="form-control" <?= $disable ?> id="action_id" name="action_id">
                                                                        <option value="">
                                                                            <?= $lang['please_choose'] ?>
                                                                        </option>
                                                                        <?php
                                                                        $action_array = [];
                                                                        foreach ($actions as $action) {
                                                                            $action_array[$action['value']] = $action['parameter'];
                                                                            ?>
                                                                            <option value="<?= $action['value'] ?>"
                                                                                <?= ($action['value'] == $detail['action_id']) ? 'selected' : '' ?>>
                                                                                <?= $lang['event_action.' . $action['parameter']] ?>
                                                                            </option>
                                                                            <?php
                                                                        }
                                                                        ?>
                                                                    </select>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-3">
                                                                <div class="form-group">
                                                                    <label>
                                                                        <span style="color:red">*</span>
                                                                        <?= $lang['label_start_time'] ?>
                                                                    </label>
                                                                    <input type="text" class="form-control date-picker"
                                                                           id="start_time" name="start_time" <?= $disable ?>
                                                                           value="<?= $start_time ?>" />
                                                                </div>
                                                            </div>
                                                            <div class="col-md-3">
                                                                <div class="form-group">
                                                                    <label>
                                                                        <span style="color:red">*</span>
                                                                        <?= $lang['label_end_time'] ?>
                                                                    </label>
                                                                    <input type="text" class="form-control date-picker"
                                                                           id="end_time" name="end_time" <?= $disable ?>
                                                                           value="<?= $end_time ?>" />
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="row" id="event_reward_points">
                                                            <div class="col-md-6">
                                                                <div class="form-group">
                                                                    <label>
                                                                        <span style="color:red">*</span>
                                                                        <?= $lang['event_reward_points'] ?>
                                                                    </label>
                                                                    <input type="text" class="form-control allow_numeric" <?= $disable ?>
                                                                           id="point" name="point" value="<?= $detail['point'] ?>"/>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="row" id="action_type_deposit">
                                                            <div class="col-md-12">
                                                                <div class="form-group">
                                                                    <label>
                                                                        <span style="color:red">*</span>
                                                                        <?= $lang['choose_deposit_category'] ?>
                                                                    </label>
                                                                    <div>
                                                                        <fieldset class="form-group border p-3">
                                                                            <legend class="w-auto px-3">
                                                                                <div class="checkbox">
                                                                                    <input id="order_type_id_0" name="order_type_id[]"
                                                                                           type="checkbox" <?= $disable ?>
                                                                                           value="0" <?= (in_array(0, $detail['order_type_id'])) ? 'checked' : '' ?>>
                                                                                    <label for="order_type_id_0">
                                                                                        <?= $lang['all'] ?>
                                                                                    </label>
                                                                                </div>
                                                                            </legend>
                                                                            <div id="order_type">
                                                                                <?php
                                                                                $cf_id = 0;
                                                                                $type_array = [];
                                                                                foreach ($order_types as $order_type) {
                                                                                $type_array[$order_type['id']] = $order_type['name'];
                                                                                if ($cf_id != 0 && $cf_id != $order_type['cf_id']) { ?>
                                                                            </div>
                                                                            <?php }
                                                                            if ($cf_id != $order_type['cf_id']) {
                                                                            $cf_id = $order_type['cf_id']; ?>
                                                                            <div class="row">　
                                                                                <div class="checkbox">
                                                                                    <input id="cf_id_<?= $cf_id ?>" name="cf_id[]" type="checkbox"
                                                                                           data-id="<?= $cf_id ?>" <?= $disable ?>
                                                                                           value="<?= $cf_id ?>">
                                                                                    <label for="cf_id_<?= $cf_id ?>">
                                                                                        <b>
                                                                                            <?= $order_type['cf_name'] ?>
                                                                                        </b>
                                                                                    </label>
                                                                                </div>
                                                                            </div>
                                                                            <div class="row">
                                                                                <?php } ?>
                                                                                <div class="checkbox col-md-3">　　
                                                                                    <input id="order_type_id_<?= $order_type['id'] ?>" name="order_type_id[]" type="checkbox"
                                                                                           data-parent="<?= $order_type['cf_id'] ?>"
                                                                                           value="<?= $order_type['id'] ?>" <?= $disable ?>
                                                                                        <?= (in_array($order_type['id'], $detail['order_type_id']) || $select_all) ? 'checked' : '' ?>>
                                                                                    <label for="order_type_id_<?= $order_type['id'] ?>">
                                                                                        <?= $order_type['name'] ?>
                                                                                    </label>
                                                                                </div>
                                                                                <?php } ?>
                                                                            </div>
                                                                            <div class="row">　
                                                                                <div class="checkbox">
                                                                                    <input id="cf_id_other" name="cf_id[]" type="checkbox"
                                                                                           data-id="-1" <?= $disable ?>
                                                                                           value="<?= $order_type['id'] ?>">
                                                                                    <label for="cf_id_other">
                                                                                        <b><?= $lang['other'] ?></b>
                                                                                    </label>
                                                                                </div>
                                                                            </div>
                                                                            <div class="row">
                                                                                <div class="checkbox col-md-3">　　
                                                                                    <input id="order_type_id_agent" name="order_type_id[]" type="checkbox"
                                                                                           data-parent="-1" <?= $disable ?>
                                                                                           value="-2"
                                                                                        <?= (in_array(-2, $detail['order_type_id']) || $select_all) ? 'checked' : '' ?>>
                                                                                    <label for="order_type_id_agent">
                                                                                        <?= $lang['agent_deposit'] ?>
                                                                                    </label>
                                                                                </div>
                                                                                <div class="checkbox col-md-3">　　
                                                                                    <input id="order_type_id_cs" name="order_type_id[]" type="checkbox"
                                                                                           data-parent="-1" <?= $disable ?>
                                                                                           value="-3"
                                                                                        <?= (in_array(-3, $detail['order_type_id']) || $select_all) ? 'checked' : '' ?>>
                                                                                    <label for="order_type_id_cs">
                                                                                        <?= $lang['cs_deposit'] ?>
                                                                                    </label>
                                                                                </div>
                                                                            </div>
                                                                    </div>
                                                                    </fieldset>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="row" id="action_type_game">
                                                        <div class="col-md-6">
                                                            <div class="form-group">
                                                                <label>
                                                                    <span style="color:red">*</span>
                                                                    <?= $lang['choose_agents'] ?>
                                                                </label>
                                                                <select class="form-control" <?= $disable ?> id="vendor_id" name="vendor_id">
                                                                    <option value="">
                                                                        <?= $lang['please_choose'] ?>
                                                                    </option>
                                                                    <?php
                                                                    $vendor_array = [];
                                                                    foreach ($vendors as $vendor) {
                                                                        $vendor_array[$vendor['id']] = $vendor['name'];
                                                                        ?>
                                                                        <option value="<?= $vendor['id'] ?>"
                                                                            <?= ($vendor['id'] == $detail['vendor_id']) ? 'selected' : '' ?>>
                                                                            <?= $vendor['name'] ?>
                                                                        </option>
                                                                        <?php
                                                                    }
                                                                    ?>
                                                                </select>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <div class="form-group">
                                                                <label>
                                                                    <span style="color:red">*</span>
                                                                    <?= $lang['choose_game_types'] ?>
                                                                </label>
                                                                <select class="form-control" <?= $disable ?> id="game_type_id" name="game_type_id">
                                                                    <option value="">
                                                                        <?= $lang['please_choose'] ?>
                                                                    </option>
                                                                    <?php
                                                                    $game_type_array = [];
                                                                    foreach ($game_types as $game_type) {
                                                                        $game_type_array[$game_type['id']] = $game_type['name'];
                                                                        ?>
                                                                        <option value="<?= $game_type['id'] ?>"
                                                                            <?= ($game_type['id'] == $detail['game_type_id']) ? 'selected' : '' ?>>
                                                                            <?= $game_type['name'] ?>
                                                                        </option>
                                                                        <?php
                                                                    }
                                                                    ?>
                                                                </select>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="row" id="group_unlimited">
                                                        <div class="col-md-6">
                                                            <div class="form-group">
                                                                <label>
                                                                    <span style="color:red">*</span>
                                                                    <?= $lang['bind_unlimited'] ?>
                                                                </label>
                                                                <div class="radio radio-inline">
                                                                    <input type="radio"
                                                                           name="bind_unlimited" value="1"
                                                                           id="u1" <?= $disable ?>
                                                                        <?= ($detail['bind_unlimited'] == '1') ? 'checked' : '' ?>>
                                                                    <label for="u1">
                                                                        <?= $lang['option_yes'] ?>
                                                                    </label>
                                                                </div>
                                                                <div class="radio radio-inline">
                                                                    <input type="radio"
                                                                           name="bind_unlimited" value="0"
                                                                           id="u2" <?= $disable ?>
                                                                        <?= ($detail['bind_unlimited'] == '0' || !$_GET['id']) ? 'checked' : '' ?> >
                                                                    <label for="u2">
                                                                        <?= $lang['option_no'] ?>
                                                                    </label>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php /*設定獎勵*/ ?>
                                        <div id="step3" class="tab-pane fade">
                                            <div class="row">
                                                <div class="col-md-10 mr-auto ml-auto">
                                                    <div class="row">
                                                        <div class="col-md-6">
                                                            <div class="form-group">
                                                                <label>
                                                                    <span style="color:red">*</span>
                                                                    <?= $lang['reward_method'] ?>
                                                                </label>
                                                                <select class="form-control" data-live-search="true" <?= $disable ?>
                                                                        name="prize_type_id">
                                                                    <?php if (count($prize_types) > 1) { ?>
                                                                        <option value="">
                                                                            <?= $lang['please_choose'] ?>
                                                                        </option>
                                                                        <?php
                                                                    }
                                                                    foreach ($prize_types as $prize_type) {
                                                                        ?>
                                                                        <option value="<?= $prize_type['id'] ?>"
                                                                            <?= ($prize_type['id'] == $event['prize_type_id']) ? 'selected' : '' ?>>
                                                                            <?= $prize_type['name'] ?>
                                                                        </option>
                                                                        <?php
                                                                        }
                                                                        ?>
                                                                    </select>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <div class="form-group">
                                                                    <label>
                                                                        <span style="color:red">*</span>
                                                                        <?= $lang['bonus_game_name'] ?>
                                                                    </label>
                                                                    <input type="text" class="form-control" <?= $disable ?>
                                                                        name="prize_name"
                                                                        value="<?= $event['prize_name'] ?>" />
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="row">
                                                            <div class="col-md-4">
                                                                <div class="form-group">
                                                                    <label>
                                                                        <span style="color:red">*</span>
                                                                        <?= $lang['available_multiple_get'] ?>
                                                                    </label>
                                                                    <div class="col-md-10">
                                                                        <div id="r0">
                                                                            <div class="radio radio-inline">
                                                                                <input type="radio" <?= $disable ?>
                                                                                       name="prize_is_repeatedly" value="1"
                                                                                       id="r1"
                                                                                    <?= ($event['prize_is_repeatedly'] == 1 || !isset($event['prize_is_repeatedly'])) ? 'checked' : '' ?>>
                                                                                <label for="r1">
                                                                                    <?= $lang['available_multiple_can'] ?>
                                                                                </label>
                                                                            </div>
                                                                            <div class="radio radio-inline">
                                                                                <input type="radio" <?= $disable ?>
                                                                                       name="prize_is_repeatedly" value="0"
                                                                                       id="r2"
                                                                                    <?= ($event['prize_is_repeatedly'] == '0') ? 'checked' : '' ?>>
                                                                                <label for="r2">
                                                                                    <?= $lang['available_not_multiple'] ?>
                                                                                </label>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-4">
                                                                <div class="form-group">
                                                                    <label>
                                                                        <span style="color:red">*</span>
                                                                        <?= $lang['event_deadline'] ?>
                                                                    </label>
                                                                    <input type="text" id="date-picker"
                                                                        class="form-control date-picker"
                                                                        name="prize_expire_date" <?= $disable ?>
                                                                        value="<?= $prize_expire_date ?>" />
                                                                </div>
                                                            </div>
                                                            <div class="col-md-4">
                                                                <div class="form-group">
                                                                    <label>
                                                                        <span style="color:red">*</span>
                                                                        <?= $lang['event_draw_date'] ?>
                                                                    </label>
                                                                    <input type="text" id="date-picker"
                                                                        class="form-control date-picker"
                                                                        name="prize_draw_date" <?= $disable ?>
                                                                        value="<?= $prize_draw_date ?>" />
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <div class="row" id="group_prize_withdrawal_cancel_reward">
                                                        <div class="col-md-6">
                                                            <div class="form-group">
                                                                <label>
                                                                    <span style="color:red">*</span>
                                                                    <?= $lang['cancel_deposit_reward'] ?>
                                                                </label>
                                                                <div class="radio radio-inline">
                                                                    <input type="radio"
                                                                           name="prize_withdrawal_cancel_reward" value="1"
                                                                           id="pwcr1" <?= $disable ?>
                                                                        <?= ($event['prize_withdrawal_cancel_reward'] == '1') ? 'checked' : '' ?>>
                                                                    <label for="pwcr1">
                                                                        <?= $lang['option_yes'] ?>
                                                                    </label>
                                                                </div>
                                                                <div class="radio radio-inline">
                                                                    <input type="radio"
                                                                           name="prize_withdrawal_cancel_reward" value="0"
                                                                           id="pwcr2" <?= $disable ?>
                                                                        <?= ($event['prize_withdrawal_cancel_reward'] == '0' || !$_GET['id']) ? 'checked' : '' ?> >
                                                                    <label for="pwcr2">
                                                                        <?= $lang['option_no'] ?>
                                                                    </label>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php /*獎勵內容*/ ?>
                                            <div id="step4" class="tab-pane fade">
                                                <div class="row">
                                                    <div class="col-md-10 mr-auto ml-auto">
                                                        <div class="row">
                                                            <div class="col-md-4">
                                                                <label>
                                                                    <span style="color:red">*</span>
                                                                    <?= $lang['reward_name'] ?>
                                                                </label>
                                                            </div>
                                                            <div class="col-md-3">
                                                                <label>
                                                                    <span style="color:red">*</span>
                                                                    <?= $lang['reward_point'] ?>
                                                                </label>
                                                            </div>
                                                            <div class="col-md-2">
                                                                <label>
                                                                    <span style="color:red">*</span>
                                                                    <?= $lang['win_chance'] ?>
                                                                </label>
                                                            </div>
                                                            <div class="col-md-2">
                                                                <label>
                                                                    <span style="color:red">*</span>
                                                                    <?= $lang['reward_item_count'] ?>
                                                                </label>
                                                            </div>
                                                            <div class="col-md-1">
                                                            </div>
                                                        </div>
                                                        <?php if ($event_started == false) { ?>
                                                        <div class="row">
                                                            <div class="col-md-4">
                                                                <div class="form-group">
                                                                    <input type="text" class="form-control" id='p_name' />
                                                                </div>
                                                            </div>
                                                            <div class="col-md-3">
                                                                <div class="form-group input-group mb-3">
                                                                    <div class="input-group-prepend">
                                                                        <span class="input-group-text">
                                                                            <a class="align-middle" href="" id="dollar" >
                                                                                <i class="fa fa-dollar"></i>
                                                                            </a>
                                                                        </span>
                                                                </div>
                                                                <input type="text"
                                                                       class="form-control allow_numeric text-right"
                                                                       id='p_point' />
                                                                <div class="input-group-append" id="fa-percent">
                                                                        <span class="input-group-text">
                                                                            <a class="align-middle" href="" id="percent" >
                                                                                <i class="fa fa-percent" style="color:#dddddd;"></i>
                                                                            </a>
                                                                        </span>
                                                                    </div>
                                                                    <input type="hidden" id='p_type' value="0" />
                                                                </div>
                                                            </div>
                                                            <div class="col-md-2">
                                                                <div class="form-group">
                                                                    <input type="number" class="form-control allow_float text-right"
                                                                        id="p_percentage" />
                                                                </div>
                                                            </div>
                                                            <div class="col-md-2">
                                                                <div class="form-group">
                                                                    <input type="text" class="form-control text-right" id="p_number" />
                                                                </div>
                                                            </div>
                                                            <div class="col-md-1">
                                                                <div class="form-group">
                                                                    <label>　</label>
                                                                    <button type="button" id="add" class="btn btn-info">
                                                                        <i class="ti-plus"></i>
                                                                    </button>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <?php } ?>
                                                        <div id="prize" style="padding-bottom:20px">
                                                            <?php
                                                            foreach ($event['prizes'] as $row) {
                                                            ?>
                                                            <div class="row">
                                                                <div class="col-md-4">
                                                                    <input type="hidden" name="prize_content_id[]"
                                                                           value="<?= $row['id'] ?>" />
                                                                    <input type="text" class="form-control" disabled
                                                                           value="<?= $row['name'] ?>" />
                                                                    <input type="hidden" name="prize_content_name[]"
                                                                           value="<?= $row['name'] ?>" />
                                                                </div>
                                                                <div class="col-md-3">
                                                                    <div class="form-group input-group mb-3">
                                                                        <input type="hidden" name="prize_content_type[]" value="<?= $row['type'] ?>" />
                                                                        <?php if ($row['type'] == 0) { ?>
                                                                            <div class="input-group-prepend">
                                                                        <span class="input-group-text">
                                                                            <i class="fa fa-dollar" style="color:#000000;"></i>
                                                                        </span>
                                                                            </div>
                                                                        <?php } ?>
                                                                        <input type="text" class="form-control text-right" disabled
                                                                               value="<?= (($row['type'] == 1) ? $row['point_percentage'] :  $row['point']) ?>" />
                                                                        <input type="hidden" name="prize_content_point[]"
                                                                               value="<?= (($row['type'] == 1) ? $row['point_percentage'] :  $row['point']) ?>" />
                                                                        <?php if ($row['type'] == 1) { ?>
                                                                            <div class="input-group-append">
                                                                        <span class="input-group-text">
                                                                            <i class="fa fa-percent" style="color:#000000;"></i>
                                                                        </span>
                                                                            </div>
                                                                        <?php } ?>
                                                                    </div>
                                                                </div>
                                                                <div class="col-md-2">
                                                                    <input type="text" class="form-control text-right" disabled
                                                                           value="<?= $row['percentage'] ?>" />
                                                                    <input type="hidden"
                                                                           name="prize_content_percentage[]"
                                                                           value="<?= $row['percentage'] ?>" />
                                                                </div>
                                                                <div class="col-md-2">
                                                                    <input type="text" class="form-control text-right" disabled
                                                                           value="<?= $row['number'] ?>" />
                                                                    <input type="hidden" name="prize_content_number[]"
                                                                           value="<?= $row['number'] ?>" />
                                                                </div>
                                                                <?php if ($event_started == false) { ?>
                                                                <div class="col-md-1">
                                                                    <button type="button" class="btn btn-danger minus">
                                                                        <i class="ti-minus"></i>
                                                                    </button>
                                                                </div>
                                                                <?php } ?>
                                                            </div>
                                                            <?php
                                                            }
                                                            ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php /*設定條件*/ ?>
                                            <div id="step5" class="tab-pane fade">
                                                <div class="row">
                                                    <div class="col-md-10 mr-auto ml-auto">
                                                        <div class="row">
                                                            <div class="col-md-2">
                                                                <div class="form-group">
                                                                    <label>
                                                                        <span style="color:red"></span>
                                                                        <?= $lang['event_flow_limit_multiple'] ?>
                                                                    </label>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-2">
                                                                <input type="number" id="prize_flow_limit_multiple"
                                                                       class="form-control allow_numeric text-right"
                                                                       name="prize_flow_limit_multiple" <?= $disable ?>
                                                                       value="<?= ($event['flow_limit_multiple'] ? $event['flow_limit_multiple'] : 0) ?>" />
                                                            </div>
                                                            <div class="col-md-4" id="group_prize_flow_limit_include_principal">
                                                                <div class="form-group">
                                                                    <label>
                                                                        <span style="color:red"></span>
                                                                        <?= $lang['event_flow_limit_include_principal'] ?>
                                                                    </label>
                                                                    <div class="radio radio-inline">
                                                                        <input type="radio"
                                                                               name="prize_flow_limit_include_principal" value="0"
                                                                               id="pflip2" <?= $disable ?>
                                                                            <?= ($event['prize_flow_limit_include_principal'] == 0 || !isset($event['prize_flow_limit_include_principal'])) ? 'checked' : '' ?>>
                                                                        <label for="pflip2">
                                                                            <?= $lang['option_no'] ?>
                                                                        </label>
                                                                    </div>
                                                                    <div class="radio radio-inline">
                                                                        <input type="radio"
                                                                               name="prize_flow_limit_include_principal" value="1"
                                                                               id="pflip1" <?= $disable ?>
                                                                            <?= ($event['prize_flow_limit_include_principal'] == 1) ? 'checked' : '' ?> >
                                                                        <label for="pflip1">
                                                                            <?= $lang['option_yes'] ?>
                                                                        </label>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php if ($event_started == false) { ?>
                                            <div class="wizard-pager">
                                                <div class="">
                                                    <input type="hidden" name="id" value="<?= $_GET['id'] ?>">
                                                    <input type="hidden" name="action" value="save_new_event">
                                                    <button type="button" class="btn btn-default button-previous btn-rounded">
                                                        <?= $lang['previous'] ?>
                                                    </button>
                                                    <button type="button" class="btn btn-primary button-next pull-right btn-rounded">
                                                        <?= $lang['next'] ?>
                                                    </button>
                                                    <button type="button" class="btn btn-success pull-right btn-rounded" id="save">
                                                        <?= $lang['btn_update'] ?>
                                                    </button>
                                                </div>
                                            </div>
                                            <?php } ?>
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
            $.post("<?= BASE_URL ?>ajax.php", $("#shipping-form").serialize(), function(data) {
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

        $(".allow_float").on("keypress keyup blur", function(event) {
            $(this).val($(this).val().replace(/[^0-9\.]/g, ''));
            if ((event.which != 46 || $(this).val().indexOf('.') != -1) && (event.which < 48 || event
                .which > 57)) {
                event.preventDefault();
            }
        });

        $(".allow_numeric").on("keypress keyup blur", function(event) {
            $(this).val($(this).val().replace(/[^\d].+/, ""));
            if ((event.which < 48 || event.which > 57)) {
                event.preventDefault();
            }
        });

        //選擇方式
        $('#action_id').change(function(e) {
            var action = $('#action_id').val();
            $("#dollar").click();

            $("#group_prize_withdrawal_cancel_reward").hide();
            $("#group_prize_flow_limit_include_principal").hide();

            //1:儲值滿額, 4:首儲滿額, 31:邀請玩家, 51:首充, 52,每次充, 53:首充, 54:每次充, 55:每日充
            if (action == 1 || action == 4 || action == 51 || action == 52 || action == 53 || action == 54 || action == 55) {
                //儲值
                $("#fa-percent").show();
                $("#event_reward_points").show();
                $("#action_type_deposit").show();
                $("#action_type_game").hide();
                $("#group_unlimited").hide();
                if (action == 51 || action == 52 || action == 53 || action == 54 || action == 55) {
                    $("#group_prize_withdrawal_cancel_reward").show();
                }
                if (action == 51 || action == 52) {
                    $("#group_prize_flow_limit_include_principal").show();
                }
                $('#vendor_id option').eq(0).prop('selected', true);
                $('#game_type_id option').eq(0).prop('selected', true);
                <?php if ($event_started == false) { ?>
                $('#vendor_id').attr('disabled', true);
                $('#game_type_id').attr('disabled', true);
                $("#order_type").removeClass("disabledStyle");
                <?php } ?>
            } else if (action == 31) {
                //邀請玩家優惠
                $("#fa-percent").hide();
                $("#event_reward_points").hide();
                $("#action_type_deposit").hide();
                $("#action_type_game").hide();
                $("#group_unlimited").show();
                $("#group_prize_withdrawal_cancel_reward").hide();
                $('#vendor_id option').eq(0).prop('selected', true);
                $('#game_type_id option').eq(0).prop('selected', true);
                <?php if ($event_started == false) { ?>
                $('#vendor_id').attr('disabled', true);
                $('#game_type_id').attr('disabled', true);
                <?php } ?>
            } else {
                //遊戲
                $("#fa-percent").hide();
                $("#event_reward_points").show();
                $("#action_type_deposit").hide();
                $("#action_type_game").show();
                $("#group_unlimited").hide();
                $("#group_prize_withdrawal_cancel_reward").hide();
                $("#order_type").addClass("disabledStyle");
                <?php if ($event_started == false) { ?>
                $('#vendor_id').attr('disabled', false);
                $('#game_type_id').attr('disabled', false);
                <?php } ?>
            }

        //可否重覆獲取
        $('#r1').attr('disabled', false);
        $('#r2').attr('disabled', false);
        $('#r0').removeClass('disabledStyle');
        if (action == 8 || action == 51 || action == 53) {
            $('#r2').click();
            $('#r1').attr('disabled', 'disabled');
            $('#r0').addClass('disabledStyle');
        } else if (action == 52 || action == 54 || action == 55) {
            $('#r1').click();
            $('#r2').attr('disabled', 'disabled');
            $('#r0').addClass('disabledStyle');
        } else {
            $('#r1').click();
        }
    })

        //增加獎勵
        $('#add').click(function(e) {
            if (e.preventDefault) {
                e.preventDefault();
            } else {
                window.event.returnValue == false;
            }
            var name = $('#p_name').val();
            var point = $('#p_point').val();
            var percentage = $('#p_percentage').val();
            var number = $('#p_number').val();
            var type = $('#p_type').val();
            //所有的值都是必填
            if (name == '') {
                $('#p_name').focus();
                alert("<?= $lang['please_input_lottery_name'] ?>");
            } else if (point == '') {
                $('#p_point').focus();
                alert("<?= $lang['please_input_lottery_point'] ?>");
            } else if (percentage == '') {
                $('#p_percentage').focus();
                alert("<?= $lang['please_input_lottery_percentage'] ?>");
            } else if (number == '') {
                $('#p_number').focus();
                alert("<?= $lang['please_input_lottery_number'] ?>");
            } else {
                var data = '';
                data += '<div class="row">';
                data += '<div class="col-md-4">';
                data += '<input type="hidden" name="prize_content_id[]" value=""/>';
                data += '<input type="text" class="form-control" disabled value="' + name + '"/>';
                data += '<input type="hidden" name="prize_content_name[]" value="' + name + '"/>';
                data += '</div>';
                data += '<div class="col-md-3"><div class="input-group mb-3">';
                if (type == 0) {
                    data += '<div class="input-group-prepend"><span class="input-group-text" style="color:#000;"><i class="fa fa-dollar"></i></span></div>';
                }
                data += '<input type="text" class="form-control text-right" disabled value="' + point + '" />';
                if (type == 1) {
                    data += '<div class="input-group-append"><span class="input-group-text" style="color:#000;"><i class="fa fa-percent"></i></span></div>';
                }
                data += '<input type="hidden" name="prize_content_point[]" value="' + point + '" />';
                data += '<input type="hidden" name="prize_content_type[]" value="' + type + '" />';
                data += '</div></div>';
                data += '<div class="col-md-2">';
                data += '<input type="text" class="form-control text-right" disabled value="' + percentage + '"/>';
                data += '<input type="hidden" name="prize_content_percentage[]" value="' + percentage + '"/>';
                data += '</div>';
                data += '<div class="col-md-2">';
                data += '<input type="text" class="form-control text-right" disabled value="' + number + '" />';
                data += '<input type="hidden" name="prize_content_number[]" value="' + number + '" />';
                data += '</div>';
                data += '<div class="col-md-1">';
                data += '<button type="button" class="btn btn-danger minus"><i class="ti-minus"></i></button>';
                data += '</div>';
                data += '</div>';
                //清空input
                $('#p_name').val('');
                $('#p_point').val('');
                $('#p_percentage').val('');
                $('#p_number').val('');
                //增加一行
                $('#prize').append(data);
            }
        });

        //獎勵內容+-
        $(document).on('click', '.minus', function(e) {
            if (e.preventDefault) {
                e.preventDefault();
            } else {
                window.event.returnValue == false;
            }
            $(this).parent().parent().remove();
        });

        //平台點數切換實際金額或%數
        $("#step4 a").on('click', function(event) {
            event.preventDefault();
            var id = $(this).attr('id');

            if (id == 'percent') {
                $('#dollar i').css("color", "#ddd");
                $('#percent i').css("color", "");
                $('#p_type').val(1);
            } else {
                $('#percent i').css("color", "#ddd");
                $('#dollar i').css("color", "");
                $('#p_type').val(0);
            }
        });

        $('#action_id').change();

        $('input[name="order_type_id[]"]').on('click', function(event) {
            var check = $(this).prop("checked");
            var parent = $(this).attr("data-parent");
            var val = $(this).val();
            if (val == 0) {
                $('input[name="cf_id[]"]').each(function(index) {
                    $(this).prop('checked', check);
                });
                $('input[name="order_type_id[]"]').each(function(index) {
                    $(this).prop('checked', check);
                });
            } else {
                var check_group = true;
                $('input[data-parent="' + parent + '"]').each(function(index) {
                    if ($(this).prop("checked") == false) {
                        check_group = false;
                        $("#order_type_id_0").prop('checked', check_group);
                    }
                });
                $('input[data-id="' + parent + '"]').prop('checked', check_group);
            }
        });

        $('input[name="cf_id[]"]').on('click', function(event) {
            var checked = $(this).prop("checked");
            $('input[data-parent="' + $(this).attr("data-id") + '"]').prop('checked', checked);
            if (checked == false) {
                $("#order_type_id_0").prop('checked', checked);
            }
        });

        action_type_deposit();
    });

    function action_type_deposit() {
        $("#order_type_id_0").prop('checked', true);
        $('input[name="cf_id[]"]').each(function(index) {
            $(this).prop('checked', true);
        });
        $('input[name="order_type_id[]"]').each(function(index) {
            if ($(this).prop("checked") == false) {
                $("#order_type_id_0").prop('checked', false);
                $('input[data-id="' + $(this).attr("data-parent") + '"]').prop('checked', false);
            }
        });
    }

    function disable_deposit() {
        $('input[name="cf_id[]"]').attr('disabled', 'disabled');
        $('input[name="order_type_id[]"]').attr('disabled', 'disabled');
        $("#order_type_id_0").attr('disabled', false);
        $("#order_type").addClass("disabledStyle");
    }

    function enable_deposit() {
        $('input[name="cf_id[]"]').attr('disabled', false);
        $('input[name="order_type_id[]"]').attr('disabled', false);
        $("#order_type").removeClass('disabledStyle');
    }
</script>
<style>
    .disabledStyle {
        opacity: .5;
    }
</style>