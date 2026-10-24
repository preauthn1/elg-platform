<?php
require_once '../_init.php';
// 取可用模版
$data = array();
$data['agent_id'] = $_POST['id'];
$result = curlPost('api/report/numberOfPeople', $data);

$agent_number = $result['data']['agent'];
$member_number = $result['data']['member'];
// 人數都不等於0時不顯示
if ($result['code'] == 1000 && ($agent_number > 0 || $member_number > 0)) {
    ?>
<div class="card">
    <div class="card-heading border bottom">
        <h4 class="card-title"><?=$lang['agent_lower']?>/<?=$lang['member'] ?></h4>
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
    var pieAndDonutdata = [
        {key: "<?=$lang['vendor']?>：<?=$agent_number . ' ' . $lang['people']?>", y:"<?=$agent_number?>"},
        {key: "<?=$lang['member']?>：<?=$member_number . ' ' . $lang['people']?>", y:"<?=$member_number?>"}    
    ];
    
    nv.addGraph(function() {
        var chart = nv.models.pieChart()
            .x(function(d) { return d.key })
            .y(function(d) { return d.y })
            .color(['#0f9aee', '#ff3c7e'])
            .showTooltipPercent(true);
             d3.select("#pie-chart svg")
            .datum(pieAndDonutdata)
            .transition().duration(1200)
            .call(chart);
        return chart;
    });
});
</script>