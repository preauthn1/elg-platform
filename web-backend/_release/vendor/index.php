<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';
?>
<!-- Page Container START -->
<div class="page-container">
    <?php
    require_once '../_top.php';
    require_once '../_theme.php';
    $result = curlPost('api/vendor/list');
    if ($result['code'] != 1000) {
        header("location:" . BASE_URL . "login.php");
        exit();
    }
    ?>
    <!-- Content Wrapper START -->
    <div class="main-content">
        <div class="container-fluid">
            <div class="page-title">
                <h4>遊戲商管理 > 廠商管理</h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="table-overflow">
                                <table id="dt-opt" class="table table-lg table-hover">
                                    <thead>
                                        <tr>
                                            <th>排序</th>
                                            <th>遊戲商圖片</th>
                                            <th>名稱</th>
                                            <th>轉點匯率</th>
                                            <th>拆帳比</th>
                                            <th>狀態</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        foreach ($result['data'] as $row) {
                                            ?>
                                        <tr>
                                            <td>
                                                <div class="relative mrg-top-15">
                                                    <span class="pdd-left-20"><?= $row['sort'] ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="list-info mrg-top-10">
                                                    <img height="80px" src="<?= $row['image'] ?>">
                                                </div>
                                            </td>
                                            <td>
                                                <div class="list-info mrg-top-10">
                                                    <div class="info">
                                                        <span class="title"><?= $row['name'] ?></span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="relative mrg-top-15">
                                                    <span class="pdd-left-20">1:<?= $row['rate'] ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span class="text-info"><?= $row['percentage'] ?>%</span>
                                                </div>
                                            </td>
                                            <td>
                                                <div
                                                    class="mrg-top-15 <?= ($row['status'] != 1) ? 'text-danger' : '' ?>">
                                                    <?= ($row['status'] == 1) ? '正常' : (($row['status'] == 2) ? '開發中' : '停用') ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-10 text-center">
                                                    <div class="dropdown inline-block">
                                                        <button class="btn btn-default btn-sm dropdown-toggle"
                                                            type="button" data-toggle="dropdown">
                                                            <i class="ti-angle-down font-size-9"></i>
                                                        </button>
                                                        <ul class="dropdown-menu">
                                                            <li>
                                                                <a href="edit.php?id=<?= $row['id'] ?>">編輯</a>
                                                            </li>
                                                            <li>
                                                                <a
                                                                    href="../game/index.php?vid=<?= $row['id'] ?>">遊戲管理</a>
                                                            </li>
                                                            <li class="divider"></li>
                                                            <li>
                                                                <a href="#" class="vendor_toggle_status"
                                                                    id="<?= $row['id'] ?>"
                                                                    status="<?= ($row['status'] != 1) ? 1 : 0 ?>"><?= ($row['status'] != 1) ? '啟用' : '停用' ?></a>
                                                            </li>
                                                        </ul>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
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
    //修改狀態
    $(document).on('click', '.vendor_toggle_status', function(e) {
        if (e.preventDefault) {
            e.preventDefault();
        } else {
            window.event.returnValue == false;
        }
        var id = $(this).attr('id');
        var status = $(this).attr('status');
        $.blockUI({
            baseZ: 9000,
            overlayCSS: {
                backgroundColor: '#444444'
            }
        })
        $.post("<?= BASE_URL ?>ajax.php", {
            'action': 'vendor_toggle_status',
            'id': id,
            'status': status
        }, function(data) {
            $.unblockUI();
            if (data.code == '1000') {
                window.location.reload();
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