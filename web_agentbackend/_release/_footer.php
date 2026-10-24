</div>
</div>


<div id="success-alert" class="alert alert-success alert-dismissible fade my-success-alert" role="alert"
     style="display:none;z-index:9999">
    <i class="ei-checked"></i>
    <strong>
        <div>
            <?= $lang["btn_update"] ?>&nbsp;<?= $lang["success"] ?>
        </div>

    </strong>
</div>


<div id="success-copy-alert" class="alert alert-warning alert-dismissible fade my-success-alert" role="alert"
     style="display:none;">
    <i class="ei-checked"></i>
    <strong>
        <div>
            <?= $lang["copyText"] ?>&nbsp;<?= $lang["success"] ?>
        </div>
    </strong>
</div>


<!-- page plugins js -->
<script src="<?= BASE_URL ?>assets/vendors/bower-jvectormap/jquery-jvectormap-1.2.2.min.js"></script>
<script src="<?= BASE_URL ?>assets/js/maps/jquery-jvectormap-us-aea.js"></script>
<script src="<?= BASE_URL ?>assets/vendors/d3/d3.min.js"></script>
<script src="<?= BASE_URL ?>assets/vendors/nvd3/build/nv.d3.min.js"></script>
<script src="<?= BASE_URL ?>assets/vendors/jquery.sparkline/index.js"></script>
<script src="<?= BASE_URL ?>assets/vendors/chart.js/dist/Chart.min.js"></script>
<script src="<?= BASE_URL ?>assets/js/app.min.js"></script>
<script src="<?= BASE_URL ?>assets/js/jquery.blockUI.js"></script>
<script src="<?= BASE_URL ?>assets/vendors/bower-jvectormap/jquery-jvectormap-world-mill-en.js"></script>
<script src="<?= BASE_URL ?>assets/vendors/selectize/dist/js/standalone/selectize.min.js"></script>
<script src="<?= BASE_URL ?>assets/vendors/moment/min/moment.min.js"></script>
<script src="<?= BASE_URL ?>assets/vendors/bootstrap-daterangepicker/daterangepicker.js"></script>
<script src="<?= BASE_URL ?>assets/vendors/bootstrap-datepicker/dist/js/bootstrap-datepicker.js"></script>
<script src="<?= BASE_URL ?>assets/vendors/bootstrap-timepicker/js/bootstrap-timepicker.js"></script>
<script src="<?= BASE_URL ?>assets/vendors/bootstrap-toggle/js/bootstrap-toggle.min.js"></script>
<script src="<?= BASE_URL ?>assets/vendors/summernote/dist/summernote.min.js"></script>
<script src="<?= BASE_URL ?>assets/vendors/datatables/media/js/jquery.dataTables.js"></script>
<script src="<?= BASE_URL ?>assets/js/charts/nvd3.js"></script>
<script src="<?= BASE_URL ?>assets/js/common/helper.js"></script>
<script src="<?= BASE_URL ?>assets/vendors/jquery-validation/dist/jquery.validate.min.js"></script>
<script src="<?= BASE_URL ?>assets/vendors/twitter-bootstrap-wizard/jquery.bootstrap.wizard.min.js"></script>
<script src="<?= BASE_URL ?>assets/js/cookie/js.cookie.min.js"></script>

<!-- page js -->
<script src="<?= BASE_URL ?>assets/js/maps/jvector-map.js"></script>
<script src="<?= BASE_URL ?>assets/js/forms/form-elements.js"></script>
<script src="<?= BASE_URL ?>assets/js/table/data-table.js"></script>
<script src="<?= BASE_URL ?>assets/js/forms/form-wizard.js"></script>


<script>
    function successAlert() {
        alertHelper.successAlert();
    }

    function successCopyAlert() {
        alertHelper.successCopyAlert();
    }

    $(document).ready(function () {
        $(document).on('change', 'input[id=toggle_thousands]', function () {
            var status = $(this).attr('data-status');
            $.post("<?= BASE_URL ?>ajax.php", {
                'action': 'toggle_thousands',
                'toggle_thousands': status
            }, function (data) {
                if (status == 0) {
                    $(".thousands_off").each(function () {
                        var val = parseInt(RemoveComma($(this).text()));
                        if(!isNaN(val)){
                            var balance = ( val / 1000);
                            // $(this).text(balance.toFixed(2) + "K");
                            $(this).text(DecimalNumber(balance, 2) + "K");
                            $(this).addClass('thousands_on').removeClass('thousands_off');
                        }
                    });
                    // $(".thousands_off_4").each(function () {
                    //     var val = parseInt(RemoveComma($(this).text()));
                    //     console.log(isNaN(val));
                    //     if(!isNaN(val)){
                    //         var balance = (RemoveComma($(this).text()) / 1000);
                    //         $(this).text(balance.toFixed(4) + "K");
                    //         $(this).addClass('thousands_on_4').removeClass('thousands_off_4');
                    //     }
                    // });
                    $('input[name=toggle_status]').attr('data-status', 1);
                } else {
                    $(".thousands_on").each(function () {
                        var amount = $(this).attr('data-original');
                        $(this).text(amount);
                        $(this).addClass('thousands_off').removeClass('thousands_on');
                    });
                    // $(".thousands_on_4").each(function () {
                    //     var amount = $(this).attr('data-original');
                    //     $(this).text(amount);
                    //     $(this).addClass('thousands_off_4').removeClass('thousands_on_4');
                    // });
                    $('input[name=toggle_status]').attr('data-status', 0);
                }
            });
        });
    });

    //將有千分位的數值轉為一般數字
    function RemoveComma(n) {
        return n.replace(/[,]+/g, '');
    }

    //無條件捨去小數點後位數
    function DecimalNumber(num, point){
        var place = Math.pow(10, point);
        var amount = num * place;
        if (num > 0) {
            return (Math.floor(amount) / place).toFixed(point);
        } else {
            return (Math.ceil(amount) / place).toFixed(point);
        }

    }
</script>


</body>

</html>