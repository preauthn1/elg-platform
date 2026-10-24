<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';
require_once '../commonFunction.php';

if (!isset($check_auth['transfer_limit/index'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

$data = array();
$data['limit_id'] = $_GET['id'];
$result = curlPost('api/transfer_limit/getLimitInfo', $data);
$limit = $result['data'];


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
                <h4><?= $lang['agnet_management'] ?> > <?= $lang['menu_vendor_maintenances'] ?></h4>
            </div>
            <div class="row" id="result">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <form id="data-form">
                                <div>
                                    <div class="form-group row">
                                        <input type="hidden" id="id"
                                               value="<?= $limit['id'] ?>">
                                        <label class="col-md-2 control-label"><?= $lang['group_name'] ?></label>
                                        <label class="col-md-2 control-label"><?= $limit['name'] ?></label>
                                    </div>
                                </div>
                                <div>
                                    <div class="form-group row">
                                        <label class="col-md-2 control-label"><?= $lang['limit_amount'] ?></label>
                                        <input type="hidden" id="limit"
                                               value="<?= $limit['limit'] ?>">
                                        <?php if ($_SESSION['toggle_thousands'] == 0) {
                                            amount_thousands_off($limit['limit']);
                                        } else {
                                            amount_thousands_on($limit['limit']);
                                        }?>
                                    </div>
                                </div>
                                <div>
                                    <div class="form-group row">
                                        <label class="col-md-2 control-label"><?= $lang['adjustment_amount'] ?> </label>
                                        <div class="col-md-3">
                                            <input type="text" class="form-control" id="amount">
                                        </div>
                                    </div>
                                </div>
                                <div>
                                    <div class="form-group row">
                                        <label class="col-md-2 control-label"><?= $lang['memo'] ?> </label>

                                        <div class="col-md-3">
                                           <textarea class="form-control" id="memo"></textarea>
                                        </div>
                                    </div>
                                </div>
                            </form>
                            <div class="row">
                                <div class="col-md-5 col-xs-6">
                                    <div class="text-right mrg-top-5">
                                        <a href="index.php">
                                            <button type="button" class="btn"><?= $lang['btn_back'] ?></button>
                                        </a>
                                        <button href="" data-toggle="modal" data-target="#check_adjustment"
                                                data-uid="1" id="adjustment"
                                                class="btn btn-danger"><?= $lang['reduce'] ?></button>
                                        <button href="" data-toggle="modal" data-target="#check_adjustment"
                                                data-uid="0" id="adjustment"
                                                class="btn btn-success"><?= $lang['plus'] ?></button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="modal fade" id="check_adjustment">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content" id="limit_list">
                123456
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
    $(document).ready(function () {
        $('.datetime-picker').daterangepicker({
            singleDatePicker: true,
            timePicker: true,
            timePicker24Hour: true,
            timePickerIncrement: 1,
            locale: {
                format: 'YYYY-MM-DD HH:mm'
            }
        });

        //開啟確認視窗
        $(document).on('click', '#adjustment', function (e) {
            //先清內容
            $("#check_adjustment").html('');
            var id = $("#id").val();
            var type = $(this).attr('data-uid');
            var limit = parseFloat($("#limit").val());
            var amount = parseFloat($("#amount").val());
            var result = type == 0 ? limit + amount : limit - amount;
            $.post("limit_list.php", {
                'id': id,
                'type': type,
                'limit': limit,
                'amount': amount,
                'result': result
            }, function (data) {
                $("#check_adjustment").html(data);
            });
        });


        $("#save").click(function (e) {
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
            $.post("<?= BASE_URL ?>ajax.php", $("#data-form").serialize(), function (data) {
                $.unblockUI();
                if (data.code == 1000) {
                    alert(data.message);
                    window.location = 'index.php';
                } else {
                    if (data.code == '2002') {
                        alert(data.data);
                    } else {
                        alert(data.message);
                    }
                }
            }, 'json');
        });


    });
</script>

<style>
    .form-group label {
        padding-top: 10px;
    }
</style>