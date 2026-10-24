<?php
require_once '../_init.php';
// 取詳細資料
if ($_GET['id']) {
    $data = array();
    $data['id'] = $_GET['id'];
    $result = curlPost('api/vendor/view', $data);
    if ($result['code'] == 1000) {
        $vendor = $result['data'];
    }

    $agents = curlPost('api/agentVendor/list', ['vendor_id' =>  $_GET['id']]);
    if ($agents['code'] == 1000) {
        $agents = $agents['data'];
    }
} else {
    echo '請選擇遊戲商';
    exit;
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
                <h4>遊戲商管理 > 廠商管理 > 編輯</h4>
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
                                                <div class="col-md-12">
                                                    <div class="form-group">
                                                        <label>名稱</label>
                                                        <input type="text" name="name" value="<?= $vendor['name'] ?>"
                                                            class="form-control">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-4">
                                                    <div class="form-group">
                                                        <label>轉點匯率</label>
                                                        <input type="text" placeholder="1:xx" name="rate"
                                                            value="<?= $vendor['rate'] ?>" class="form-control">
                                                    </div>
                                                    <div class="form-group">
                                                        <label>拆帳比</label>
                                                        <input type="text" placeholder="ex: 20%" name="percentage"
                                                            value="<?= $vendor['percentage'] ?>" class="form-control">
                                                    </div>
                                                    <div class="form-group">
                                                        <label>排序</label>
                                                        <input type="text" placeholder="ex: 1" name="sort"
                                                            value="<?= $vendor['sort'] ?>" class="form-control">
                                                    </div>
                                                    <div class="form-group">
                                                        <label>狀態</label>
                                                        <div class="col-md-10">
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="status" value="1" id="rad1"
                                                                    <?= ($vendor['status'] == 1) ? 'checked' : '' ?>>
                                                                <label for="rad1">啟用</label>
                                                            </div>
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="status" value="0" id="rad2"
                                                                    <?= ($vendor['status'] == 0) ? 'checked' : '' ?>>
                                                                <label for="rad2">停用</label>
                                                            </div>
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="status" value="2" id="rad3"
                                                                    <?= ($vendor['status'] == 2) ? 'checked' : '' ?>>
                                                                <label for="rad3">開發中</label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="form-group">
                                                        <p class="mrg-btm-15 font-size-13">遊戲商圖片</p>
                                                        <div>
                                                            <label class="pointer">
                                                                <img src="<?= $vendor['image'] ?>" id="show_image"
                                                                    style="max-height:200px;max-width:200px" />
                                                                <input type="hidden" name="image">
                                                                <span
                                                                    class="btn btn-default display-block no-mrg-btm">Choose
                                                                    file</span>
                                                                <input class="d-none upload_image" type="file"
                                                                    data-location='image'>
                                                            </label>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="form-group">
                                                        <p class="mrg-btm-15 font-size-13">遊戲商APP ICON</p>
                                                        <div>
                                                            <label class="pointer">
                                                                <img src="<?= $vendor['app_icon'] ?>" id="show_app_icon"
                                                                    style="max-height:200px;max-width:200px" />
                                                                <input type="hidden" name="app_icon">
                                                                <span
                                                                    class="btn btn-default display-block no-mrg-btm">Choose
                                                                    file</span>
                                                                <input class="d-none upload_image" type="file"
                                                                    data-location='app_icon'>
                                                            </label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-12">
                                                    <label>設定營運商是否顯示此遊戲商</label>
                                                    <?php foreach ($agents as $agent) { ?>
                                                    <div class="row">
                                                        <div class="col-md-2" style="line-height: 40px;">
                                                            <?= $agent['username'] ?>
                                                        </div>
                                                        <div class="col-md-2">
                                                            <div class="radio radio-inline">
                                                                <input type="radio"
                                                                    name="agent<?= $agent['agent_id'] ?>" value="1"
                                                                    id="a1<?= $agent['agent_id'] ?>"
                                                                    <?= ($agent['vendor_status'] == 1 || $agent['vendor_status'] == 0) ? 'checked' : '' ?>>
                                                                <label for="a1<?= $agent['agent_id'] ?>">顯示</label>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-2">
                                                            <div class="radio radio-inline">
                                                                <input type="radio"
                                                                    name="agent<?= $agent['agent_id'] ?>" value="0"
                                                                    id="a0<?= $agent['agent_id'] ?>"
                                                                    <?= ($agent['vendor_status'] === null) ? 'checked' : '' ?>>
                                                                <label for="a0<?= $agent['agent_id'] ?>">不顯示</label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <?php } ?>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-12 col-xs-6">
                                                    <div class="text-right mrg-top-5">
                                                        <input type="hidden" name="action" value="save_vendor">
                                                        <input type="hidden" name="id" value="<?= $vendor['id'] ?>">
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
<script>
$(document).ready(function() {
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
    $('.upload_image').change(function(e) {
        if (e.preventDefault) {
            e.preventDefault();
        } else {
            window.event.returnValue == false;
        }
        var file = $(this).prop('files')[0];
        var location = $(this).attr('data-location');
        getBase64(file, location); // prints the base64 string
    });

});

//圖片轉成BASE64
function getBase64(file, location) {
    var reader = new FileReader();
    reader.readAsDataURL(file);
    reader.onload = function() {
        //RETURN後變undefined，所以先在這處理
        var image = reader.result;
        $('#show_' + location).attr('src', image);
        $('[name="' + location + '"]').val(image);
        return reader.result;
    };
    reader.onerror = function(error) {
        return 'Error: ', error;
    };
}
</script>