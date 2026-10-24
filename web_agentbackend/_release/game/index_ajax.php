<?php
require_once '../_init.php';
require_once '../class/CreatePage.php';

if ($_GET['act'] == 1) {
    $data = array();
    // vendor_id
    if ($_GET['vendor_id'] != '' && isset($_GET['vendor_id'])) {
        $data['vendor_id'] = $_GET['vendor_id'];
    }
    // name
    if ($_GET['name'] != '' && isset($_GET['name'])) {
        $data['name'] = $_GET['name'];
    }
    // game_type
    if ($_GET['game_type'] != '' && isset($_GET['game_type'])) {
        $data['game_type'] = $_GET['game_type'];
    }
    // 狀態
    if ($_GET['status'] != '' && isset($_GET['status'])) {
        $data['status'] = $_GET['status'];
    }
    // 查看下線
    if (isset($_GET['id'])) {
        $data['id'] = $_GET['id'];
    }
    $data['page'] = $_GET['page'] ? $_GET['page'] : 1;
    $data['per_page'] = 50;
    $result = curlPost('api/vendor/gameList', $data);
    if ($result['code'] == 1000) {
        $games = $result['data'];
    }
}
$page = new CreatePage();
?>
<div class="col-md-12">
    <div class="card">
        <div class="card-block">
            <div class="table-overflow">
                <table id="dt-opt" class="table table-lg table-hover">
                    <thead>
                    <tr>
                        <th style="width:10%"><?= $lang['vendor_name'] ?></th>
                        <th class="text-left" style="width:10%"><?= $lang['game_code'] ?></th>
                        <th style="width:10%"><?= $lang['game_type'] ?></th>
                        <th style="width:20%"><?= $lang['game_name'] ?></th>
                        <th style="width:10%"><?= $lang['memo'] ?></th>
                        <th style="width:17%"><?= $lang['picture'] ?></th>
                        <th style="width:8%"><?= $lang['status'] ?></th>
                        <th style="width:5%"><?= $lang['features'] ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php
                    if (count($games) > 0) {
                        foreach ($games as $row) {
                            ?>
                            <tr>
                                <td>
                                    <div class="list-info mrg-top-10 text-left" >
                                        <?=$row['vendor_name']?>
                                    </div>
                                </td>
                                <td>
                                    <div class="list-info mrg-top-10 text-left">
                                        <div class="">
                                            <span class="title"><?=$row['game_code']?></span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="relative mrg-top-15 text-left">
                                        <span class=""><?=$row['game_type']?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="list-info mrg-top-10">
                                        <div class="info">
                                            <span class="title">
                                                <b>
                                                    <?=$row['game_name']?>
                                                </b>
                                            </span>
                                            <span class="sub-title">
                                                <?= $lang['id'] ?> <?=$row['id']?>
                                            </span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15 text-left">
                                        <?=$row['remark']?>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15 text-left">
                                        <span>
                                            <?php
                                            if (isset($row['image'])) {
                                                ?>
                                                <img class="thumb-img" src="<?=$row['image']?>" style="max-height:100px;max-width:200px;">
                                                <?php
                                            } else {
                                                echo $lang['not_uploaded'];
                                            }
                                            ?>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15 <?=($row['status'] != 1) ? 'text-danger' : ''?>">
                                        <?=($row['status'] == 1) ? $lang['enable'] : $lang['disable'] ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-10 text-center">
                                        <div class="dropdown inline-block">
                                            <button class="btn btn-default btn-sm dropdown-toggle" type="button" data-toggle="dropdown">
                                                <i class="ti-angle-down font-size-9"></i>
                                            </button>
                                            <ul class="dropdown-menu">
                                                <li>
                                                    <a href="edit.php?id=<?=$row['id']?>">
                                                        <?= $lang['edit'] ?>
                                                    </a>
                                                </li>
                                                <li>
                                                    <a href="#" class="game_toggle_status" id="<?=$row['id']?>"
                                                       status="<?=($row['status'] != 1) ? 1 : 0?>">
                                                        <?=($row['status'] != 1) ? $lang['enable'] : $lang['disable'] ?>
                                                    </a>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php
                        }
                    } else {
                        ?>
                        <tr>
                            <td colspan="8" align='center'>
                                <div class="mrg-top-15">
                                    <span class="text-info">
                                        <b>
                                            <?= $lang['no_record'] ?>
                                        </b>
                                    </span>
                                </div>
                            </td>
                        </tr>
                    <?php }
                    ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
        if (count($result['data']) > 0) {
            $page = new CreatePage();
            ?>
            <div class="dataTables_info" id="dt-opt_info" role="status" aria-live="polite">
                <?= $lang['total'] ?> <?= $result['pagination']['total'] ?> <?= $lang['count'] ?>
            </div>
            <div class="dataTables_paginate paging_simple_numbers" id="dt-opt_paginate">
                <?= $page->createPager($result['pagination']['pages'], $result['pagination']['current'], $lang) ?>
            </div>
        <?php } ?>
    </div>
        </div>
    </div>
</div>
<?php if ($_SESSION['has_transaction']) { ?>
    <div class="modal fade" id="transaction">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content" id="transaction_content">
            </div>
        </div>
    </div>
<?php } ?>
<script>
    $(document).ready(function() {
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
                var result = $(location).attr('search').split('&page')[0]
                if (result == '') {
                    window.location = $(location).attr('href') + '?page=' + page;
                } else {
                    window.location = result + '&page=' + page;
                }
            }
        });
    });
</script>