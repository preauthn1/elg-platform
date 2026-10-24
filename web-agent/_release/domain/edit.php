<?php
require_once '../_init.php';
// 取詳細資料
if ($_GET['id']) {
    $data = array();
    $data['id'] = $_GET['id'];
    $result = curlPost('api/agentDomain/view', $data);
    if ($result['code'] == 1000) {
        $domain = $result['data'];
    }
}
$result = curlPost('api/template/list');
if ($result['code'] == 1000) {
    $template = $result['data'];
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
                <h4><?=$lang['system_management']?> > <?=$lang['management_domain_template']?> > <?=$lang['edit_template']?></h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="mrg-top-40">
                                <div class="row">
                                    <div class="col-md-8 ml-auto mr-auto">
                                        <form id="data-form">
                                            <div class="col-md-10 mr-auto ml-auto">
                                                <div class="row">
                                                    <div class="col-md-12">
                                                        <div class="form-group">
                                                            <label><?=$lang['website_name']?></label>
                                                            <input type="text" class="form-control" name="title"
                                                                value="<?= $domain['title'] ?>" />
                                                        </div>
                                                    </div>
                                                    <div class="col-md-4" style="text-align:center">
                                                        <p class="mrg-top-10 text-dark"> <b><?=$lang['choose_pc_logo']?></b></p>
                                                        <div>
                                                            <label for="pc-upload" class="pointer">
                                                                <img src="<?= $domain['logo_pc'] ?>" id="show_logo_pc"
                                                                    style="max-height:300px;max-width:300px" />
                                                                <input type="hidden" name="logo_pc">
                                                                <span
                                                                    class="btn btn-default display-block no-mrg-btm"><?=$lang['choose_file']?></span>
                                                                <input class="d-none" type="file" name="pc"
                                                                    id="pc-upload">
                                                            </label>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-4" style="text-align:center">
                                                        <p class="mrg-top-10 text-dark"> <b><?=$lang['choose_mobile_logo']?></b></p>
                                                        <div>
                                                            <label for="mobile-upload" class="pointer">
                                                                <img src="<?= $domain['logo_mobile'] ?>"
                                                                    id="show_logo_mobile"
                                                                    style="max-height:300px;max-width:300px" />
                                                                <input type="hidden" name="logo_mobile">
                                                                <span
                                                                    class="btn btn-default display-block no-mrg-btm"><?=$lang['choose_file']?></span>
                                                                <input class="d-none" type="file" name="mobile"
                                                                    id="mobile-upload">
                                                            </label>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-4" style="text-align:center">
                                                        <p class="mrg-top-10 text-dark"> <b><?=$lang['choose_favorite_icon']?></b></p>
                                                        <div>
                                                            <label for="icon-upload" class="pointer">
                                                                <img src="<?= $domain['icon'] ?>" id="show_logo_icon"
                                                                    style="max-height:300px;max-width:300px" />
                                                                <input type="hidden" name="logo_icon">
                                                                <span
                                                                    class="btn btn-default display-block no-mrg-btm"><?=$lang['choose_file']?></span>
                                                                <input class="d-none" type="file" name="icon"
                                                                    id="icon-upload">
                                                            </label>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-12">
                                                        <div class="form-group">
                                                            <p class="mrg-top-10 text-dark"> <b><?=$lang['choose_template']?></b></p>
                                                        </div>
                                                    </div>
                                                    <?php
                                                    foreach ($template as $row) {
                                                        ?>
                                                    <div class="col-md-6">
                                                        <div class="form-group">
                                                            <label><input type="radio" name="template_id"
                                                                    value="<?= $row['id'] ?>"
                                                                    <?= ($domain['template_id'] == $row['id']) ? 'checked' : '' ?>><?= $row['name'] ?></label>
                                                            <p><img src="<?= $row['thumbnail'] ?>" id="show_logo_mobile"
                                                                    style="max-height:300px;max-width:300px" /></p>
                                                        </div>
                                                    </div>
                                                    <?php
                                                    }
                                                    ?>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-12 col-xs-6">
                                                    <div class="text-right mrg-top-5">
                                                        <input type="hidden" name="action" value="save_domain">
                                                        <input type="hidden" name="id" value="<?= $domain['id'] ?>">
                                                        <input type="button" class="btn btn-default" value="<?=$lang['btn_back']?>"
                                                            onclick="history.back();" />
                                                        <button type="submit" class="btn btn-primary"
                                                            id="save"><?=$lang['btn_update']?></button>
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
    $('#pc-upload').change(function(e) {
        if (e.preventDefault) {
            e.preventDefault();
        } else {
            window.event.returnValue == false;
        }
        var name = $(this).attr('name');
        var file = document.querySelector('input[name="' + name + '"]').files[0];
        getBase64(file, name); // prints the base64 string
    });
    $('#mobile-upload').change(function(e) {
        if (e.preventDefault) {
            e.preventDefault();
        } else {
            window.event.returnValue == false;
        }
        var name = $(this).attr('name');
        var file = document.querySelector('input[name="' + name + '"]').files[0];
        getBase64(file, name); // prints the base64 string
    });

    $('#icon-upload').change(function(e) {
        if (e.preventDefault) {
            e.preventDefault();
        } else {
            window.event.returnValue == false;
        }
        var name = $(this).attr('name');
        var file = document.querySelector('input[name="' + name + '"]').files[0];
        getBase64(file, name); // prints the base64 string
    });

});
//圖片轉成BASE64
function getBase64(file, name) {
    var reader = new FileReader();
    reader.readAsDataURL(file);
    reader.onload = function() {
        //RETURN後變undefined，所以先在這處理
        var image = reader.result;
        $('#show_logo_' + name).attr('src', image);
        $('[name="logo_' + name + '"]').val(image);
        return reader.result;
    };
    reader.onerror = function(error) {
        return 'Error: ', error;
    };
}
</script>