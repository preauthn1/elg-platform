<?php
require_once '../_init.php';
// 取可用模版
$data = array();
$data['member_id'] = $_POST['id'];
$data['start_date'] = date('Y-m-d', strtotime('-90day'));
$data['end_date'] = date('Y-m-d');
$result = curlPost('api/report/deposit', $data);
// 儲值金額都不等於0時不顯示
if ($result['code'] == 1000) {
    $pie_data = array();
    foreach ($result['data'] as $row) {
        $pie_data[$row['type']] += $row['total'];
    }
    $result = curlPost('api/orderType/list');
    if ($result['code'] == 1000) {
        $type = array();
        foreach ($result['data'] as $row) {
            $type[$row['id']] = $row['name'];
        }
    }
    ?>
<div class="card">
    <div class="card-heading border bottom">
        <h4 class="card-title"><?=$lang['deposit_method']?></h4>
    </div>
    <div class="card-block">
        <div id="pie-chart">
            <svg height="450"></svg>
        </div>
    </div>
</div>
<?php
}
?>
<script>
$( document ).ready(function() {
	var pieAndDonutdata = [];
    <?php
    foreach ($pie_data as $key => $value) {
        ?>
    	pieAndDonutdata.push({ key: "<?=$type[$key] . ','. $lang['amount'] .':' . $value?>", y: "<?=$value?>" });
    <?php
    }
    ?>
    nv.addGraph(function() {
        var chart = nv.models.pieChart()
            .x(function(d) { return d.key })
            .y(function(d) { return d.y })
            .color(['#0f9aee', '#ff3c7e','#228B22','#FF4500','#9932CC'])
            .showTooltipPercent(true);
             d3.select("#pie-chart svg")
            .datum(pieAndDonutdata)
            .transition().duration(1200)
            .call(chart);
        return chart;
    });
});
</script>