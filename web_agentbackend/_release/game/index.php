<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';

//確認權限
if (!isset($check_auth['game/index'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

//查詢條件的代理列表
$data = array();
$data['type'] = 1;
$result = curlPost('api/vendor/dropdownList', $data);
$vendors = $result['data'];

$data = array();
$data['type'] = 1;
$result = curlPost('api/gameType/list', $data);
$game_type = $result['data'];
?>
<style>
@media (min-width: 1200px) {
    .modal-xl {
        max-width: 1140px;
    }
}
</style>
<div class="page-container">
    <?php
    require_once '../_top.php';
    require_once '../_theme.php';
    ?>
    <div class="main-content">
        <div class="container-fluid">
            <div class="page-title">
                <h4><?= $lang['vendor_management'] ?> > <?= $lang['menu_game_management'] ?></h4>
            </div>
            <div class="row">
                <!--
                <div class="pdd-horizon-30 pdd-vertical-20" style="text-align:center">
                    <a href="edit.php" class="btn btn-danger btn-inverse btn-rounded"><?= $lang['add_game'] ?></a>
                </div>
                -->
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="form-group row">
                                        <h4 class="card-title col-md-11">
                                            <?= $lang['search'] ?>
                                        </h4>
                                        <div>
                                            <a href="edit.php"
                                                class="btn btn-danger btn-inverse btn-rounded"><?= $lang['add_game'] ?></a>
                                        </div>
                                    </div>
                                    <form class="form-horizontal mrg-top-40 pdd-right-30">
                                        <div class="form-group row">

                                            <div class="col-md-1">
                                                <label for="form-1-1" class="control-label">
                                                    <?= $lang['label_vendor'] ?>
                                                </label>
                                            </div>

                                            <div class="col-md-3">
                                                <select class="selectize-class" name="vendor_id">
                                                    <option value=""></option>
                                                    <?php
                                                    foreach ($vendors as $row) {
                                                        ?>
                                                    <option value="<?= $row['id'] ?>"
                                                        <?= ($_GET['vendor_id'] == $row['id']) ? 'selected' : '' ?>>
                                                        <?= $row['name'] ?>
                                                    </option>
                                                    <?php
                                                    }
                                                    ?>
                                                </select>
                                            </div>

                                            <div class="col-md-1">
                                                <label for="form-1-1" class="control-label">
                                                    <?= $lang['game_name'] ?>
                                                </label>
                                            </div>


                                            <div class="col-md-3">
                                                <input type="text" class="form-control" name="name"
                                                    value="<?= $_GET['name'] ?>" />
                                            </div>
                                        </div>
                                        <div class="form-group row">


                                            <div class="col-md-1">

                                                <label for="form-1-1" class="control-label">
                                                    <?= $lang['game_type'] ?>
                                                </label>
                                            </div>


                                            <div class="col-md-3">
                                                <select class="selectize-class" name="game_type">
                                                    <option value=""></option>
                                                    <?php
                                                    foreach ($game_type as $row) {
                                                        ?>
                                                    <option value="<?= $row['id'] ?>"
                                                        <?= ($_GET['game_type'] == $row['id']) ? 'selected' : '' ?>>
                                                        <?= $row['name'] ?>
                                                    </option>
                                                    <?php
                                                    }
                                                    ?>
                                                </select>
                                            </div>

                                            <div class="col-md-1">
                                                <label for="form-1-1" class="control-label">
                                                    <?= $lang['status'] ?>
                                                </label>
                                            </div>


                                            <div class="col-md-3">
                                                <select id="selectize-dropdown" name="status">
                                                    <option value=""><?= $lang['all'] ?></option>
                                                    <option value="1" <?= ($_GET['status'] == '1') ? 'selected' : '' ?>>
                                                        <?= $lang['enable'] ?>
                                                    </option>
                                                    <option value="0" <?= ($_GET['status'] == '0') ? 'selected' : '' ?>>
                                                        <?= $lang['disable'] ?>
                                                    </option>
                                                </select>
                                            </div>
                                            <div class="col-md-6"></div>
                                        </div>
                                        <div class="form-group row">
                                            <div class="col-md-3"></div>
                                            <div class="col-md-6">
                                                <button class="btn btn-primary"><?= $lang['search'] ?></button>
                                                <input type="reset" class="btn btn-default clear"
                                                    value="<?= $lang['clear'] ?>" />
                                                <input type="hidden" name="act" value="1" />
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row" id="result">
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
<div class="modal fade" id="flow_limit">
    <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content" id="flow_limit_content">
        </div>
    </div>
</div>
<script>
$(document).ready(function() {
    $.blockUI();
    $.get('index_ajax.php' + $(location).attr('search'), function(data) {
        $("#result").html(data);
        $.unblockUI();
    });

    //修改狀態
    $(document).on('click', '.game_toggle_status', function(e) {
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
            'action': 'game_toggle_status',
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

    //清除查詢資料
    $(".clear").click(function(e) {
        if (e.preventDefault) {
            e.preventDefault();
        } else {
            window.event.returnValue == false;
        }
        window.location = 'index.php';
    });


    $('.selectize').selectize({
        // true下拉option中没有输入的内容会出现Add+输入的内容，false则只能在已有的option中选择，不允许添加。
        create: false,
        // 排序
        sortField: {
            // text: 依据文本排序，id: 依据value排序
            field: 'text',
            direction: 'asc'
        },
        // body 下拉选依据body生成。有时候设置了样式，其他的元素浮动在select标签上方，可能导致下拉框下沉显示不出来，设置该属性则不会存在这个问题。
        dropdownParent: 'body',
        //多选的时候设置最大选择数量
        //maxItems: 3
    });
});
</script>