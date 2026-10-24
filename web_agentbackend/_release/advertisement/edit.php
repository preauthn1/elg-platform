<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';

//確認權限
if (!isset($check_auth['advertisement/index'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

// 取詳細資料
if ($_GET['id']) {
    $data = array();
    $data['id'] = $_GET['id'];
    $result = curlPost('api/advertisement/view', $data);
    if ($result['code'] == 1000) {
        $advertisement = $result['data'];
    }
}
$start_date = $advertisement['start_time']
    ? substr($advertisement['start_time'], 0, 10)
    : date('Y-m-d', strtotime('-1month'));
$end_date = $advertisement['end_time']
    ? substr($advertisement['end_time'], 0, 10)
    : date('Y-m-d');

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
$result = curlPost('api/advertisementPath/list', $data);
if ($result['code'] != 1000) {
    echo 'advertisementPath';exit;
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
$data = array();
$data['page'] = 1;
$data['per_page'] = 1000;
$result = curlPost('api/agent/dropdownList', $data);
if ($result['code'] != 1000) {
    echo 'agent';exit;
    if ($result['code'] > 9997) {
        header("location:" . BASE_URL . "login.php");
    } else {
        header('Location:' . $_SERVER['HTTP_REFERER']);
    }
    exit();
} else {
    $agents = $result['data'];
}

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
    'per_page' => 1000
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
                                                        <?= $lang['choose_language_location_time'] ?>
                                                    </span>
                                                </a>
                                            </li>
                                            <li class="nav-item">
                                                <a href="#step2" data-toggle="tab">
                                                    <span class="step">2</span>
                                                    <span class="title"><?= $lang['set_vendor'] ?></span>
                                                </a>
                                            </li>
                                            <li class="nav-item">
                                                <a href="#step3" data-toggle="tab">
                                                    <span class="step">3</span>
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
                                                                    <label><?= $lang['choose_language'] ?></label>
                                                                    <select class="selectize" name="language[]" id="language" multiple="multiple">
                                                                        <?php
                                                                        foreach ($languages as $row) {
                                                                            ?>
                                                                        <option value="<?= $row['id'] ?>"
                                                                            <?= ((in_array($row['id'], $_GET['language']) || ($row['id'] == 1 && !isset($_GET['language']))) ? 'selected' : '') ?>>
                                                                            <?= $row['name'] ?>
                                                                        </option>
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
                                                                    <label>
                                                                        <?= $lang['select_layout_location'] ?>
                                                                    </label>
                                                                    <select id="path_id" class="form-control"
                                                                        data-live-search="true" name="path_id">
                                                                        <?php
                                                                        foreach ($paths as $path) {
                                                                            ?>
                                                                        <option value="<?= $path['id'] ?>"
                                                                            <?= ($path['id'] == $advertisement['path_id']) ? 'selected' : '' ?>>
                                                                            <?= $path['name'] ?>
                                                                        </option>
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
                                                                    <label><?= $lang['opt-in_interval'] ?></label>
                                                                    <input type="text" id="date-range-picker"
                                                                        class="form-control"
                                                                        placeholder="Date range picker" name="range" />
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div id="step2" class="tab-pane fade">
                                                <div class="row">
                                                    <div class="col-md-10 mr-auto ml-auto">
                                                        <div class="card">
                                                            <div class="card-block">
                                                                <div class="table-overflow">
                                                                    <table id="dt-opt1" class="table table-lg table-hover">
                                                                        <thead>
                                                                            <tr>
                                                                                <th><?= $lang['agent_name'] ?></th>
                                                                            </tr>
                                                                        </thead>
                                                                        <tbody>
                                                                            <?php
                                                                            foreach ($agents as $agent) {
                                                                                ?>
                                                                            <tr>
                                                                                <td>
                                                                                    <!--
                                                                                    <div class="radio mrg-left-20">
                                                                                        <input
                                                                                            name="agent[<?= $agent['id'] ?>]"
                                                                                            type="radio"
                                                                                            <?= (($advertisement['agent_id'] == $agent['id']) ? 'checked' : '') ?> />
                                                                                        <label for="agent<?= $agent['id'] ?>">
                                                                                        </label>
                                                                                    </div>
                                                                                    -->
                                                                                    <div class="radio radio-inline">
                                                                                        <input type="radio" name="agent[<?= $agent['id'] ?>]" id="agent_<?= $agent['id'] ?>" value="1"
                                                                                            <?= (($advertisement['agent_id'] == $agent['id']) ? 'checked' : '') ?> />
                                                                                        <label for="agent<?= $agent['id'] ?>">
                                                                                            <div class="info">
                                                                                                <span class="title">
                                                                                                    <?= $agent['username'] ?>
                                                                                                </span>
                                                                                            </div>
                                                                                        </label>
                                                                                    </div>
                                                                                </td>
                                                                            </tr>
                                                                            <?php
                                                                            }
                                                                            ?>
                                                                        </tbody>
                                                                    </table>
                                                                </div>
                                                            </div>
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
                                                                    <label><?= $lang['hyperlink'] ?></label>
                                                                    <select class="form-control" id="type" name="type">
                                                                        <option value="0"
                                                                            <?= ($advertisement['type'] == 0) ? 'selected' : '' ?>>
                                                                            <?= $lang['advertisement_type_not_link'] ?>
                                                                        </option>
                                                                        <option value="1"
                                                                            <?= ($advertisement['game_type_id'] != 0) ? 'selected' : '' ?>>
                                                                            <?= $lang['advertisement_type_game_type'] ?>
                                                                        </option>
                                                                        <option value="2"
                                                                            <?= ($advertisement['game_id'] != 0) ? 'selected' : '' ?>>
                                                                            <?= $lang['advertisement_type_game'] ?>
                                                                        </option>
                                                                        <option value="3"
                                                                            <?= ($advertisement['vendor_id'] != 0) ? 'selected' : '' ?>>
                                                                            <?= $lang['advertisement_type_vendor'] ?>
                                                                        </option>
                                                                        <option value="4"
                                                                            <?= ($advertisement['type'] == 4) ? 'selected' : '' ?>>
                                                                            <?= $lang['advertisement_type_external'] ?>
                                                                        </option>
                                                                    </select>
                                                                    </br>
                                                                    <select id="game_id" class="form-control"
                                                                        data-live-search="true" name="game_id">
                                                                        <?php
                                                                        foreach ($games as $row) {
                                                                            ?>
                                                                        <option value="<?= $row['id'] ?>"
                                                                            <?= ($row['id'] == $advertisement['game_id']) ? 'selected' : '' ?>>
                                                                            <?= $row['name'] ?>
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
                                                                            <?= ($key == $advertisement['game_type_id']) ? 'selected' : '' ?>>
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
                                                                            <?= ($row['id'] == $advertisement['vendor_id']) ? 'selected' : '' ?>>
                                                                            <?= $row['name'] ?></option>
                                                                        <?php
                                                                        }
                                                                        ?>
                                                                    </select>
                                                                    <div>
                                                                        <input type="text" class="form-control"
                                                                               id="external_url" name="external_url"
                                                                               value="<? $_GET['external_url'] ?>" />
                                                                    </div>
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
                                                        <?php
                                                        foreach ($languages as $row) {
                                                            ?>
                                                            <div class="row img" id="img_<?= $row['id'] ?>"
                                                                 style="<?= ((in_array($row['id'], $_GET['language']) || ($row['id'] == 1 && !isset($_GET['language']))) ? '' : 'display:none') ?>">
                                                                <div class="col-md-2" style="text-align:center">
                                                                    <p class="mrg-top-10 text-dark">
                                                                        <b>
                                                                            <?= $row['name'] ?>
                                                                        </b>
                                                                    </p>
                                                                </div>
                                                                <div class="col-md-5" style="text-align:center">
                                                                    <label for="img-upload_<?= $row['id'] ?>" class="pointer">
                                                                        <img src="<?= $advertisement['image'] ?>"
                                                                             id="show_image_<?= $row['id'] ?>"
                                                                             style="max-height:300px; max-width:300px" />
                                                                        <input type="hidden" name="img_image_<?= $row['id'] ?>">
                                                                        <span class="btn btn-default display-block no-mrg-btm">
                                                                            <?= $lang['choose_file'] ?>
                                                                        </span>
                                                                        <input class="d-none" type="file"
                                                                               name="image_<?= $row['id'] ?>"
                                                                               id="img-upload_<?= $row['id'] ?>">
                                                                    </label>
                                                                </div>
                                                                <div class="col-md-5" style="text-align:center">
                                                                    <label for="img-upload_pc_<?= $row['id'] ?>" class="pointer">
                                                                        <img src="<?= $advertisement['image_pc'] ?>"
                                                                             id="show_image_pc_<?= $row['id'] ?>"
                                                                             style="max-height:300px; max-width:300px" />
                                                                        <input type="hidden" name="img_image_pc_<?= $row['id'] ?>">
                                                                        <span class="btn btn-default display-block no-mrg-btm">
                                                                            <?= $lang['choose_file'] ?>
                                                                        </span>
                                                                        <input class="d-none" type="file"
                                                                               name="image_pc_<?= $row['id'] ?>"
                                                                               id="img-upload_pc_<?= $row['id'] ?>">
                                                                    </label>
                                                                </div>
                                                            </div>
                                                            <?php
                                                        }
                                                        ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="wizard-pager">
                                                <div class="">
                                                    <input type="hidden" name="id" value="<?= $_GET['id'] ?>">
                                                    <input type="hidden" name="action" value="save_advertisement">
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
    <?php
    require_once '../_bottom.php';
    ?>
</div>
<?php
require_once '../_footer.php';
?>
<script>
$(document).ready(function() {
    var language_count = <?= count($languages)?>;

    $('.selectize').selectize({
        plugins: ['remove_button'],
        create: false,
        dropdownParent: 'body'
    });

    $('#game_id').hide();
    $('#game_type_id').hide();
    $('#vendor_id').hide();
    $('#external_url').hide();
    if ("<?= $advertisement['vendor_id'] ?>" > 0) {
        $('#vendor_id').show();
    } else if ("<?= $advertisement['game_id'] ?>" > 0) {
        $('#game_id').show();
    } else if ("<?= $advertisement['game_id'] ?>" > 0) {
        $('#game_type_id').show();
    } else if ("<?= $advertisement['external_url'] ?>" != '') {
        $('#external_url').show();
    }

    //變更日期格式
    $('#date-range-picker').daterangepicker({
        startDate: '<?= $start_date ?>',
        endDate: '<?= $end_date ?>',
        locale: {
            format: 'YYYY-MM-DD'
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
        })
        $.post("<?= BASE_URL ?>ajax.php", $("#shipping-form").serialize(), function(data) {
            $.unblockUI();
            console.log(data);
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
        getBase64(file, name); // prints the base64 string
    });

    $("select#language").change(function() {
        $('.img').hide();
        $('option:selected', this).each(function() {
            $('#img_' + this.value).show();
        });
    });

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
        $('#external_url').hide();
        if (type == 1) {
            $('#game_type_id').show();
        } else if (type == 2) {
            $('#game_id').show();
        } else if (type == 3) {
            $('#vendor_id').show();
        } else if (type == 4) {
            $('#external_url').show();
        }
    });
});

//圖片轉成BASE64
function getBase64(file, name) {
    var reader = new FileReader();
    reader.readAsDataURL(file);
    reader.onload = function() {
        //RETURN後變undefined，所以先在這處理
        var image = reader.result;
        $('#show_' + name).attr('src', image);
        $('[name="img_' + name + '"]').val(image);
        return reader.result;
    };
    reader.onerror = function(error) {
        return 'Error: ', error;
    };
}
</script>