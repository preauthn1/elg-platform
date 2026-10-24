<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';
if (!isset($check_auth['vendor/index'])) {
    echo $lang['nopermission'];
    exit;
}
// 取詳細資料
if ($_GET['id']) {
    $data = array();
    $data['id'] = $_GET['id'];
    $result = curlPost('api/agent/view', $data);
    if ($result['code'] == 1000) {
        $agent = $result['data'];
    }
}
$agent_id = isset($agent['id']) ? $agent['id'] : $_SESSION['id'];

$result = curlPost('api/agentVendor/list', ['agent_id' => $agent_id]);
if ($result['code'] != 1000) {
    if ($result['code'] > 9997) {
        header("location:" . BASE_URL . "login.php");
    } else {
        echo $result['message'];
        exit();
        header('Location:' . $_SERVER['HTTP_REFERER']);
    }
    exit();
}
$agent_vendors = $result['data'];

$parent = isset($agent['parent']) ? $agent['parent'] : $_SESSION['parent'];
if ($parent > 0) {
    $result = curlPost('api/agentVendor/list', ['agent_id' => $parent]);
    if ($result['code'] != 1000) {
        if ($result['code'] > 9997) {
            header("location:" . BASE_URL . "login.php");
        } else {
            echo $result['message'];
            exit();
            header('Location:' . $_SERVER['HTTP_REFERER']);
        }
        exit();
    }
    $parent_vendors = array();
    foreach ($result['data'] as $row) {
        $parent_vendors[$row['id']] = $row['status'];
    }
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
                <h4><?= $lang['system_management'] ?> > <?= $lang['vendor_management'] ?></h4>
            </div>
            <h5><?= $lang['username'] ?> ： <?= isset($agent['username']) ? $agent['username'] : $_SESSION['username'] ?>
            </h5>

            <div class="row">
                <?php
                foreach ($agent_vendors as $row) {
                    // $info = json_decode(decrypt($row['info']));
                    if ($parent_vendors[$row['id']] != 0 || $parent == 0) {
                        ?>
                        <div class="col-md-2">
                            <div class="card">
                                <div class="card-block">
                                    <h5 class="mrg-btm-10">
                                        <?= $row['name'] ?><?= (c == 2) ? '(Coming Soon)' : '' ?></h5>
                                    <div class="row" style="height: 150px;justify-content: center;align-items: center;">
                                        <img class="img-fluid mrg-horizon-auto mrg-btm-15" src="<?= $row['image'] ?>"
                                             style="max-height:150px;">
                                    </div>
                                    <div class="row">
                                        <div class="col-lg-8 col-md-12"></div>
                                        <div class="col-lg-4 col-md-12 text-right pdd-top-5">
                                            <button
                                                    class="status_btn btn btn-sm <?= ($row['status'] == 1) ? 'btn-primary' : 'btn-default' ?>"
                                                    tid="<?= $row['id'] ?>" status="<?= ($row['status'] == 1) ? 0 : 1 ?>"
                                                    backend_show="<?= $row['backend_show'] ?>"><?= ($row['status'] == 1) ? $lang['enable'] : $lang['disable'] ?></button>
                                            <button
                                                    class="status_btn btn btn-sm <?= ($row['backend_show'] == 1) ? 'btn-primary' : 'btn-default' ?>"
                                                    tid="<?= $row['id'] ?>"
                                                    backend_show="<?= ($row['backend_show'] == 1) ? 0 : 1 ?>"
                                                    status="<?= $row['status'] ?>"><?= ($row['backend_show'] == 1) ? $lang['backend_show_enable'] : $lang['backend_show_disable'] ?></button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php
                    }
                }
                ?>
            </div>
        </div>
    </div>
    <!-- Content Wrapper END -->
    <?php
    require_once '../_bottom.php';
    require_once '../_footer.php';
    ?>
    <script>
        $(document).ready(function() {
            $(".status_btn").click(function(e) {
                if (e.preventDefault) {
                    e.preventDefault();
                } else {
                    window.event.returnValue == false;
                }
                var id = $(this).attr('tid');
                var status = $(this).attr('status');
                var backend_show = $(this).attr('backend_show');
                $.blockUI({
                    baseZ: 9000,
                    overlayCSS: {
                        backgroundColor: '#444444'
                    }
                })
                $.post("<?= BASE_URL ?>ajax.php", {
                    'action': 'vendor_setting',
                    'agent_id': "<?= $agent_id ?>",
                    'id': id,
                    'status': status,
                    'backend_show': backend_show,
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