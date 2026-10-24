<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';
?>
<!-- Page Container START -->
<div class="page-container">
    <?php
    require_once '../_top.php';
    require_once '../_theme.php';
    // 廠商列表
    $result = curlPost('api/vendor/list');
    if ($result['code'] == 1000) {
        $vendors = array();
        foreach ($result['data'] as $row) {
            $vendors[$row['id']] = $row['name'];
        }
    }
    // 遊戲類型
    $result = curlPost('api/gameType/list');
    if ($result['code'] == 1000) {
        $types = array();
        foreach ($result['data'] as $row) {
            if ($row['parent'] == 0) {
                $types[$row['id']] = $row['name'];
            } else {
                $types[$row['id']] = $types[$row['parent']] . '>' . $row['name'];
            }
        }
    }
    $data = [
        'page' => 1,
        'per_page' => 1000
    ];
    if ($_GET['id']) {
        $data['id'] = $_GET['id'];
        $result = curlPost('api/game/view', $data);
        if ($result['code'] != 1000) {
            if ($result['code'] > 9997) {
                header("location:" . BASE_URL . "login.php");
            } else {
                header('Location:' . $_SERVER['HTTP_REFERER']);
            }
            exit();
        }
        $game = $result['data'];
        $language_data = array();
        foreach ($game['names'] as $row) {
            $language_data[$row['language_id']] = $row;
        }
    }
    ?>
    <!-- Content Wrapper START -->
    <div class="main-content">
        <div class="container-fluid">
            <div class="page-title">
                <h4>遊戲商管理 > 廠商管理 > 遊戲管理 > 編輯遊戲</h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="mrg-top-40">
                                <div class="row">
                                    <div class="col-md-8 ml-auto mr-auto">
                                        <form id="data-form">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label>遊戲廠商</label>
                                                        <select id="selectize-dropdown1" name="vendor_id">
                                                            <?php
                                                            foreach ($vendors as $key => $value) {
                                                            ?>
                                                            <option value="<?= $key ?>"
                                                                <?= ($game['vendor_id'] == $key) ? 'selected' : '' ?>>
                                                                <?= $value ?></option>
                                                            <?php
                                                            }
                                                            ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label>遊戲類型</label>
                                                        <select id="selectize-dropdown2" name="type_id">
                                                            <?php
                                                            foreach ($types as $key => $value) {
                                                            ?>
                                                            <option value="<?= $key ?>"
                                                                <?= ($game['type_id'] == $key) ? 'selected' : '' ?>>
                                                                <?= $value ?></option>
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
                                                                <a href="#default" class="nav-link active" role="tab"
                                                                    data-toggle="tab">預設</a>
                                                            </li>
                                                            <?php
                                                            foreach ($_LANGUAGES as $key => $value) {
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
                                                            <div role="tabpanel" class="tab-pane fade in active"
                                                                id="default">
                                                                <div class="form-group">
                                                                    <p class="mrg-btm-15 font-size-13"><span
                                                                            style="color:red">*</span>遊戲名稱</p>
                                                                    <input type="text" class="form-control" name="name"
                                                                        value="<?= $game['name'] ?>">
                                                                </div>
                                                            </div>
                                                            <?php
                                                            foreach ($_LANGUAGES as $key => $value) {
                                                            ?>
                                                            <div role="tabpanel" class="tab-pane fade"
                                                                id="pills-vertical-<?= $value['id'] ?>">
                                                                <div class="form-group">
                                                                    <p class="mrg-btm-15 font-size-13">遊戲名稱</p>
                                                                    <input type="text" class="form-control"
                                                                        name="names[<?= $value['id'] ?>]"
                                                                        value="<?= $language_data[$value['id']]['name'] ?>">
                                                                </div>
                                                            </div>
                                                            <?php
                                                            }
                                                            ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label>遊戲代碼</label>
                                                        <input type="text" name="game_type"
                                                            value="<?= $game['game_type'] ?>" class="form-control">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label>顯示排序(數字越大越前)</label>
                                                        <input type="text" name="sort"
                                                            value="<?= $game['sort'] ? $game['sort'] : 0 ?>"
                                                            class="form-control"
                                                            oninput="value=value.replace(/[^\d\-]/g,'')" maxlength="8">
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="form-group">
                                                        <label>顯示新遊戲標籤</label>
                                                        <div class="col-md-10">
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="is_new" value="1" id="n1"
                                                                    <?= ($game['is_new'] == 1) ? 'checked' : '' ?>>
                                                                <label for="n1">顯示</label>
                                                            </div>
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="is_new" value="0" id="n2"
                                                                    <?= ($game['is_new'] != 1) ? 'checked' : '' ?>>
                                                                <label for="n2">不顯示</label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="form-group">
                                                        <label>顯示熱門遊戲標籤</label>
                                                        <div class="col-md-10">
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="is_hot" value="1" id="h1"
                                                                    <?= ($game['is_hot'] == 1) ? 'checked' : '' ?>>
                                                                <label for="h1">顯示</label>
                                                            </div>
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="is_hot" value="0" id="h2"
                                                                    <?= ($game['is_hot'] != 1) ? 'checked' : '' ?>>
                                                                <label for="h2">不顯示</label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="form-group">
                                                        <label>狀態</label>
                                                        <div class="col-md-10">
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="status" value="1" id="rad1"
                                                                    <?= ($game['status'] == 1 || !isset($game['status'])) ? 'checked' : '' ?>>
                                                                <label for="rad1">啟用</label>
                                                            </div>
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="status" value="0" id="rad2"
                                                                    <?= ($game['status'] != 1 and isset($game['status'])) ? 'checked' : '' ?>>
                                                                <label for="rad2">停用</label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="form-group">
                                                        <label>是否可參與投注活動</label>
                                                        <div class="col-md-10">
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="join_bet_event" value="1"
                                                                    id="b1"
                                                                    <?= ($game['join_bet_event'] == 1) ? 'checked' : '' ?>>
                                                                <label for="b1">顯示</label>
                                                            </div>
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="join_bet_event" value="0"
                                                                    id="b2"
                                                                    <?= ($game['join_bet_event'] != 1) ? 'checked' : '' ?>>
                                                                <label for="b2">不顯示</label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="form-group">
                                                        <label>是否可參與贏分活動</label>
                                                        <div class="col-md-10">
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="join_win_event" value="1"
                                                                    id="w1"
                                                                    <?= ($game['join_win_event'] == 1) ? 'checked' : '' ?>>
                                                                <label for="w1">顯示</label>
                                                            </div>
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="join_win_event" value="0"
                                                                    id="w2"
                                                                    <?= ($game['join_win_event'] != 1) ? 'checked' : '' ?>>
                                                                <label for="w2">不顯示</label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="form-group">
                                                        <label>無限代有效投注轉換比例</label>
                                                        <input type="text" name="vaild_bet_rate"
                                                            value="<?= $game['vaild_bet_rate'] ?>" class="form-control">
                                                    </div>
                                                </div>
                                                <div class="col-md-12">
                                                    <div class="form-group">
                                                        <label>圖片</label>
                                                        <div>
                                                            <label for="img-upload" class="pointer">
                                                                <img src="<?= $game['image'] ?>" id="show_image"
                                                                    style="max-height:300px;max-width:300px" />
                                                                <input type="hidden" name="image">
                                                                <span
                                                                    class="btn btn-default display-block no-mrg-btm">Choose
                                                                    file</span>
                                                                <input class="d-none" type="file" name="image_file"
                                                                    id="img-upload">
                                                            </label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-12 col-xs-6">
                                                    <div class="text-right mrg-top-5">
                                                        <input type="hidden" name="action" value="save_game">
                                                        <input type="hidden" name="id" value="<?= $_GET['id'] ?>">
                                                        <input type="button" class="btn btn-default" value="回上頁"
                                                            onclick="history.back();" />
                                                        <button type="submit" class="btn btn-primary"
                                                            id="save">儲存</button>
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
<script type="text/javascript">
$(document).ready(function() {
    $('#selectize-dropdown1').selectize({
        create: false,
        sortField: {
            field: 'text',
            direction: 'asc'
        },
        dropdownParent: 'body'
    });

    $('#selectize-dropdown2').selectize({
        create: false,
        sortField: {
            field: 'text',
            direction: 'asc'
        },
        dropdownParent: 'body'
    });

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
        $.post("<?= BASE_URL ?>ajax.php", $("#data-form").serialize(), function(data) {
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
});
//圖片轉成BASE64
function getBase64(file) {
    var reader = new FileReader();
    reader.readAsDataURL(file);
    reader.onload = function() {
        //RETURN後變undefined，所以先在這處理
        var image = reader.result;
        $('#show_image').attr('src', image);
        $('[name="image"]').val(image);
        return reader.result;
    };
    reader.onerror = function(error) {
        return 'Error: ', error;
    };
}
</script>