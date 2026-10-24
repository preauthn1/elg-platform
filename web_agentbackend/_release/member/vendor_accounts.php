<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';

//確認權限
if (!isset($check_auth['member/vendor_accounts'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

$queryDropdown = curlPost('api/vendor/dropdownList');

$vendors = $queryDropdown['data'];

?>
<div class="page-container">
    <?php
    require_once '../_top.php';
    require_once '../_theme.php';
    ?>
    <div class="main-content">
        <div class="container-fluid">
            <div class="page-title">
                <h4><?= $lang['member_lower_management'] ?> > <?= $lang['menu_member_vendor_accounts'] ?></h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">


                            <form id="data-form" class="form-horizontal mrg-top-40 pdd-right-30">
                                <div class="row">
                                    <div class="col-md-12">



                                        <div class="form-group row">



                                            <div class="col-md-2">
                                                <?= $lang["label_vendor"]."".$lang["member_account"] ?>
                                            </div>

                                            <div class="col-md-3">
                                                <input type="text" class="form-control" name="account_name" />
                                            </div>



                                            <div class="col-md-1">
                                                <?= $lang["member_account"] ?>
                                            </div>

                                            <div class="col-md-3">

                                                <input type="text" class="form-control" name="username" />

                                            </div>



                                        </div>



                                        <div class="form-group row">

                                            <div class="col-md-2">
                                                <?= $lang["label_vendor"] ?>
                                                <span class="minus-red">*</span>
                                            </div>

                                            <div class="col-md-3">
                                                <select id="vendor_id" name="vendor_id" class="selectized_style">

                                                    <option value=" "><?= $lang["please_choose"] ?></option>

                                                    <?php foreach ($vendors as $item) { ?>

                                                    <option value="<?= $item["id"] ?>">
                                                        <?= $item["name"] ?>
                                                    </option>

                                                    <?php } ?>
                                                </select>
                                            </div>



                                            <div class="col-md-3">

                                            </div>


                                            <div class="col-md-3">
                                                <button type="reset" class="btn">
                                                    <?= $lang['clear'] ?>
                                                </button>
                                                <button type="button" class="btn btn-primary"
                                                    onclick="loadTableData(1)">
                                                    <?= $lang['search'] ?>
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
            <div class="row" id="result">
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
    //變更日期格式
    $('.datetime-picker').daterangepicker({
        singleDatePicker: true,
        timePicker: true,
        timePicker24Hour: true,
        timePickerIncrement: 1,
        locale: {
            format: 'YYYY-MM-DD HH:mm'
        }
    });

    $('.selectized_style').selectize({
        create: false,
        dropdownParent: 'body'
    });

    //loadTableData(1);

    dataTableHelper.initialTableSearchFormClearEvent();

    dataTableHelper.initialPagingEvent(loadTableData);
});


function loadTableData(page) {

    if (page == 0) {
        let pageValue = $(this).attr('data-dt-idx');;
        page = pageValue ? pageValue : 1;
    }

    $select_vendor = $("#vendor_id").val();

    if ($select_vendor === undefined || $select_vendor === "" || $select_vendor === " ") {
        alert('<?= $lang['please_choose'].' '.$lang["label_vendor"] ?>');
        return;
    }

    $.blockUI();

    var queryString = $("#data-form").serialize();
    var url = 'vendor_accounts_ajax.php?per_page=10&page=' + page + "&" + queryString
    $.get(url, function(data) {
        $("#result").html(data);
        $.unblockUI();
    });
}
</script>

<style>
.selectize-dropdown {
    z-index: 99999 !important;
}

.agent_group_type_1,
.agent_group_type_2,
.agent_group_type_3,
.agent_group_type_4 {
    font-weight: 700;
}

.agent_group_type_1 {
    color: #3f6eb4;
}

.agent_group_type_2 {
    color: #ba2121;
}

.agent_group_type_3 {
    color: #666;
}

.agent_group_type_4 {
    color: #248d1d;
}
</style>