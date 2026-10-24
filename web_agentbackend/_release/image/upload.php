<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';

// if (!isset($check_auth['member/index'])) {
//     echo $lang['nopermission'];
//     exit;
// }


?>
<style>
@media (min-width: 1200px) {
    .modal-xl {
        max-width: 1140px;
    }
}
</style>

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
                <h4><?= $lang['menu_system_management'] ?> > <?= $lang['menu_system_image_upload'] ?> </h4>
            </div>

            <div class="row">


                <div class="col-md-12">
                    <label for="img-upload" class="pointer">
                        <img src="" id="show_image" style="max-height:300px;max-width:300px" />
                        <input type="hidden" name="img_image">
                        <span class="btn btn-default display-block no-mrg-btm">
                            <?= $lang['choose_file'] ?>
                        </span>
                        <input class="d-none" type="file" name="image" id="img-upload">
                    </label>


                </div>


                <div class="col-md-12">
                    <hr>

                    <div id="hidePathPanel" style="display:none">
                        圖片路徑為：
                        <div id="result_path"
                            style="background: #fff3da;display: inline-block;color: #cc7949;border-radius: 5px;padding: 5px 10px;">
                        </div>

                        <button class="" style="margin-left: 15px;" onclick="copyPath()">複製文字</button>
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

    var config_param = {
        input_file_elementID: 'img-upload',
        preivew_image_elementID: 'show_image',
        input_tempUrl_elementID: 'ttttt',
        uploadCallback: function(response) {
            $("#hidePathPanel").show();
            $("#result_path").text(response.data.path);
        }
    };

    imageHelper.reigsterFileUploadAutoUploadToRemoteEvent(config_param);

});


function copyPath() {
    let text = $("#result_path").text();
    clipboardHelper.copyText(text);
    successCopyAlert();
}
</script>