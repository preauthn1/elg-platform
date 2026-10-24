<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';

//確認權限
if (!isset($check_auth['vendor_maintenances/index'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

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
                <h4><?= $lang['menu_system_management'] ?> > <?= $lang['menu_vendor_maintenances'] ?></h4>
            </div>

            <div class="row">
                <a href="edit.php"
                    class="btn btn-inverse btn-success"><?= $lang['add'].$lang['menu_vendor_maintenances'] ?></a>
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


<script>
$(document).ready(function() {


    loadTableData(1);

    //換頁
    $(document).on('click', '.paginate_button', function(e) {
        if (e.preventDefault) {
            e.preventDefault();
        } else {
            window.event.returnValue == false;
        }
        var page = $(this).attr('data-dt-idx');
        var is_disable = $(this).hasClass('disabled');

        if (!is_disable) {
            loadTableData(page);
        }
    });

});


function loadTableData(page) {
    $.blockUI();

    var url = 'index_ajax.php?page=' + page;

    $.get(url, function(data) {
        $("#result").html(data);
        $.unblockUI();
    });
}
</script>