<?php
require_once '../_init.php';
// 取詳細資料
if ($_GET['id']) {
    $data = array();
    $data['id'] = $_GET['id'];
    $result = curlPost('api/advertisement/view', $data);
    if ($result['code'] == 1000) {
        $advertisement = $result['data'];
    }
}
$start_date = $advertisement['start_time'] ? substr($advertisement['start_time'], 0, 10) : date('Y-m-d', strtotime('-1month'));
$end_date = $advertisement['end_time'] ? substr($advertisement['end_time'], 0, 10) : date('Y-m-d');
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
    $langeuages = $result['data'];
}
// 版位列表
$data = array();
$data['page'] = 1;
$data['per_page'] = 1000;
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
$data = array();
$data['page'] = 1;
$data['per_page'] = 1000;
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
// 廣告連結類型列表
$data = array();
$data['key'] = 'advertisement_type';
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
    header("location:" . BASE_URL . "login.php");
    exit();
} else {
    $vendors = $result['data'];
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
    <!-- Content Wrapper START -->
    <div class="main-content">
        <div class="container-fluid">
            <div class="page-title">
                <h4>系統管理 > Banner管理 > 編輯 Banner</h4>
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
                                                    <span class="title">選擇語系/版位/時間</span>
                                                </a>
                                            </li>
                                            <li class="nav-item">
                                                <a href="#step2" data-toggle="tab">
                                                    <span class="step">2</span>
                                                    <span class="title">設定國家</span>
                                                </a>
                                            </li>
                                            <li class="nav-item">
                                                <a href="#step3" data-toggle="tab">
                                                    <span class="step">3</span>
                                                    <span class="title">設定代理商</span>
                                                </a>
                                            </li>
                                            <li class="nav-item">
                                                <a href="#step4" data-toggle="tab" id="tab4">
                                                    <span class="step">4</span>
                                                    <span class="title">超連結/上傳圖片</span>
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
                                                                    <label>選擇廣告內容語系</label>
                                                                    <select id="banner_language" class="form-control"
                                                                        data-live-search="true" name="language">
                                                                        <?php
                                                                        foreach ($langeuages as $langeuage) {
                                                                            ?>
                                                                        <option value="<?= $langeuage['id'] ?>"
                                                                            <?= ($langeuage['id'] == $advertisement['language_id']) ? 'selected' : '' ?>>
                                                                            <?= $langeuage['name'] ?></option>
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
                                                                    <label>選擇版面位置</label>
                                                                    <select id="banner_language" class="form-control"
                                                                        data-live-search="true" name="path_id">
                                                                        <?php
                                                                        foreach ($paths as $path) {
                                                                            ?>
                                                                        <option value="<?= $path['id'] ?>"
                                                                            <?= ($path['id'] == $advertisement['path_id']) ? 'selected' : '' ?>>
                                                                            <?= $path['name'] ?></option>
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
                                                                    <label>選擇啟用區間</label>
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
                                                                    <table id="dt-opt"
                                                                        class="table table-lg table-hover">
                                                                        <thead>
                                                                            <tr>
                                                                                <th></th>
                                                                                <th>國家名稱</th>
                                                                            </tr>
                                                                        </thead>
                                                                        <tbody>
                                                                            <?php
                                                                            foreach ($_SESSION['country'] as $key => $value) {
                                                                                ?>
                                                                            <tr>
                                                                                <td>
                                                                                    <div class="checkbox mrg-left-20">
                                                                                        <input id="country<?= $key ?>"
                                                                                            name="country[<?= $key ?>]"
                                                                                            type="checkbox"
                                                                                            <?= strpos($advertisement['country_id'], '"' . $key . '"') ? 'checked' : '' ?>>
                                                                                        <label
                                                                                            for="country<?= $key ?>"></label>
                                                                                    </div>
                                                                                </td>
                                                                                <td>
                                                                                    <div class="list-info mrg-top-10">
                                                                                        <img class="thumb-img"
                                                                                            src="assets/images/avatars/thumb-1.jpg"
                                                                                            alt="">
                                                                                        <div class="info">
                                                                                            <span
                                                                                                class="title"><?= $value ?></span>
                                                                                        </div>
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
                                                        <div class="card">
                                                            <div class="card-block">
                                                                <div class="table-overflow">
                                                                    <table id="dt-opt1"
                                                                        class="table table-lg table-hover">
                                                                        <thead>
                                                                            <tr>
                                                                                <th></th>
                                                                                <th>營運商名稱</th>
                                                                            </tr>
                                                                        </thead>
                                                                        <tbody>
                                                                            <?php
                                                                            foreach ($agents as $agent) {
                                                                                ?>
                                                                            <tr>
                                                                                <td>
                                                                                    <div class="checkbox mrg-left-20">
                                                                                        <input
                                                                                            id="agent<?= $agent['id'] ?>"
                                                                                            name="agent[<?= $agent['id'] ?>]"
                                                                                            type="checkbox"
                                                                                            <?= strpos($advertisement['agent_id'], '"' . $agent['id'] . '"') ? 'checked' : '' ?>>
                                                                                        <label
                                                                                            for="agent<?= $agent['id'] ?>"></label>
                                                                                    </div>
                                                                                </td>
                                                                                <td>
                                                                                    <div class="list-info mrg-top-10">
                                                                                        <img class="thumb-img"
                                                                                            src="assets/images/avatars/thumb-1.jpg"
                                                                                            alt="">
                                                                                        <div class="info">
                                                                                            <span
                                                                                                class="title"><?= $agent['username'] ?></span>
                                                                                        </div>
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
                                            <div id="step4" class="tab-pane fade">

                                                <div class="row">
                                                    <div class="col-md-10 mr-auto ml-auto">
                                                        <div class="row">
                                                            <div class="col-md-12">
                                                                <div class="form-group">
                                                                    <label>超連結</label>
                                                                    <select id="type" class="form-control"
                                                                        data-live-search="true" name="type">
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
                                                                    </select>
                                                                    </br>
                                                                    <select id="game_id" class="form-control"
                                                                        data-live-search="true" name="game_id">
                                                                        <?php
                                                                        foreach ($games as $row) {
                                                                            ?>
                                                                        <option value="<?= $row['id'] ?>"
                                                                            <?= ($row['id'] == $advertisement['game_id']) ? 'selected' : '' ?>>
                                                                            <?= $row['name'] ?></option>
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
                                                                </div>
                                                            </div>
                                                            <div class="col-md-6" style="text-align:center">
                                                                <p class="mrg-top-10 text-dark"> <b>請選擇您的手機版廣告圖片</b></p>
                                                                <div>
                                                                    <label for="img-upload" class="pointer">
                                                                        <img src="<?= $advertisement['image'] ?>"
                                                                            id="show_image"
                                                                            style="max-height:300px;max-width:300px" />
                                                                        <input type="hidden" name="img_image">
                                                                        <span
                                                                            class="btn btn-default display-block no-mrg-btm">Choose
                                                                            file</span>
                                                                        <input class="d-none" type="file" name="image"
                                                                            id="img-upload">
                                                                    </label>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-6" style="text-align:center">
                                                                <p class="mrg-top-10 text-dark"> <b>請選擇您的電腦版廣告圖片</b></p>
                                                                <div>
                                                                    <label for="img-upload_pc" class="pointer">
                                                                        <img src="<?= $advertisement['image_pc'] ?>"
                                                                            id="show_image_pc"
                                                                            style="max-height:300px;max-width:300px" />
                                                                        <input type="hidden" name="img_image_pc">
                                                                        <span
                                                                            class="btn btn-default display-block no-mrg-btm">Choose
                                                                            file</span>
                                                                        <input class="d-none" type="file"
                                                                            name="image_pc" id="img-upload_pc">
                                                                    </label>
                                                                </div>
                                                            </div>
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
                                                    <input type="hidden" name="action" value="save_advertisement">
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
    if ("<?= $advertisement['vendor_id'] ?>" > 0) {
        $('#game_id').hide();
        $('#game_type_id').hide();
    } else if ("<?= $advertisement['game_id'] ?>" > 0) {
        $('#game_id').hide();
        $('#vendor_id').hide();
    } else {
        $('#game_type_id').hide();
        $('#vendor_id').hide();
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
        var name = $(this).attr('name');
        var file = document.querySelector('input[name="' + name + '"]').files[0];
        getBase64(file, name); // prints the base64 string
    });

    //上傳圖片後可預覽
    $('#img-upload_pc').change(function(e) {
        if (e.preventDefault) {
            e.preventDefault();
        } else {
            window.event.returnValue == false;
        }
        var name = $(this).attr('name');
        var file = document.querySelector('input[name="' + name + '"]').files[0];
        getBase64(file, name); // prints the base64 string
    });


    $('#type').change(function(e) {
        if (e.preventDefault) {
            e.preventDefault();
        } else {
            window.event.returnValue == false;
        }
        var type = $(this).val();
        if (type == 1) {
            $('#game_type_id').show();
            $('#game_id').hide();
            $('#vendor_id').hide();
        } else if (type == 2) {
            $('#game_type_id').hide();
            $('#game_id').show();
            $('#vendor_id').hide();
        } else if (type == 3) {
            $('#game_type_id').hide();
            $('#game_id').hide();
            $('#vendor_id').show();
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