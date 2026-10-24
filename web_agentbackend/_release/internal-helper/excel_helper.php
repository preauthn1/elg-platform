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
    
    
$debug = $lang;

$tttt =  array_keys( $lang);
$debug111 = $lang;


    ?>
    <!-- Content Wrapper START -->
    <div class="main-content">
        <div class="container-fluid">
            <div class="page-title">
                <h4> 系統管理 > Excel轉多語系
                </h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="mrg-top-20">
                                <div class="row">



                                    <div class="col-md-8">


                                        <div style="margin-bottom:15px;">

                                            <h5>
                                                請在此複製貼上excel格式

                                                <a class="my-link-text" target="_blank"
                                                    href="https://example.invalid/spreadsheets/d/1DQlCWspgpc_bv9IwFRs8NxYbcrOIlR7rbA3vrh6gyL4/edit?usp=sharing">
                                                    Excel多語系連結
                                                </a>


                                                <a class="my-link-text" target="_blank"
                                                    href="https://example.invalid/cdn-betmonsters-com-cdn-bucket/161673255753.png">
                                                    示意圖範例
                                                </a>
                                            </h5>

                                        </div>


                                        <div style="margin-left:15px;">

                                            <div style="font-size: 10px;color: #999;">
                                                多語系分類
                                            </div>

                                            <select id="applicationType" class="form-control" style="width:300px;"
                                                name="applicationType">

                                                <option value="1">API.Agent</option>
                                                <option value="51">Web.AgentBackend</option>
                                                <option value="0">無分類(不檢查)</option>

                                            </select>


                                            <textarea style="margin-top:5px;color:#888;" id="excel_content"
                                                placeholder="貼上Excel格式" class="form-control"></textarea>

                                        </div>

                                        <div style="margin-top:15px;">

                                            <button class="btn btn-success" onclick="submitContent()">
                                                開始轉換
                                            </button>
                                            <!-- 
                                            <div style="float: right;padding: 10px;">
                                                <input id="isCheck" type="checkbox" checked="checked" />
                                                <label for="isCheck">
                                                    檢查未定義鍵值
                                                </label>
                                            </div> -->

                                        </div>

                                        <div id="preview" style="margin-top:75px;">
                                        </div>

                                        <div id="hint-text" class="hint-text" style="display:none;">
                                            <i class="fa fa-exclamation-circle"
                                                style="margin-right:5px;"></i>若有文案未同步到Excel，可能導致文案覆蓋 (版控commit時請檢查下)
                                        </div>


                                        <div id="undefined_columns_warning" class="undefined_warning"
                                            style="display:none;">



                                            <button class="btn btn-danger"
                                                style="background:#ff8b68;border-color:#ffc7da;"
                                                onclick="copyUndefinedText()">
                                                複製缺少的欄位至剪貼簿
                                            </button>

                                            <div>
                                                <i class="fa fa-exclamation-circle" style="margin-right:5px;"></i>
                                                警告！系統檢查到 【<span id="numberOfUndefined"></span>筆】 未定義的多語系鍵值
                                            </div>


                                            <ul>
                                                <li>方法1：將複製缺少的欄位貼上到Google Sheet，並再重新將Excel格式貼至此</li>
                                                <li>方法2：下拉選單選擇【無分類（不檢查）】 </li>
                                            </ul>


                                        </div>

                                    </div>


                                    <div class="col-md-4">


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
var globalResultTemp = null;
var undefined_copy_text = null;

$(document).ready(function() {

});

function submitContent() {

    let isCheck = $('#isCheck').is(":checked") ? "1" : "0";
    let applicationType = $("#applicationType").val();

    let form = "action=helper_convert_excel_to_langFileFormat&applicationType=" + applicationType + "&isCheck=" +
        isCheck + "&excel_content=" + encodeURIComponent($("#excel_content").val());

    $.blockUI();

    $.post("<?= BASE_URL ?>ajax.php", form, function(data) {

        $.unblockUI();

        //存在全域變數
        globalResultTemp = data.data;
        undefined_copy_text = "";

        $("#preview").html('');
        $("#undefined_columns_warning").hide();
        $("#hint-text").hide();

        if (data.data.undefined_columns && data.data.undefined_columns.length > 0) {

            let numberOfUndefined = data.data.undefined_columns.length;
            $("#numberOfUndefined").text(numberOfUndefined);
            $("#undefined_columns_warning").show();

            data.data.undefined_columns.forEach(column => {
                undefined_copy_text = undefined_copy_text + column.file_name + "\t" + column.lang_key +
                    "\t" + "XXXXXXXXXXXXXX" + "\t" + "XXXXXXXXXXXXXX" + "\n";
            });

        } else {

            $("#hint-text").show();
            data.data.list_files.forEach(file_name => {

                $div = "";
                $div += "<div>";
                $div += "<h5>" + file_name + "</h5>";

                let indexPosition = 0;
                data.data.list_langs.forEach(lang => {

                    let cssStyle = "btn btn-primary no-float";
                    let clickEvent = "copyText(\"" + file_name + "\" , " + indexPosition + ")";
                    let text = "複製(" + lang + ")";
                    $div += "<button class='" + cssStyle + "' onclick='" + clickEvent + "' >" +
                        text + "</button>";

                    indexPosition++;
                });

                $div += "</div>";
                $("#preview").append($div);
            });
        }

    }, 'json');

}

function copyText(fileName, indexPosition) {

    let text = globalResultTemp.file_outCode_result[fileName][indexPosition];
    clipboardHelper.copyText(text);
    successCopyAlert();

}


function copyUndefinedText() {

    clipboardHelper.copyText(undefined_copy_text);
    successCopyAlert();
}
</script>

<style>
.my-link-text {
    margin-left: 15px;
    font-size: 12px;
    text-decoration: underline !important;
    color: #679fc1;
    cursor: pointer;
}

.no-float {
    float: none;
}

h5 {
    font-weight: 700;
}

.btn:hover {
    opacity: .8;
}


.hint-text {
    padding: 5px 15px;
    margin-left: 10px;
    margin-bottom: 5px;
    background: #dfe7ff;
    color: #4b78b1;
    display: inline-block;
}


.undefined_warning {
    padding: 10px 25px;
    background: #ffe9d6;
    border-radius: 5px;
    color: #dc3131;
}

#numberOfUndefined {}
</style>