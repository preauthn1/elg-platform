<?php
require_once '../_init.php';

$post_data = array();
$post_data["language_code"] =$_GET['language_code'];

$api_response = curlPost('api/language-text/show', $post_data);

$query_setting = $api_response["data"];

?>


<div>


    <div>
        <div class="label-light">
            <?= $lang['language_text_code'] ?>：
        </div>

        <div class="language-code">
            <?= $query_setting["language_code"] ?>
        </div>
    </div>


    <div>

        <div class="label-light">
            代理後台僅支援
        </div>


        <div>
            <?php foreach ($query_setting["available_languages"] as $item) { ?>

            <span class="label-item">
                <?= $item["name"] ?>
            </span>

            <?php } ?>
        </div>


    </div>


    <div style="margin-top: 30px;">

        <form id="update-form">

            <input type="hidden" name="action" value="update_all_language_text">
            <input type="hidden" name="language_code" value="<?= $query_setting["language_code"] ?>">

            <?php foreach ($query_setting["text_settings"] as $eachText) { ?>


            <div class="each-input">

                <span class="lang-label">
                    <?= $eachText["language_name"] ?>
                </span>

                <input type="text" class="form-control " name="<?= "language_text_".$eachText["language_id"]  ?>"
                    value="<?= $eachText["value"]  ?>">

            </div>


            <?php } ?>

        </form>

    </div>


    <div style="margin-top: 30px;">


        <button type="button" class="btn btn-primary" onclick="saveCreate()">
            <?=$lang["btn_update"] ?>
        </button>

    </div>





</div>



<script>
function saveCreate() {

    $.blockUI({
        baseZ: 9000,
        overlayCSS: {
            backgroundColor: '#444444'
        }
    });

    $form_body = $("#update-form").serialize();

    $.post("<?= BASE_URL ?>ajax.php", $form_body, function(data) {
        $.unblockUI();

        if (data.code == 1000) {
            successAlert();
            $("#editModal").modal("hide");

        } else {
            alert(data.message + "." + data.data);
        }
    }, 'json');


}
</script>


<style>
.language-code {
    color: #d54b4b;
    font-weight: 700;

}

.label-item {

    color: #666;
    background: #EEE;
    border-radius: 15px;
    /* color: #FFF; */
    padding: 5px 10px;
    margin-right: 5px;
}


.each-input {
    padding: 5px;
}


.label-light {
    color: #888;
    margin-top: 15px;
}


.lang-label {
    color: #888;
    width: 85px;
    display: inline-block;
}

.each-input input {
    width: 450px;
    display: inline-block;
}
</style>