<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';

//確認權限
if (!isset($check_auth['advertise/index'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

// 取詳細資料
if ($_GET['id']) {
    $data = array();
    $data['id'] = $_GET['id'];
    $result = curlPost('api/advertise/view', $data);
    if ($result['code'] == 1000) {
        $advertise = $result['data'];
        
        foreach($advertise['detail'] as $detail) {
            $advertise['language'][] = $detail['language_id'];
            $advertise['advertise_image' . $detail['language_id']] = $detail['advertise_image'];
            $advertise['advertise_image_id' . $detail['language_id']] = $detail['advertise_image_id'];

            $advertise['advertise_image_pc' . $detail['language_id']] = $detail['advertise_image_pc'];
            $advertise['advertise_image_pc_id' . $detail['language_id']] = $detail['advertise_image_pc_id'];
        }
    }
}

$agent_name = array();
$agent_name['id'] = isset($_GET['id']) ? $advertise['agent_id'] : $_SESSION['host_id'];
$agent_name = curlPost('api/agent/view', $agent_name);

$start_date = isset($advertise['start_time'])
    ? substr($advertise['start_time'], 0, 10)
    : date('Y-m-d 00:00', strtotime('+8hour'));
$end_date = isset($advertise['end_time'])
    ? substr($advertise['end_time'], 0, 10)
    : date('Y-m-d 23:59', strtotime('+1month'));

// 語系列表
$data = array();
$result = curlPost('api/languages', $data);
if ($result['code'] != 1000) {
    echo 'languages';exit;
    if ($result['code'] > 9997) {
        header("location:" . BASE_URL . "login.php");
    } else {
        header('Location:' . $_SERVER['HTTP_REFERER']);
    }
    exit();
} else {
    $languages = $result['data'];
}
// 版位列表
$data = array();
$data['page'] = 1;
$data['per_page'] = 1000;
$data['status'] = 1;
$result = curlPost('api/advertisementPath/list', $data);
if ($result['code'] != 1000) {
    if ($result['code'] > 9997) {
        header("location:" . BASE_URL . "login.php");
    } else {
        header('Location:' . $_SERVER['HTTP_REFERER']);
    }
    exit();
} else {
    $paths = $result['data'];
}
// 營運商列表
//$result = curlPost('api/agent/dropdownList');
//if ($result['code'] != 1000) {
//    echo 'agent';exit;
//    if ($result['code'] > 9997) {
//        header("location:" . BASE_URL . "login.php");
//    } else {
//        header('Location:' . $_SERVER['HTTP_REFERER']);
//    }
//    exit();
//} else {
//    $agents = $result['data'];
//}

// 廣告連結類型列表
$data = array();
$data['key'] = 'advertisement_type';
$result = curlPost('api/param/list', $data);
if ($result['code'] != 1000) {
    echo 'param';exit;
    if ($result['code'] > 9997) {
        header("location:" . BASE_URL . "login.php");
    } else {
        header('Location:' . $_SERVER['HTTP_REFERER']);
    }
    exit();
} else {
    $types = $result['data'];
}

// 遊戲類型
$result = curlPost('api/gameType/list');
if ($result['code'] == 1000) {
    $game_types = array();
    foreach ($result['data'] as $row) {
        if ($row['parent'] == 0) {
            $game_types[$row['id']] = $row['name'];
        } else {
            $game_types[$row['id']] = $game_types[$row['parent']] . '>' . $row['name'];
        }
    }
}

// 遊戲列表
$data = [
    'page' => 1,
    'per_page' => 10000
];
$result = curlPost('api/game/list', $data);
if ($result['code'] != 1000) {
    echo 'game';exit;
    header("location:" . BASE_URL . "login.php");
    exit();
} else {
    $games = $result['data'];
}

// 遊戲商列表
$data = [
    'status' => 1,
];
$result = curlPost('api/vendor/list', $data);
if ($result['code'] != 1000) {
    echo 'vendor';exit;
    header("location:" . BASE_URL . "login.php");
    exit();
} else {
    $vendors = $result['data'];
    foreach ($vendors as $row) {
        $v[$row['id']] = $row['name'];
    }
}

// 內部連結列表
$data = array();
$data['key'] = 'advertise_internal';
$result = curlPost('api/param/list', $data);
if ($result['code'] != 1000) {
    echo 'internal_type';exit;
    if ($result['code'] > 9997) {
        header("location:" . BASE_URL . "login.php");
    } else {
        header('Location:' . $_SERVER['HTTP_REFERER']);
    }
    exit();
} else {
    $internal_types = $result['data'];
}

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
                    <?= $lang['system_management'] ?> > <?= $lang['menu_advertisement'] ?> > <?= ($_GET['id']) ? $lang['edit'] : $lang['add'] ?>
                </h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="row relative">
                                <form id="shipping-form" class="width-100">
                                    <div id="rootwizard" class="form-wizard col-md-10 ml-auto mr-auto">
                                        <ul class="nav nav-pills nav-fill">
                                            <li class="nav-item">
                                                <a href="#step1" data-toggle="tab">
                                                    <span class="step">1</span>
                                                    <span class="title">
                                                        <?= $lang['choose_language_location_time'] .'/' . $lang['agent'] ?>
                                                    </span>
                                                </a>
                                            </li>
                                            <li class="nav-item">
                                                <a href="#step2" data-toggle="tab">
                                                    <span class="step">2</span>
                                                    <span class="title">
                                                        <?= $lang['hyperlink'] ?>/<?= $lang['upload_image'] ?>
                                                    </span>
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
                                                                    <label><?= $lang['choose_language'] . $lang['default_english'] ?></label>
                                                                    <select class="selectize" id="language" name="language[]" multiple="multiple">
                                                                        <?php foreach ($languages as $row) { ?>
                                                                        <option value="<?= $row['id'] ?>"
                                                                            <?= ((in_array($row['id'], $advertise['language']) || ($row['id'] == 1 && !isset($advertise['language']))) ? 'selected' : '') ?>>
                                                                            <?= $row['name'] ?>
                                                                        </option>
                                                                        <?php } ?>
                                                                    </select>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="row">
                                                            <div class="col-md-6">
                                                                <div class="form-group">
                                                                    <label>
                                                                        <?= $lang['select_layout_location'] ?>
                                                                    </label>
                                                                    <select id="path_id" class="form-control"
                                                                        data-live-search="true" name="path_id">
                                                                        <?php foreach ($paths as $row) { ?>
                                                                        <option value="<?= $row['id'] ?>"
                                                                            <?= ($row['id'] == $advertise['advertisement_path_id']) ? 'selected' : '' ?>>
                                                                            <?= $row['name'] ?>
                                                                        </option>
                                                                        <?php } ?>
                                                                    </select>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <div class="form-group">
                                                                    <label><?= $lang['agent_name'] ?></label>
                                                                    <select class="selectize-class" id="agent" name="parent">
                                                                            <option value="<?= $agent_name['data']['id'] ?>" selected>
                                                                                <?= $agent_name['data']['username'] ?></option>
                                                                    </select>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="row">
                                                            <div class="col-md-6">
                                                                <div class="form-group">
                                                                    <label>
                                                                        <?= $lang['label_start_time'] ?>
                                                                    </label>
                                                                    <input type="text" id="date-picker"
                                                                           class="form-control date-picker"
                                                                           placeholder="Date picker" name="start_date"
                                                                           value="<?= $start_date ?>" />
                                                                </div>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <div class="form-group">
                                                                    <label>
                                                                        <?= $lang['label_end_time'] ?>
                                                                    </label>
                                                                    <input type="text" class="form-control date-picker" name="end_date"
                                                                           value="<?= $end_date ?>" />
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="row">
                                                            <div class="col-md-6">
                                                                <div class="form-group">
                                                                    <label><?= $lang['status'] ?></label>
                                                                    <div class="col-md-10">
                                                                        <div class="radio radio-inline">
                                                                            <input type="radio" name="status" value="1" id="rad1"
                                                                                <?= ($advertise['status'] == 1 || !isset($advertise['status'])) ? 'checked' : '' ?>>
                                                                            <label for="rad1"><?= $lang['enable'] ?></label>
                                                                        </div>
                                                                        <div class="radio radio-inline">
                                                                            <input type="radio" name="status" value="0" id="rad2"
                                                                                <?= ($advertise['status'] != 1 and isset($advertise['status'])) ? 'checked' : '' ?>>
                                                                            <label for="rad2"><?= $lang['disable'] ?></label>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <div class="form-group">
                                                                    <label><?= $lang['sorts'] ?></label>
                                                                    <input type="text" class="form-control"
                                                                           id="sort" name="sort"
                                                                           value="<?= (isset($advertise['sort']) ? $advertise['sort'] : 0) ?>" />
                                                                    </select>
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
                                                            <div class="col-md-12">
                                                                <div class="form-group">
                                                                    <label><?= $lang['hyperlink'] ?></label>
                                                                    <select class="form-control" id="type" name="type">
                                                                        <option value="0"
                                                                            <?= ($advertise['type'] == 0) ? 'selected' : '' ?>>
                                                                            <?= $lang['advertisement_type_not_link'] ?>
                                                                        </option>
                                                                        <!--
                                                                        <option value="1"
                                                                            <?= ($advertise['type'] == 1) ? 'selected' : '' ?>>
                                                                            <?= $lang['advertisement_type_game_type'] ?>
                                                                        </option>
                                                                        -->
                                                                        <option value="2"
                                                                            <?= ($advertise['type'] == 2) ? 'selected' : '' ?>>
                                                                            <?= $lang['advertisement_type_game'] ?>
                                                                        </option>
                                                                        <option value="3"
                                                                            <?= ($advertise['type'] == 3) ? 'selected' : '' ?>>
                                                                            <?= $lang['advertisement_type_vendor'] ?>
                                                                        </option>
                                                                        <option value="4"
                                                                            <?= ($advertise['type'] == 4) ? 'selected' : '' ?>>
                                                                            <?= $lang['advertisement_type_external'] ?>
                                                                        </option>
                                                                        <option value="5"
                                                                            <?= ($advertise['type'] == 5) ? 'selected' : '' ?>>
                                                                            <?= $lang['advertisement_type_internal'] ?>
                                                                        </option>
                                                                    </select>
                                                                    </br>
                                                                    <select id="game_id" class="form-control"
                                                                        data-live-search="true" name="game_id">
                                                                        <?php
                                                                        $vendor_id = 0;
                                                                        foreach ($games as $row) {
                                                                            if ($vendor_id != $row['vendor_id']) {
                                                                                if ($vendor_id > 0) {
                                                                                    ?>
                                                                        </optgroup>
                                                                            <?php
                                                                                }
                                                                                $vendor_id = $row['vendor_id'];
                                                                                ?>
                                                                                <optgroup label="<?= $vendor_id . '.' . $v[$vendor_id] ?>">
                                                                                    <?php
                                                                            }
                                                                            ?>
                                                                        <option value="<?= $row['id'] ?>"
                                                                            <?= ($advertise['type'] == 2 && $row['id'] == $advertise['content']) ? 'selected' : '' ?>>
                                                                            <?= trim($row['name']) ?>
                                                                        </option>
                                                                        <?php
                                                                        }
                                                                        ?>
                                                                    </select>
                                                                    <select id="game_type_id" class="form-control"
                                                                        data-live-search="true" name="game_type_id">
                                                                        <?php
                                                                        foreach ($game_types as $key => $value) {
                                                                            ?>
                                                                        <option value="<?= $key ?>"
                                                                            <?= ($advertise['type'] == 1 && $key == $advertise['content']) ? 'selected' : '' ?>>
                                                                            <?= $value ?></option>
                                                                        <?php
                                                                        }
                                                                        ?>
                                                                    </select>
                                                                    <select id="vendor_id" class="form-control"
                                                                            data-live-search="true" name="vendor_id">
                                                                        <?php
                                                                        foreach ($vendors as $row) {
                                                                            ?>
                                                                            <option value="<?= $row['id'] ?>"
                                                                                <?= ($advertise['type'] == 3 && $row['id'] == $advertise['content']) ? 'selected' : '' ?>>
                                                                                <?= $row['name'] ?></option>
                                                                            <?php
                                                                        }
                                                                        ?>
                                                                    </select>
                                                                    <div id="external_div">
                                                                        <input type="text" class="form-control"
                                                                               id="external_url" name="external_url"
                                                                               value="<?= (($advertise['type'] == 4) ? $advertise['content'] : '') ?>" /><br>
                                                                        <input type="checkbox" id="open_window" name="open_window"
                                                                               value="1" <?= (($advertise['open_window'] == 1) ? 'checked' : '') ?> /> Open Window
                                                                    </div>
                                                                    <select id="internal_type" class="form-control"
                                                                            data-live-search="true" name="internal_type">
                                                                        <?php
                                                                        foreach ($internal_types as $row) {
                                                                            ?>
                                                                            <option value="<?= $row['parameter'] ?>"
                                                                                <?= ($advertise['type'] == 5 && $row['parameter'] == $advertise['content']) ? 'selected' : '' ?>>
                                                                                <?= $lang['advertise_internal_'. strtolower($row['parameter'])] ?></option>
                                                                            <?php
                                                                        }
                                                                        ?>
                                                                    </select>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="row">
                                                            <div class="col-md-2" style="text-align:center">
                                                            </div>
                                                            <div class="col-md-5" style="text-align:center">
                                                                <p class="mrg-top-10 text-dark">
                                                                    <b>
                                                                        <?= $lang['choose_mobile_ad_picture'] ?>
                                                                    </b>
                                                                </p>
                                                            </div>
                                                            <div class="col-md-5" style="text-align:center">
                                                                <p class="mrg-top-10 text-dark">
                                                                    <b>
                                                                        <?= $lang['choose_pc_ad_picture'] ?>
                                                                    </b>
                                                                </p>
                                                            </div>
                                                        </div>
                                                        <input type="hidden" id="image_tag" value="" />
                                                        <?php foreach ($languages as $row) { ?>
                                                            <div class="row border-top img" id="img_<?= $row['id'] ?>">
                                                                <div class="col-md-2" style="text-align:center">
                                                                    <p class="mrg-top-10 text-dark">
                                                                        <b>
                                                                            <?= $row['name'] ?>
                                                                        </b>
                                                                    </p>
                                                                </div>
                                                                <div class="col-md-5" style="text-align:center">
                                                                    <img src="<?= $advertise['advertise_image' . $row['id']] ?>"
                                                                         id="show_image_<?= $row['id'] ?>"
                                                                         style="max-height:300px; max-width:300px" />
                                                                    <?php /* base64 */ ?>
                                                                    <input type="hidden" name="img_image_<?= $row['id'] ?>">
                                                                    <input class="d-none" type="file"
                                                                           name="image_<?= $row['id'] ?>"
                                                                           id="img_upload_<?= $row['id'] ?>" />
                                                                    <?php /* image id*/ ?>
                                                                    <input type="hidden" id="id_image_<?= $row['id'] ?>" name="id_image_<?= $row['id'] ?>"
                                                                           value="<?= $advertise['advertise_image_id' . $row['id']] ?>" />
                                                                    <label for="img_upload_<?= $row['id'] ?>" class="ti-upload pointer mrg-top-10"
                                                                           data-placement="top" title="<?= $lang['upload_image'] ?>">
                                                                    </label>&nbsp;
                                                                    <label for="img_select_<?= $row['id'] ?>"
                                                                           data-toggle="modal" data-target="#advertise_image"
                                                                           class="ti-view-grid pointer mrg-top-10"
                                                                           data-uid="<?=$row['id']?>"
                                                                           data-placement="top" title="<?= $lang['choose_file'] ?>">
                                                                        <input class="d-none" type="button"
                                                                               name="image_select[]"
                                                                               data_img="image_<?= $row['id'] ?>"
                                                                               id="img_select_<?= $row['id'] ?>" />
                                                                    </label>
                                                                </div>
                                                                <div class="col-md-5" style="text-align:center">
                                                                    <img src="<?= $advertise['advertise_image_pc' . $row['id']] ?>"
                                                                         id="show_image_pc_<?= $row['id'] ?>"
                                                                         style="max-height:300px; max-width:300px" />
                                                                    <input type="hidden" name="img_image_pc_<?= $row['id'] ?>">
                                                                    <input class="d-none" type="file"
                                                                           name="image_pc_<?= $row['id'] ?>"
                                                                           id="img_upload_pc_<?= $row['id'] ?>" />
                                                                    <input type="hidden" id="id_image_pc_<?= $row['id'] ?>" name="id_image_pc_<?= $row['id'] ?>"
                                                                           value="<?= $advertise['advertise_image_pc_id' . $row['id']] ?>" />
                                                                    <label for="img_upload_pc_<?= $row['id'] ?>" class="ti-upload pointer mrg-top-10"
                                                                           data-placement="top" title="<?= $lang['upload_image'] ?>">
                                                                    </label>&nbsp;
                                                                    <label for="img_select_pc_<?= $row['id'] ?>"
                                                                           data-toggle="modal" data-target="#advertise_image"
                                                                           class="ti-view-grid pointer mrg-top-10"
                                                                           data-uid="<?=$row['id']?>"
                                                                           data-placement="top" title="<?= $lang['choose_file'] ?>">
                                                                        <input class="d-none" type="button"
                                                                               name="image_select[]"
                                                                               data_img="image_pc_<?= $row['id'] ?>"
                                                                               id="img_select_pc_<?= $row['id'] ?>" />
                                                                    </label>
                                                                </div>
                                                            </div>
                                                            <?php } ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="wizard-pager">
                                                <div class="">
                                                    <input type="hidden" name="id" value="<?= $_GET['id'] ?>">
                                                    <input type="hidden" name="action" value="save_advertise">
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
                                                    <button type="button" class="btn btn-warning pull-right btn-rounded"
                                                            onclick="history.back();">
                                                        <?= $lang['cancel'] ?>
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
    <div class="modal fade" id="advertise_image">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content" id="image_list">
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
    $('.selectize').selectize({
        plugins: ['remove_button'],
        create: false,
        dropdownParent: 'body'
    });

    $(".date-picker").daterangepicker({
        singleDatePicker: true,
        singleDatePicker: true,
        timePicker: true,
        timePicker24Hour: true,
        timePickerIncrement: 59,
        locale: {
            format: 'YYYY-MM-DD HH:mm'
        }
    });

    //換頁
    $(document).on('click', '#member_search_paging .paginate_button', function(e) {
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

    var _timer = {};
    function delay_till_last(id, fn, wait) {
        if (_timer[id]) {
            window.clearTimeout(_timer[id]);
            delete _timer[id];
        }
        return _timer[id] = window.setTimeout(function() {
            fn();
            delete _timer[id];
        }, wait);
    }

    $("#agent-selectized").on('keyup', function() {
        var val =  $("#agent-selectized").val();
            if(val.length >= 1){
                delay_till_last('agent', function() {
                    $.post("<?= BASE_URL ?>ajax.php", {
                        action : 'get_drop_down_list',
                        username : val
                    }, function(data) {
                        if (data.code == 1000) {
                            var $select = $('#agent').selectize();
                            var selectize = $select[0].selectize;
                            $.each( data.data, function( key, value ){
                                selectize.addOption({value: value['id'], text: value['username']});
                            })
                            selectize.refreshOptions();
                        }
                    }, 'json');
                }, 1000);
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

    //上傳圖片後可預覽
    $('input[type=file]').change(function(e) {
        if (e.preventDefault) {
            e.preventDefault();
        } else {
            window.event.returnValue == false;
        }
        var name = $(this).attr('name');
        var file = document.querySelector('input[name="' + name + '"]').files[0];
        $('#id_' + name).val(0);
        getBase64(file, name); // prints the base64 string
    });

    //選擇已上傳儲存圖片
    $('input[name="image_select[]"]').click(function(e) {
        $('#image_tag').val($(this).attr('data_img'));
        getAdvertiseImage(1);
    });

    //超連結類型切換
    $('#type').change(function(e) {
        if (e.preventDefault) {
            e.preventDefault();
        } else {
            window.event.returnValue == false;
        }
        var type = $(this).val();
        $('#game_type_id').hide();
        $('#game_id').hide();
        $('#vendor_id').hide();
        $('#external_div').hide();
        $('#internal_type').hide();
        if (type == 1) {
            $('#game_type_id').show();
        } else if (type == 2) {
            $('#game_id').show();
        } else if (type == 3) {
            $('#vendor_id').show();
        } else if (type == 4) {
            $('#external_div').show();
        } else if (type == 5) {
            $('#internal_type').show();
        }
    });
    $("#type").change();

    //語言選擇
    $('#language').change(function(e) {
        $('.img').hide();
        $('#language option').each(function() {
            $('#img_' + $(this).val()).show()
        });
    });
    $("#language").change();

    //註冊dataTable事件
    dataTableHelper.initialPagingEventBySelector("#image_list", getAdvertiseImage);
});

function getAdvertiseImage(page) {
    $.blockUI();
    $.post("image_list.php", {
        'id': $('#image_tag').val(),
        'page': page
    }, function (data) {
        $("#image_list").html(data);
        $.unblockUI();
    });
}

//圖片轉成BASE64
function getBase64(file, name) {
    var reader = new FileReader();
    reader.readAsDataURL(file);
    reader.onload = function() {
        //RETURN後變undefined，所以先在這處理
        var image = reader.result;
        $('#show_' + name).attr('src', image);
        $('[name="img_' + name + '"]').val(image);

        //直接寫入DB advertise_images
        $.blockUI({
            baseZ: 9000,
            overlayCSS: {
                backgroundColor: '#444444'
            }
        });
        
        $.post("<?= BASE_URL ?>ajax.php", "action=upload_image&type=1&agent_id=<?= $_SESSION['host_id'] ?>&base64Image=" + image, function(data) {
            $.unblockUI();
            if (data.code == 1000) {
                $('#id_' + name).val(data.data.advertise_image_id);
            } else {
                if (data.code == '2002') {
                    return 'Error: ', data.data;
                } else {
                    return 'Error: ', data.message;
                }
            }
        }, 'json');

        return reader.result;
    };
    reader.onerror = function(error) {
        return 'Error: ', error;
    };
}
</script>