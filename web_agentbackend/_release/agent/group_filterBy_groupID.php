<?php
require_once '../_init.php';


$queryAgents = curlPost('api/agent/filter-by-group', $_GET);

$test = "";

?>

<div class="row">

    <?php foreach ($queryAgents["data"] as $item) {   ?>

    <div class="col-md-4">


        <span style="display:inline-block;width:50px;">
            <?= $item["id"] ?>
        </span>
        ｜
        <a target="_blank" href="/agent/edit.php?id=<?= $item["id"] ?>" class="link-text">
            <?= $item["username"] ?>
        </a>

    </div>

    <?php } ?>

</div>



<script>
$(document).ready(function() {

});

function loadTableData(page) {


}
</script>

<style>

</style>