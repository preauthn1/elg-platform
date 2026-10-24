<div>
    <a id="today" style="float:left" class="btn btn-outline-info btn-sm"><?= $lang['today'] ?></a>
    <a id="yesterday" style="float:left" class="btn btn-outline-info btn-sm"><?= $lang['yesterday'] ?></a>
    <a id="this_week" style="float:left" class="btn btn-outline-info btn-sm"><?= $lang['this_week'] ?></a>
    <a id="last_week" style="float:left" class="btn btn-outline-info btn-sm"><?= $lang['last_week'] ?></a>
    <a id="this_month" style="float:left" class="btn btn-outline-info btn-sm"><?= $lang['this_month'] ?></a>
    <a id="last_month" style="float:left" class="btn btn-outline-info btn-sm"><?= $lang['last_month'] ?></a>

</div>


<script>
$(document).ready(function() {


    $('#today').click(function(e) {
        $("[name='start_time']").val("<?= date('Y-m-d 00:00', strtotime('+8hour')) ?>");
        $("[name='end_time']").val("<?= date('Y-m-d 23:59', strtotime('+8hour')) ?>");
    });

    $('#yesterday').click(function(e) {
        $("[name='start_time']").val("<?= date('Y-m-d 00:00', strtotime('-1day +8hour')) ?>");
        $("[name='end_time']").val("<?= date('Y-m-d 23:59', strtotime('-1day +8hour')) ?>");
    });

    $('#this_week').click(function(e) {
        $("[name='start_time']").val(
            "<?= date('Y-m-d 00:00', strtotime(- (date('N') - 1) . 'day +8hour')) ?>"
        );
        $("[name='end_time']").val(
            "<?= date('Y-m-d 23:59', strtotime((7 - date('N')) . 'day +8hour')) ?>"
        );
    });
    $('#last_week').click(function(e) {
        $("[name='start_time']").val(
            "<?= date('Y-m-d 00:00', strtotime(- (date('N') + 6) . 'day +8hour')) ?>"
        );
        $("[name='end_time']").val(
            "<?= date('Y-m-d 23:59', strtotime(- (date('N')) . 'day +8hour')) ?>"
        );
    });
    $('#this_month').click(function(e) {
        $("[name='start_time']").val("<?= date('Y-m-01 00:00', strtotime(' +8hour')) ?>");
        $("[name='end_time']").val("<?= date('Y-m-t 23:59', strtotime(' +8hour')) ?>");
    });
    $('#last_month').click(function(e) {
        $("[name='start_time']").val("<?= date('Y-m-01 00:00', strtotime('-1month +8hour')) ?>");
        $("[name='end_time']").val("<?= date('Y-m-t 23:59', strtotime('-1month +8hour')) ?>");
    });



});
</script>