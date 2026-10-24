<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';


$queryDropdown = curlPost('api/vendor/dropdownList');
$vendors = $queryDropdown['data'];

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
                <h4>
                    <?= $lang['member_lower_management'] ?> > 數據一致性比對
                </h4>
            </div>

            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="row searchRow">

                                <div class="col-md-2">

                                    <label>
                                        請輸入會員帳號
                                    </label>

                                    <input type="text" class="form-control" id="username" placeholder="member username"
                                        name="username" value="">

                                </div>




                                <div class="col-md-2">

                                    <label>
                                        <?= $lang["label_vendor"] ?>
                                    </label>

                                    <div>
                                        <select id="vendor_id" name="vendor_id" class="selectized_style">

                                            <?php foreach ($vendors as $item) { ?>

                                            <option value="<?= $item["id"] ?>">
                                                <?= $item["name"] ?>
                                            </option>

                                            <?php } ?>
                                        </select>

                                    </div>

                                </div>



                                <div class="col-md-2">

                                    <label>
                                        起始時間
                                    </label>

                                    <div>

                                        <input type="text" id="start_time" class="form-control datetime-picker"
                                            name="start_time"
                                            value="<?=  date('Y-m-d 00:00', strtotime('-168 hour')) ?>" />

                                    </div>

                                </div>





                                <div class="col-md-2">

                                    <label>
                                        結算時間
                                    </label>

                                    <div>

                                        <input type="text" id="end_time" class="form-control datetime-picker"
                                            name="end_time" value="<?= date('Y-m-d 23:59', strtotime('+8hour')) ?>" />

                                    </div>

                                </div>



                                <div class="col-md-1">

                                    <button type="button" class="btn" onclick="search()" style="margin-top: 25px;">
                                        <?=$lang["search"] ?>
                                    </button>

                                </div>


                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row" id="result">
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
<div class="modal fade" id="flow_limit">
    <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content" id="flow_limit_content">
        </div>
    </div>
</div>


<style>
.searchRow label {

    margin-bottom: 0;
    color: #867e7e;
}
</style>



<script>
$(document).ready(function() {

    $('.selectized_style').selectize({
        create: false,
        dropdownParent: 'body'
    });


    $('.datetime-picker').daterangepicker({
        singleDatePicker: true,
        timePicker: true,
        timePicker24Hour: true,
        timePickerIncrement: 1,
        locale: {
            format: 'YYYY-MM-DD HH:mm'
        }
    });


});


function search() {

    let username = $("#username").val();
    let vendor_id = $("#vendor_id").val();
    let start_time = $("#start_time").val();
    let end_time = $("#end_time").val();

    if (username === undefined || username == "") {
        alert('please input username.');
        return;
    }

    $.blockUI();

    let url = "compare_summaryBet_vs_totalBet_ajax.php?username=" + username + '&vendor_id=' + vendor_id +
        '&start_time=' + start_time + '&end_time=' + end_time;

    $.get(url, function(data) {
        $("#result").html(data);
        $.unblockUI();
    });


}
</script>