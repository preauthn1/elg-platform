<?php
   require_once '_init.php';
   $data = array();
   $data['agent_id'] = $_SESSION['id'];
   $data['is_exclude_tag_invisible'] = true;
   
   $menus = curlPost('api/agentMenu/list', $data);
   if ($menus['code'] != 1000) {
       header("location:" . BASE_URL . "login.php");
   }
   
   $check_auth = array();
   foreach ($menus['data'] as $row) {
   
       if (isset($row['submenu'])) {
   
           foreach ($row['submenu'] as $sub_row) {
               
               $is_any_layer_3_menu = isset($sub_row['submenu']);

               if ($is_any_layer_3_menu) {
                   foreach ($sub_row['submenu'] as $detail_row) {
                       $check_auth[$detail_row['url']] = 1;   
                   }
               }
               
               $check_auth[$sub_row['url']] = 1;               
           }
       }
       $check_auth[$row['url']] = 1;
   }
   
   $env = strtolower(RUN_ENV);
   $isDev = $env == "dev";
   $isLocal = $env == "local";

   if($_SERVER['PHP_SELF'] != '/index.php') {
       $data = array();
       $check_announcement = curlPost('api/announcement/checkUnread', $data);
       if ($menus['code'] != 1000) {
           header("location:" . BASE_URL . "login.php");
       }
   }

?>
<?php if ($isDev) { ?>
<div class="env-hint">Dev環境</div>
<?php } ?>
<?php if ($isLocal) { ?>
<div class="env-hint local">Local環境</div>
<?php } ?>
<!-- Side Nav START -->
<div class="side-nav">
    <div class="side-nav-inner">
        <div class="side-nav-logo">
            <div class="mobile-toggle side-nav-toggle">
                <a href="">
                    <i class="ti-arrow-circle-left"></i>
                </a>
            </div>
        </div>
        <ul class="side-nav-menu scrollable">
            <li class="nav-item active">
                <a class="mrg-top-30" href="../index.php">
                    <span class="icon-holder">
                        <i class="ti-home"></i>
                    </span>
                    <span class="title">
                        <?= $lang['dashboard'] ?>
                        <?= ($check_announcement['data'] > 0) ? '( N )' : '' ?>
                    </span>
                </a>
            </li>
            <?php
            foreach ($menus['data'] as $menu) {
            
                $is_any_sub_menu = isset($menu['submenu']);
               
                // 多層選單
                if ($is_any_sub_menu) {
                    ?>
            <li class="nav-item dropdown" id="P<?= $menu['id'] ?>">
                <a class="dropdown-toggle" href="javascript:void(0);">
                    <span class="icon-holder">
                        <i class="<?= $menu['symbol'] ?>"></i>
                    </span>
                    <span class="title"><?= $lang['menu_' . $menu['code']] ?></span>
                    <span class="arrow">
                        <i class="ti-angle-right"></i>
                    </span>
                </a>
                <?php

                    //layer 2 選單
                    foreach ($menu['submenu'] as $m) {           
                    $is_any_url_submenu = $m['is_any_url_submenu'];   

                ?>

                <?php   

                  //是否有第三層選單 (需要是url型的submenu才算)
                  if ($is_any_url_submenu) {
                      $url = BASE_URL . $m['url'] . '.php';
                      $active = '';   
                      $active =  $_SERVER['PHP_SELF'] == $url ? 'active' : ""; 
                      
                ?>

                <ul class="dropdown-menu">
                    <li class="nav-item dropdown" id="P<?= $m['id'] ?>">
                        <a class="dropdown-toggle" href="javascript:void(0);">
                            <span class="title"><?= $lang['menu_' . $m['code']] ?></span>
                            <span class="arrow">
                                <i class="ti-angle-right"></i>
                            </span>
                        </a>
                        <?php

                     //layer 3 選單
                     foreach ($m['submenu'] as $t) {                         
                     
                      $url = BASE_URL . $t['url'] . '.php';
                      $active = '';
                      if ($_SERVER['PHP_SELF'] == $url) {
                          $active = 'active';
                          $parent = $m['parent'];
                          $detail = $t['parent'];
                      }
                     
                      $isUrlPageMenu =  $t["is_url_menu"];

                     ?>
                        <?php 
                     if ($isUrlPageMenu) {
                      ?>
                        <ul class="dropdown-menu" name="L3_<?= $t['parent'] ?>">
                            <li class="<?= $active ?>">
                                <a href="<?=  $url ?>"><?= $lang['menu_' . $t['code']] ?> </a>
                            </li>
                        </ul>
                        <?php  
                     }

                     ?>
                        <?php
                     }  //for迴圈
                     ?>
                    </li>
                </ul>
                <?php
               } else {
                   $isUrlPageMenu = $m["is_url_menu"];             
                 
                   $url = BASE_URL . $m['url'] . '.php';
                   $active = '';
                   if ($_SERVER['PHP_SELF'] == $url) {
                       $active = 'active';
                       $parent = $m['parent'];
                   } ?>
                <?php 

                    //若沒有第三層選單 單純顯示menu名稱 & url
                    if ($isUrlPageMenu) { 
                ?>
                <ul class="dropdown-menu">
                    <li class="<?= $active ?>">
                        <a href="<?= $url ?>"><?= $lang['menu_' . $m['code']] ?></a>
                    </li>
                </ul>
                <?php 
                    } 
                ?>
                <?php
               }
               } ?>
            </li>
            <?php
            } else {
            
                // 一層選單
                ?>
            <li class="nav-item">
                <a class="mrg-top-30" href="<?= BASE_URL . $menu['url'] ?>.php">
                    <span class="icon-holder">
                        <i class="<?= $menu['symbol'] ?>"></i>
                    </span>
                    <span class="title"><?= $lang['menu_' . $menu['code']] ?>
                    </span>
                </a>
            </li>
            <?php
            }
            }
            ?>
        </ul>
    </div>
</div>
<!-- Side Nav END -->
<script>
$(document).ready(function() {

});
</script>