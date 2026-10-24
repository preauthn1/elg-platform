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

//從樣板預設代理 抓出可copy設置的遊戲商
$all_vendors = curlPost('api/agentVendor/template', ['agent_id' => $agent_id])["data"];

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

//若自己完全沒設定(空資料)。則會查出上層的
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
    $parent_vendors_raw = $result['data'];
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
                <h4><?= $lang['system_management'] ?> > <?= $lang['agent_vendor_force_create'] ?></h4>
            </div>

            <?php

                $curreny_enable_vendors  = array_filter($agent_vendors, function ($var) {
                    return ($var['status'] == 1);
                });

                //$parent_vendors_raw 會排除status已經設為 關閉的遊戲廠商
                $parent_enable_vendors  = array_filter($parent_vendors_raw, function ($var) {
                    return ($var['status'] == 1);
                });

                //隱藏的debug資訊
                $hintMsg = "current：".((string)count($agent_vendors))."   current(enable)：".((string)count($curreny_enable_vendors))."  parent：".((string)count($parent_enable_vendors));

            ?>


            <h5 title="<?=$hintMsg?>">

                <span style="color: #888;">
                    <?= $lang['username'] ?> ： </b>
                </span>
                <span>
                    <?= isset($agent['username']) ? $agent['username'] : $_SESSION['username'] ?>
                </span>

                <span style="color: #888;margin-left:25px;">
                    <?= $lang['unlimit_parent_member_id'] ?> ：
                </span>
                <span>
                    <?= $agent['parent'] ?>
                </span>


                <div style="display:inline-block;margin-left:25px;">
                    <a href="index.php?id=<?=$_GET['id']?>" class=""
                        style="float: none;text-decoration: underline;"><?= $lang['btn_back'].$lang['vendor_management'] ?></a>
                </div>

            </h5>

            <div class="row">
                <?php
                foreach ($all_vendors as $vendor) {  
                     
                $vendor_id = $vendor["vendor_id"];
                $filterArray =  array_filter($agent_vendors, function ($var) use ($vendor_id) {
                    return ($var['id'] == $vendor_id);
                });  
                
                $isAnyMatch = count($filterArray) > 0;
                $match = $isAnyMatch ? array_values($filterArray)[0] : null;
                
                $element_id="status_".((string)$vendor_id); 
                $element_show_id="backend_show_".((string)$vendor_id); 

                $isEnable= $isAnyMatch ? $match["status"] == 1 : false;    

                $current_status = $isAnyMatch ? $match["status"]  : 0;    
                $current_backend_show = $isAnyMatch ? $match["backend_show"] : 0;    

                        ?>

                <div class="col-md-2">
                    <div class="card">
                        <div class="card-block">
                            <h5 class="mrg-btm-10">
                                <?= $vendor['name'] ?>
                            </h5>
                            <div class="row" style="height: 150px;justify-content: center;align-items: center;">
                                <img class="img-fluid mrg-horizon-auto mrg-btm-15" src="<?= $vendor['image'] ?>"
                                    style="max-height:150px;">
                            </div>
                            <div class="row">
                                <div class="col-lg-12 col-md-12">


                                    <div class="switchBar">
                                        <label>
                                            <?=$lang['enable_status']?>
                                        </label>

                                        <!-- switch開關 -->
                                        <div class="toggle-checkbox toggle-success checkbox-inline toggle-sm mrg-top-0">
                                            <input type="checkbox" id="<?=$element_id?>" class="js_changeVendorStatus"
                                                backend_show="<?= $current_backend_show ?>" tid="<?= $vendor_id ?>"
                                                status="<?= $isEnable ? 0 : 1 ?>" <?= $isEnable ? 'checked' : '' ?>>
                                            <label for="<?=$element_id?>"></label>
                                        </div>
                                    </div>

                                    <div style="clear:both"></div>


                                    <div class="switchBar">
                                        <label>
                                            <?=$lang['backend_show_enable']?>
                                        </label>

                                        <!-- switch開關 -->
                                        <div class="toggle-checkbox toggle-success checkbox-inline toggle-sm mrg-top-0">
                                            <input type="checkbox" id="<?=$element_show_id?>"
                                                class="js_changeVendorStatus"
                                                backend_show="<?= $current_backend_show == 1 ? 0 : 1 ?>"
                                                tid="<?= $vendor_id ?>" status="<?= $isEnable ?>"
                                                <?= $current_backend_show ? 'checked' : '' ?>>
                                            <label for="<?=$element_show_id?>"></label>
                                        </div>

                                    </div>



                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php                       
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


        $(".js_changeVendorStatus , .status_btn").click(function(e) {

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
                'action': 'vendor_setting_force_create',
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

    <style>
    .checkbox-inline {
        vertical-align: top;
        float: right;
    }

    .switchBar label {
        width: 75px;
        color: #666;
    }
    </style>