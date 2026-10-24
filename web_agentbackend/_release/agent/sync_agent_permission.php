<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';

function getMenu($menus, &$owner_menus) {
    if (is_array($menus)) {
        foreach ($menus as $menu) {
            $owner_menus[] = $menu['id'];
            if (isset($menu['submenu'])) {
                getMenu($menu['submenu'], $owner_menus);
            }
        }
    }
}

//檢查權限
if (!check_permission_menuCode('agent_sync_permissiom')) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

$api_query_agent_childs = curlPost('api/agent/child/accounts');

if ($api_query_agent_childs['code'] != 1000) {
    echo display_error_msg_html($api_query_agent_childs["message"]);
    exit;
}

$query_agent_childs = $api_query_agent_childs["data"];
$is_not_child_account = $query_agent_childs['is_not_child_account'];


if (!$is_not_child_account) {
    echo display_error_msg_html("此帳號已經是子帳號、不開放使用此功能");
    exit;
}

 // 查詢這個代理有的權限
 $data = array();
 $data['agent_id'] = $_SESSION['id'];
 $agent_menus = curlPost('api/agentMenu/list', $data);

 if ($agent_menus['code'] != 1000) {
     echo display_error_msg_html($agent_menus["message"]);
     exit;
 }

 if (is_array($agent_menus['data'])) {
     $owner_menus = array();
     getMenu($agent_menus['data'], $owner_menus);
 }

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
                <h4><?= $lang['agnet_management'] ?> > <?= $lang['menu_agent_sync_permissiom'] ?>
                </h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="">
                                <form id="data-form">
                                    <div class="row col-md-11">

                                        <div class="col-md-4">

                                            <h3>
                                                <b>
                                                    當前帳號所擁有的權限
                                                </b>

                                                <a style="margin-left:7px;font-size: 14px;" class="linkText"
                                                    target="_blank" href="/agent/edit.php?id=<?= $_SESSION["id"] ?>">
                                                    <?= $lang["edit"] ?>
                                                </a>
                                            </h3>

                                            <hr>


                                            <div class="confirmMenu">



                                                <!-- 遮罩 -->
                                                <div
                                                    style="position: absolute;top: 1px;width: 100%;height: 100vh;opacity: .1;z-index:995;">
                                                </div>

                                                <!-- 多層選單 -->
                                                <?php foreach ($menus['data'] as $menu) { ?>

                                                <input type="checkbox" id="<?= $menu['code'] ?>" name="agent_menus[]"
                                                    value="<?= $menu['id'] ?>"
                                                    <?= in_array($menu['id'], $owner_menus) ? 'checked' : '' ?>>
                                                <label for="<?= $menu['code'] ?>">
                                                    <?= $lang['menu_' . $menu['code']] ?>
                                                </label><br>

                                                <?php
                                                if (isset($menu['submenu'])) {
                                                    foreach ($menu['submenu'] as $m) {
                                                        ?>


                                                <div style="padding-left:15px">

                                                    <input type="checkbox" id="<?= $m['code'] ?>" name="agent_menus[]"
                                                        value="<?= $m['id'] ?>" parent="<?= $m['parent'] ?>"
                                                        <?= in_array($m['id'], $owner_menus) ? 'checked' : '' ?>>
                                                    <label for="<?= $m['code'] ?>">
                                                        <?= $lang['menu_' . $m['code']] ?>
                                                    </label><br>
                                                    <?php
                                                        if (isset($m['submenu'])) {
                                                            foreach ($m['submenu'] as $d) {
                                                                ?>


                                                    <div style="padding-left:35px">

                                                        <input type="checkbox" id="<?= $d['code'] ?>"
                                                            name="agent_menus[]" value="<?= $d['id'] ?>"
                                                            parent="<?= $d['parent'] ?>"
                                                            <?= in_array($d['id'], $owner_menus) ? 'checked' : '' ?>>
                                                        <label for="<?= $d['code'] ?>">
                                                            <?= $lang['menu_' . $d['code']] ?>
                                                        </label><br>



                                                    </div>

                                                    <?php
                                                            }
                                                        } ?>


                                                </div>

                                                <?php
                                                    }
                                                } } ?>


                                            </div>
                                        </div>

                                        <div class="col-md-4">

                                            <h3>
                                                <b>
                                                    預計同步給以下所有子帳號 (<?= $query_agent_childs["childCount"] ?>)
                                                </b>
                                            </h3>

                                            <hr>




                                            <?php foreach ($query_agent_childs["childs"] as $item) {?>



                                            <div>
                                                <div class="each-child">
                                                    <b>
                                                        <?=$item["username"]?>
                                                    </b>
                                                </div>
                                                <div class="each-child">
                                                    <?=$item["id"]?>
                                                </div>
                                            </div>



                                            <?php } ?>


                                        </div>

                                        <div class="col-md-4">

                                        </div>

                                    </div>

                                    <div class="row">
                                        <div class="col-md-8 col-xs-6">
                                            <div class="text-right mrg-top-5">
                                                <input type="hidden" name="action" value="sync_child_permission">

                                                <?php if ($query_agent_childs["childCount"]  > 0) { ?>

                                                <button type="button" class="btn btn-primary"
                                                    onclick="save()"><?= $lang['sync'] ?>
                                                </button>

                                                <?php  } ?>

                                            </div>
                                        </div>
                                    </div>

                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>
<!-- Page Container END -->
<?php
require_once '../_footer.php';
?>


<script>
$(document).ready(function() {


});

function save() {

    $.blockUI({
        baseZ: 9000,
        overlayCSS: {
            backgroundColor: '#444444'
        }
    });

    let formPost = "action=sync_child_permission";

    $.post("<?= BASE_URL ?>ajax.php", formPost, function(data) {
        $.unblockUI();

        if (data.code == 1000) {
            alert(data.message);
            successAlert();
        } else {
            alert(data.message + "." + data.data);
        }
    }, 'json');

}
</script>


<style>
.disabledStyle {
    opacity: .5;
}

.confirmMenu {
    height: 600px;
    overflow-y: scroll;
    position: relative;
}

.confirmMenu input {
    opacity: .4;
}


.each-child {
    display: inline-block;
    width: 120px;
    padding: 5px;
    font-size: 14px;
}
</style>