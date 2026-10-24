<?php
require_once '../_init.php';
// 取詳細資料
if ($_GET['id']) {
    $data = array();
    $data['id'] = $_GET['id'];
    $result = curlPost('api/event/view', $data);
    if ($result['code'] == 1000) {
        $event = $result['data'];
        $language_data = array();
        foreach ($event['languages'] as $row) {
            $language_data[$row['language_id']] = $row;
        }
    }
}
$start_date = date('Y-m-d 00:00');
$end_date = date('Y-m-d 23:59', strtotime('+1month'));
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

// 獎勵方式列表
$result = curlPost('api/prizeType/list');
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
$data = [
    'page' => 1,
    'per_page' => 1000
];
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
require_once '../_header.php';
require_once '../_menu.php';
?>
<!-- Page Container START -->
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
    <!-- Content Wrapper START -->
    <div class="main-content">
        <div class="container-fluid">
            <div class="page-title">
                <h4>系統管理 > 活動管理 > <?= isset($_GET['id']) ? '編輯' : '新增' ?>活動</h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="row relative">
                                <form id="shipping-form" class="width-100" method="get">
                                    <div id="rootwizard" class="form-wizard col-md-10 ml-auto mr-auto">
                                        <ul class="nav nav-pills nav-fill">
                                            <li class="nav-item">
                                                <a href="#step1" data-toggle="tab">
                                                    <span class="step">1</span>
                                                    <span class="title">名稱/內容</span>
                                                </a>
                                            </li>
                                            <li class="nav-item">
                                                <a href="#step2" data-toggle="tab">
                                                    <span class="step">2</span>
                                                    <span class="title">設定條件</span>
                                                </a>
                                            </li>
                                            <li class="nav-item">
                                                <a href="#step3" data-toggle="tab">
                                                    <span class="step">3</span>
                                                    <span class="title">設定獎勵</span>
                                                </a>
                                            </li>
                                            <li class="nav-item">
                                                <a href="#step4" data-toggle="tab">
                                                    <span class="step">4</span>
                                                    <span class="title">獎勵內容</span>
                                                </a>
                                            </li>
                                            <li class="nav-item">
                                                <a href="#step5" data-toggle="tab">
                                                    <span class="step">5</span>
                                                    <span class="title">指定開獎</span>
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
                                                        <div class="row">
                                                            <div class="col-md-12">
                                                                <div class="form-group">
                                                                    <label><span
                                                                            style="color:red">*</span>選擇活動類型</label>
                                                                    <select class="form-control" data-live-search="true"
                                                                        name="type_id">
                                                                        <?php
                                                                        foreach ($types as $type) {
                                                                            ?>
                                                                        <option value="<?= $type['value'] ?>"
                                                                            <?= ($type['value'] == $event['type_id']) ? 'selected' : '' ?>>
                                                                            <?= $lang['event_type.' . $type['parameter']] ?>
                                                                        </option>
                                                                        <?php
                                                                        }
                                                                        ?>
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
                                                                                role="tab" data-toggle="tab">預設</a>
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
                                                                                <p class="mrg-btm-15 font-size-13"><span
                                                                                        style="color:red">*</span>活動名稱
                                                                                </p>
                                                                                <input type="text" class="form-control"
                                                                                    placeholder="New event" name="title"
                                                                                    value="<?= $event['title'] ?>">
                                                                            </div>
                                                                            <div class="form-group">
                                                                                <p class="mrg-btm-15 font-size-13"><span
                                                                                        style="color:red">*</span>活動內容
                                                                                </p>
                                                                                <textarea name="content"
                                                                                    class="form-control"><?= $event['content'] ?></textarea>
                                                                            </div>
                                                                        </div>
                                                                        <?php
                                                                        foreach ($languages as $key => $value) {
                                                                            ?>
                                                                        <div role="tabpanel" class="tab-pane fade"
                                                                            id="pills-vertical-<?= $value['id'] ?>">
                                                                            <div class="form-group">
                                                                                <p class="mrg-btm-15 font-size-13">活動名稱
                                                                                </p>
                                                                                <input type="text" class="form-control"
                                                                                    placeholder="Taiwan"
                                                                                    name="titles[<?= $value['id'] ?>]"
                                                                                    value="<?= $language_data[$value['id']]['title'] ?>">
                                                                            </div>
                                                                            <div class="form-group">
                                                                                <p class="mrg-btm-15 font-size-13">活動內容
                                                                                </p>
                                                                                <textarea rows="" cols=""
                                                                                    class="form-control"
                                                                                    name="contents[<?= $value['id'] ?>]"><?= $language_data[$value['id']]['content'] ?></textarea>
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
                                            <div id="step2" class="tab-pane fade">
                                                <div class="row">
                                                    <div class="col-md-10 mr-auto ml-auto">
                                                        <div class="row">
                                                            <div class="col-md-2">
                                                                <div class="form-group">
                                                                    <label><span style="color:red">*</span>選擇方式</label>
                                                                    <select class="form-control" id="action_id">
                                                                        <option value="">請選擇</option>
                                                                        <?php
                                                                        $action_array = [];
                                                                        foreach ($actions as $action) {
                                                                            $action_array[$action['value']] = $action['parameter'];
                                                                            ?>
                                                                        <option value="<?= $action['value'] ?>">
                                                                            <?= $lang['event_action.' . $action['parameter']] ?>
                                                                        </option>
                                                                        <?php
                                                                        }
                                                                        ?>
                                                                    </select>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-2">
                                                                <div class="form-group">
                                                                    <label>選擇限定的儲值方式</label>
                                                                    <select class="form-control" id="order_type_id">
                                                                        <option value="">請選擇</option>
                                                                        <?php
                                                                        $type_array = [];
                                                                        foreach ($order_types as $order_type) {
                                                                            $type_array[$order_type['id']] = $order_type['name'];
                                                                            ?>
                                                                        <option value="<?= $order_type['id'] ?>">
                                                                            <?= $order_type['name'] ?></option>
                                                                        <?php
                                                                        }
                                                                        ?>
                                                                    </select>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-2">
                                                                <div class="form-group">
                                                                    <label>選擇限定的遊戲商</label>
                                                                    <select class="form-control" id="vendor_id">
                                                                        <option value="">請選擇</option>
                                                                        <?php
                                                                        $vendor_array = [];
                                                                        foreach ($vendors as $vendor) {
                                                                            $vendor_array[$vendor['id']] = $vendor['name'];
                                                                            ?>
                                                                        <option value="<?= $vendor['id'] ?>">
                                                                            <?= $vendor['name'] ?></option>
                                                                        <?php
                                                                        }
                                                                        ?>
                                                                    </select>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-3">
                                                                <div class="form-group">
                                                                    <label><span
                                                                            style="color:red">*</span>選擇活動區間</label>
                                                                    <input type="text" class="form-control"
                                                                        id="range" />
                                                                </div>
                                                            </div>
                                                            <div class="col-md-2">
                                                                <div class="form-group">
                                                                    <label><span
                                                                            style="color:red">*</span>需大於等於多少點數</label>
                                                                    <input type="text"
                                                                        class="form-control allow_numeric" id="point" />
                                                                </div>
                                                            </div>
                                                            <div class="col-md-1">
                                                                <div class="form-group">
                                                                    <label>　</label>
                                                                    <button type="button" id="add_detail"
                                                                        class="btn btn-info"><i
                                                                            class="ti-plus"></i></button>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div id="detail" style="padding-bottom:20px">
                                                            <?php
                                                            foreach ($event['details'] as $row) {
                                                                ?>
                                                            <div class="row">
                                                                <div class="col-md-2">
                                                                    <input type="text" class="form-control" disabled
                                                                        value="<?= $lang['event_action.' . $action_array[$row['action_id']]] ?>" />
                                                                    <input type="hidden" name="action_id[]"
                                                                        value="<?= $row['action_id'] ?>" />
                                                                </div>
                                                                <div class="col-md-2">
                                                                    <input type="text" class="form-control" disabled
                                                                        value="<?= $type_array[$row['order_type_id']] ?>" />
                                                                    <input type="hidden" name="order_type_id[]"
                                                                        value="<?= $row['order_type_id'] ?>" />
                                                                </div>
                                                                <div class="col-md-2">
                                                                    <input type="text" class="form-control" disabled
                                                                        value="<?= $vendor_array[$row['vendor_id']] ?>" />
                                                                    <input type="hidden" name="vendor_id[]"
                                                                        value="<?= $row['vendor_id'] ?>" />
                                                                </div>
                                                                <div class="col-md-3">
                                                                    <input type="text" class="form-control" disabled
                                                                        value="<?= substr($row['start_time'], 0, 16) ?> - <?= substr($row['end_time'], 0, 16) ?>" />
                                                                    <input type="hidden" name="range[]"
                                                                        value="<?= substr($row['start_time'], 0, 16) ?> - <?= substr($row['end_time'], 0, 16) ?>" />
                                                                </div>
                                                                <div class="col-md-2">
                                                                    <input type="text" class="form-control" disabled
                                                                        value="<?= $row['point'] ?>" />
                                                                    <input type="hidden" name="point[]"
                                                                        value="<?= $row['point'] ?>" />
                                                                </div>
                                                                <div class="col-md-1">
                                                                    <button type="button" class="btn btn-info minus"><i
                                                                            class="ti-minus"></i></button>
                                                                </div>
                                                            </div>
                                                            <?php
                                                            }
                                                            ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div id="step3" class="tab-pane fade">
                                                <div class="row">
                                                    <div class="col-md-10 mr-auto ml-auto">
                                                        <div class="row">
                                                            <div class="col-md-12">
                                                                <div class="form-group">
                                                                    <label><span style="color:red">*</span>獎勵方式</label>
                                                                    <select class="form-control" data-live-search="true"
                                                                        name="prize_type_id">
                                                                        <option value="">請選擇</option>
                                                                        <?php
                                                                        foreach ($prize_types as $prize_type) {
                                                                            ?>
                                                                        <option value="<?= $prize_type['id'] ?>"
                                                                            <?= ($prize_type['id'] == $event['prize_type_id']) ? 'selected' : '' ?>>
                                                                            <?= $prize_type['name'] ?></option>
                                                                        <?php
                                                                        }
                                                                        ?>
                                                                    </select>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="row">
                                                            <div class="col-md-12">
                                                                <div class="form-group">
                                                                    <label><span
                                                                            style="color:red">*</span>獎勵遊戲名稱</label>
                                                                    <input type="text" class="form-control"
                                                                        name="prize_name"
                                                                        value="<?= $event['prize_name'] ?>" />
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="row">
                                                            <div class="col-md-12" style="text-align:center">
                                                                <p class="mrg-top-10 text-dark"> <b><span
                                                                            style="color:red">*</span>請選擇您的獎勵活動背景圖</b>
                                                                </p>
                                                                <div>
                                                                    <label for="img-upload" class="pointer">
                                                                        <img src="<?= $event['prize_background'] ?>"
                                                                            id="show_background"
                                                                            style="max-height:300px;max-width:300px" />
                                                                        <input type="hidden" name="background">
                                                                        <span
                                                                            class="btn btn-default display-block no-mrg-btm">Choose
                                                                            file</span>
                                                                        <input class="d-none" type="file"
                                                                            name="background_file" id="img-upload">
                                                                    </label>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <!-- <div class="row">
                                                            <div class="col-md-12">
                                                                <div class="form-group">
                                                                    <label><span
                                                                            style="color:red">*</span>獎勵遊戲獲得次數</label>
                                                                    <input type="text"
                                                                        class="form-control allow_numeric"
                                                                        name="prize_number"
                                                                        value="<?= $event['prize_number'] ?>" />
                                                                </div>
                                                            </div>
                                                        </div> -->
                                                        <div class="row">
                                                            <div class="col-md-12">
                                                                <div class="form-group">
                                                                    <label><span style="color:red">*</span>可否重覆獲取</label>
                                                                    <div class="col-md-10">
                                                                        <div class="radio radio-inline">
                                                                            <input type="radio" name="prize_is_repeatedly" value="1"
                                                                                id="r1"
                                                                                <?= ($event['prize_is_repeatedly'] == 1 || !isset($event['prize_is_repeatedly'])) ? 'checked' : '' ?>>
                                                                            <label for="r1">可重覆獲取</label>
                                                                        </div>
                                                                        <div class="radio radio-inline">
                                                                            <input type="radio" name="prize_is_repeatedly" value="0"
                                                                                id="r2"
                                                                                <?= ($event['prize_is_repeatedly'] == '0') ? 'checked' : '' ?>>
                                                                            <label for="r2">不可重覆獲取</label>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="row">
                                                            <div class="col-md-12">
                                                                <div class="form-group">
                                                                    <label><span style="color:red">*</span>獎勵到期日</label>
                                                                    <input type="text" id="date-picker"
                                                                        class="form-control" name="prize_expire_date"
                                                                        value="<?= substr($event['prize_expire_date'], 0, 10) ?>" />
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div id="step4" class="tab-pane fade">
                                                <div class="row">
                                                    <div class="col-md-10 mr-auto ml-auto">
                                                        <div class="row">
                                                            <div class="col-md-5">
                                                                <div class="form-group">
                                                                    <label><span style="color:red">*</span>獎勵名稱</label>
                                                                    <input type="text" class="form-control"
                                                                        id='p_name' />
                                                                </div>
                                                            </div>
                                                            <div class="col-md-2">
                                                                <div class="form-group">
                                                                    <label><span
                                                                            style="color:red">*</span>可獲得平台點數</label>
                                                                    <input type="text"
                                                                        class="form-control allow_numeric"
                                                                        id='p_point' />
                                                                </div>
                                                            </div>
                                                            <div class="col-md-2">
                                                                <div class="form-group">
                                                                    <label><span style="color:red">*</span>中獎機率</label>
                                                                    <input type="text" class="form-control allow_float"
                                                                        id='p_percentage' />
                                                                </div>
                                                            </div>
                                                            <div class="col-md-2">
                                                                <div class="form-group">
                                                                    <label><span style="color:red">*</span>獎品數量</label>
                                                                    <input type="text"
                                                                        class="form-control allow_numeric"
                                                                        id='p_number' />
                                                                </div>
                                                            </div>
                                                            <div class="col-md-1">
                                                                <div class="form-group">
                                                                    <label>　</label>
                                                                    <button type="button" id="add"
                                                                        class="btn btn-info"><i
                                                                            class="ti-plus"></i></button>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div id="prize" style="padding-bottom:20px">
                                                            <?php
                                                            foreach ($event['prizes'] as $row) {
                                                                ?>
                                                            <div class="row">
                                                                <div class="col-md-5">
                                                                    <input type="text" class="form-control" disabled
                                                                        value="<?= $row['name'] ?>" />
                                                                    <input type="hidden" name="prize_content_name[]"
                                                                        value="<?= $row['name'] ?>" />
                                                                </div>
                                                                <div class="col-md-2">
                                                                    <input type="text" class="form-control" disabled
                                                                        value="<?= $row['point'] ?>" />
                                                                    <input type="hidden" name="prize_content_point[]"
                                                                        value="<?= $row['point'] ?>" />
                                                                </div>
                                                                <div class="col-md-2">
                                                                    <input type="text" class="form-control" disabled
                                                                        value="<?= $row['percentage'] ?>" />
                                                                    <input type="hidden"
                                                                        name="prize_content_percentage[]"
                                                                        value="<?= $row['percentage'] ?>" />
                                                                </div>
                                                                <div class="col-md-2">
                                                                    <input type="text" class="form-control" disabled
                                                                        value="<?= $row['number'] ?>" />
                                                                    <input type="hidden" name="prize_content_number[]"
                                                                        value="<?= $row['number'] ?>" />
                                                                </div>
                                                                <div class="col-md-1">
                                                                    <button type="button" class="btn btn-info minus"><i
                                                                            class="ti-minus"></i></button>
                                                                </div>
                                                            </div>
                                                            <?php
                                                            }
                                                            ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div id="step5" class="tab-pane fade">
                                                <div class="row">
                                                    <div class="col-md-10 mr-auto ml-auto">
                                                        <div class="row">
                                                            <div class="col-md-7">
                                                                <div class="form-group">
                                                                    <label><span style="color:red">*</span>營運商</label>
                                                                    <select class="item-info" id="assign_agent">
                                                                        <option value="">請選擇</option>
                                                                        <?php
                                                                        $agent_arry = [];
                                                                        foreach ($agents as $agent) {
                                                                            $agent_arry[$agent['id']] = $agent['username'];
                                                                            ?>
                                                                        <option value="<?= $agent['id'] ?>">
                                                                            <?= $agent['username'] ?></option>
                                                                        <?php
                                                                        }
                                                                        ?>
                                                                    </select>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-4">
                                                                <div class="form-group">
                                                                    <label><span style="color:red">*</span>獲獎比例</label>
                                                                    <input type="text" class="form-control allow_float"
                                                                        id="assign_percentage" />
                                                                </div>
                                                            </div>
                                                            <div class="col-md-1">
                                                                <div class="form-group">
                                                                    <label>　</label>
                                                                    <button type="button" id="add_assign"
                                                                        class="btn btn-info"><i
                                                                            class="ti-plus"></i></button>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div id="assign" style="padding-bottom:20px">
                                                            <?php
                                                            foreach ($event['assign'] as $row) {
                                                                ?>
                                                            <div class="row">
                                                                <div class="col-md-7">
                                                                    <input type="text" class="form-control" disabled
                                                                        value="<?= $agent_arry[$row['agent_id']] ?>" />
                                                                    <input type="hidden" name="assign_agent_id[]"
                                                                        value="<?= $row['agent_id'] ?>" />
                                                                </div>
                                                                <div class="col-md-4">
                                                                    <input type="text" class="form-control allow_float"
                                                                        disabled value="<?= $row['percentage'] ?>" />
                                                                    <input type="hidden" name="assign_percentage[]"
                                                                        value="<?= $row['percentage'] ?>" />
                                                                </div>
                                                                <div class="col-md-1">
                                                                    <button type="button"
                                                                        class="btn btn-info minus_assign"
                                                                        data-percentage="<?= $row['percentage'] ?>"
                                                                        data-agent="<?= $row['agent_id'] ?>"><i
                                                                            class="ti-minus"></i></button>
                                                                </div>
                                                            </div>
                                                            <?php
                                                            }
                                                            ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="wizard-pager">
                                                <div class="">
                                                    <button type="button"
                                                        class="btn btn-default button-previous btn-rounded">Previous</button>
                                                    <button type="button"
                                                        class="btn btn-primary button-next pull-right btn-rounded">Next</button>
                                                    <input type="hidden" name="id" value="<?= $_GET['id'] ?>">
                                                    <input type="hidden" name="action" value="save_event">
                                                    <button type="button" class="btn btn-success pull-right btn-rounded"
                                                        id="save">Save</button>
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
    <!-- Content Wrapper END -->
    <?php
    require_once '../_bottom.php';
    ?>
</div>
<!-- Page Container END -->
<?php
require_once '../_footer.php';
?>
<script>
$(document).ready(function() {
    //變更日期格式
    $('#range').daterangepicker({
        timePicker: true,
        timePicker24Hour: true,
        timePickerIncrement: 5,
        startDate: '<?= $start_date ?>',
        endDate: '<?= $end_date ?>',
        locale: {
            format: 'YYYY-MM-DD HH:mm'
        }
    });
    $('#date-picker').datepicker({
        startDate: '-0d',
        format: 'yyyy-mm-dd'
    });

    var $select = $('#assign_agent').selectize({
        create: false,
        sortField: {
            field: 'text',
            direction: 'asc'
        },
        dropdownParent: 'body'
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
        })
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

    //上傳圖片後可預覽
    $('#img-upload').change(function(e) {
        if (e.preventDefault) {
            e.preventDefault();
        } else {
            window.event.returnValue == false;
        }
        var file = document.querySelector('input[type="file"]').files[0];
        getBase64(file); // prints the base64 string
    });

    $(".allow_float").on("keypress keyup blur", function(event) {
        //this.value = this.value.replace(/[^0-9\.]/g,'');
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

    $('#action_id').change(function(e) {
        var action = $('#action_id').val();
        if (action == 1 || action == 4) {
            //儲值
            $('#order_type_id').attr('disabled', false);
            $('#vendor_id').attr('disabled', true);
            $('#vendor_id option').eq(0).prop('selected', true);
        } else {
            //遊戲
            $('#order_type_id').attr('disabled', true);
            $('#order_type_id option').eq(0).prop('selected', true);
            $('#vendor_id').attr('disabled', false);
        }
    })

    //增加條件
    $('#add_detail').click(function(e) {
        if (e.preventDefault) {
            e.preventDefault();
        } else {
            window.event.returnValue == false;
        }

        var action_id = $('#action_id').val();
        var action = $('#action_id :selected').text();
        var order_type_id = $('#order_type_id').val();
        var order_type = '';
        if (order_type_id != '') {
            order_type = $('#order_type_id :selected').text();
        }
        var vendor_id = $('#vendor_id').val();
        var vendor = '';
        if (vendor_id != '') {
            vendor = $('#vendor_id :selected').text();
        }
        var point = $('#point').val();
        var range = $('#range').val();

        //所有的值都是必填
        if (action_id == '' || point == '' || range == '') {
            alert('請輸入設定條件');
        } else {
            var data = '';

            data += '<div class="row">';
            data += '<div class="col-md-2">';
            data += '<input type="text" class="form-control" disabled value="' + action + '"/>';
            data += '<input type="hidden" name="action_id[]" value="' + action_id + '"/>';
            data += '</div>';
            data += '<div class="col-md-2">';
            data += '<input type="text" class="form-control" disabled value="' + order_type + '"/>';
            data += '<input type="hidden" name="order_type_id[]" value="' + order_type_id + '"/>';
            data += '</div>';
            data += '<div class="col-md-2">';
            data += '<input type="text" class="form-control" disabled value="' + vendor + '"/>';
            data += '<input type="hidden" name="vendor_id[]" value="' + vendor_id + '"/>';
            data += '</div>';
            data += '<div class="col-md-3">';
            data += '<input type="text" class="form-control" disabled value="' + range + '" />';
            data += '<input type="hidden" name="range[]" value="' + range + '" />';
            data += '</div>';
            data += '<div class="col-md-2">';
            data += '<input type="text" class="form-control" disabled value="' + point + '" />';
            data += '<input type="hidden" name="point[]" value="' + point + '" />';
            data += '</div>';
            data += '<div class="col-md-1">';
            data +=
            '<button type="button" class="btn btn-info minus"><i class="ti-minus"></i></button>';
            data += '</div>';
            data += '</div>';
            //清空input
            $('#point').val('');
            //增加一行  	
            $('#detail').append(data);
        }
    });

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
        //所有的值都是必填
        if (name == '' || number == '' || percentage == '') {
            alert('請輸入獎勵內容');
        } else {
            var data = '';
            data += '<div class="row">';
            data += '<div class="col-md-5">';
            data += '<input type="text" class="form-control" disabled value="' + name + '"/>';
            data += '<input type="hidden" name="prize_content_name[]" value="' + name + '"/>';
            data += '</div>';
            data += '<div class="col-md-2">';
            data += '<input type="text" class="form-control" disabled value="' + point + '" />';
            data += '<input type="hidden" name="prize_content_point[]" value="' + point + '" />';
            data += '</div>';
            data += '<div class="col-md-2">';
            data += '<input type="text" class="form-control" disabled value="' + percentage + '"/>';
            data += '<input type="hidden" name="prize_content_percentage[]" value="' + percentage +
                '"/>';
            data += '</div>';
            data += '<div class="col-md-2">';
            data += '<input type="text" class="form-control" disabled value="' + number + '" />';
            data += '<input type="hidden" name="prize_content_number[]" value="' + number + '" />';
            data += '</div>';
            data += '<div class="col-md-1">';
            data +=
            '<button type="button" class="btn btn-info minus"><i class="ti-minus"></i></button>';
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
    $(document).on('click', '.minus', function(e) {
        if (e.preventDefault) {
            e.preventDefault();
        } else {
            window.event.returnValue == false;
        }
        $(this).parent().parent().remove();
    });

    var total_percentage = 100;
    var assign_array = [];
    //增加指定開獎
    $('#add_assign').click(function(e) {
        if (e.preventDefault) {
            e.preventDefault();
        } else {
            window.event.returnValue == false;
        }
        var agent_id = $('#assign_agent').val();
        var agent = $('#assign_agent :selected').text();
        var percentage = $('#assign_percentage').val();
        //所有的值都是必填
        if (agent == '' || percentage == '') {
            alert('請輸入指定開獎內容');
        } else {
            if ((total_percentage - percentage) < 0) {
                alert('獲獎比例加總不可超過100');
            } else {
                if (assign_array.indexOf(agent_id) > -1) {
                    alert(agent + '已設定');
                } else {
                    total_percentage -= percentage;
                    assign_array.push(agent_id);
                    var data = '';
                    data += '<div class="row">';
                    data += '<div class="col-md-7">';
                    data += '<input type="text" class="form-control"  disabled value="' + agent + '"/>';
                    data += '<input type="hidden" name="assign_agent_id[]" value="' + agent_id + '"/>';
                    data += '</div>';
                    data += '<div class="col-md-4">';
                    data += '<input type="text" class="form-control allow_float" disabled value="' +
                        percentage + '" />';
                    data += '<input type="hidden" name="assign_percentage[]" value="' + percentage +
                        '" />';
                    data += '</div>';
                    data += '<div class="col-md-1">';
                    data +=
                        '<button type="button" class="btn btn-info minus_assign" data-percentage="' +
                        percentage + '" data-agent="' + agent_id +
                        '"><i class="ti-minus"></i></button>';
                    data += '</div>';
                    data += '</div>';
                    //清空input
                    var selectize = $select[0].selectize;
                    selectize.clear();
                    $('#assign_agent').val('');
                    $('#assign_percentage').val('');
                    //增加一行  	
                    $('#assign').append(data);
                }
            }
        }
    });

    $(document).on('click', '.minus_assign', function(e) {
        if (e.preventDefault) {
            e.preventDefault();
        } else {
            window.event.returnValue == false;
        }
        total_percentage += parseFloat($(this).attr("data-percentage"));
        agent_id = $(this).attr("data-agent");
        var index = assign_array.indexOf(agent_id);
        assign_array.splice(index, 1);
        $(this).parent().parent().remove();
    });
});

//圖片轉成BASE64
function getBase64(file) {
    var reader = new FileReader();
    reader.readAsDataURL(file);
    reader.onload = function() {
        //RETURN後變undefined，所以先在這處理
        var image = reader.result;
        $('#show_background').attr('src', image);
        $('[name="background"]').val(image);
        return reader.result;
    };
    reader.onerror = function(error) {
        return 'Error: ', error;
    };
}
</script>