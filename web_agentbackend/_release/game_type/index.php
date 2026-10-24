<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';

//確認權限
if (!isset($check_auth['game_type/index'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

$result = curlPost('api/gameType/list');
if ($result['code'] != 1000) {
    if ($result['code'] > 9997) {
        header("location:" . BASE_URL . "login.php");
    } else {
        header('Location:' . $_SERVER['HTTP_REFERER']);
    }
    exit();
}
?>
<div class="page-container">
    <?php
    require_once '../_top.php';
    require_once '../_theme.php';
    ?>
    <div class="main-content">
        <div class="container-fluid">
            <div class="page-title">
                <h4><?= $lang['menu_new_vendor'] ?> > <?= $lang['menu_game_type_management'] ?></h4>
            </div>
            <div class="row">
                <div class="pdd-horizon-30 pdd-vertical-20" style="text-align:center">
                    <a href="#" data-toggle="modal" data-target="#edit_game_type" data-uid="0"
                       class="btn btn-inverse btn-success edit">
                        <?= $lang['add_type'] ?>
                    </a>
                </div>
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="table-overflow">
                                <table id="dt-opt" class="table table-lg table-hover">
                                    <thead>
                                        <tr>
                                            <th><?= $lang['type_name'] ?></th>
                                            <th><?= $lang['status'] ?></th>
                                            <th><?= $lang['features'] ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php
                                    foreach ($result['data'] as $row) {
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="list-info mrg-top-10">
                                                    <div class="info">
                                                        <span class="title">
                                                            <?=$row['name']?>
                                                        </span>
                                                        <span class="sub-title">
                                                            <?= $lang['id'] ?> <?=$row['id']?>
                                                        </span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <b class="text-dark font-size-16 <?=($row['status'] != 1) ? 'text-danger' : ''?>">
                                                        <?= ($row['status'] == 1) ? $lang['enable'] : $lang['disable'] ?>
                                                    </b>
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
                                                                <a href="#" data-toggle="modal"
                                                                   class="edit"
                                                                   data-target="#edit_game_type"
                                                                   data-uid="<?=$row['id']?>">
                                                                    <?= $lang['edit'] ?>
                                                                </a>
                                                            </li>
                                                            <li>
                                                                <a href="#" class="game_type_toggle_status"
                                                                   id="<?=$row['id']?>"
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
    <?php
    require_once '../_bottom.php';
    ?>
</div>
<?php
require_once '../_footer.php';
?>
<div class="modal fade" id="edit_game_type">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content" id="game_type_content">
        </div>
    </div>
</div>
<script>
    $( document ).ready(function() {
        //開啟編輯框
        $(document).on('click', '.edit', function(e){
            var id = $(this).attr('data-uid');
            $.post( "edit.php",{'id':id}, function( data ) {
                $( "#game_type_content" ).html(data);
            });
        })

        //修改狀態
        $(document).on('click', '.game_type_toggle_status', function(e){
            if (e.preventDefault){
                e.preventDefault();
            } else {
                window.event.returnValue == false;
            }
            var id = $(this).attr('id');
            var status = $(this).attr('status');
            $.blockUI({
                baseZ: 9000,
                overlayCSS: { backgroundColor: '#444444' }
            });
            $.post(
                "<?=BASE_URL?>ajax.php",
                {'action':'game_type_toggle_status', 'id':id, 'status':status},
                function( data ) {
                $.unblockUI();
                if(data.code == '1000'){
                    window.location.reload();
                }else{
                    if(data.code == '2002'){
                        alert(data.data);
                    }else{
                        alert(data.message);
                    }
                }
            },'json');
        });
    });
</script>