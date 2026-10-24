<?php
require_once '_init.php';
$data = array();
$data['user_id'] = $_SESSION['id'];
$menus = curlPost('api/userMenu/list', $data);
if ($menus['code'] != 1000) {
    header("location:" . BASE_URL . "login.php");
}

?>
<!-- Side Nav START -->
<div class="side-nav">
    <div class="side-nav-inner">
        <div class="side-nav-logo">
            <a href="index.php">
                &nbsp;
                <!--<div class="logo logo-dark" style="background-image: url('assets/images/logo/logo.png')"></div>
                <div class="logo logo-white" style="background-image: url('assets/images/logo/logo-white.png')"></div>-->
            </a>
            <div class="mobile-toggle side-nav-toggle">
                <a href="">
                    <i class="ti-arrow-circle-left"></i>
                </a>
            </div>
        </div>
        <ul class="side-nav-menu scrollable">
            <li class="nav-item active">
                <a class="mrg-top-30" href="index.php">
                    <span class="icon-holder">
                        <i class="ti-home"></i>
                    </span>
                    <span class="title"><?=$lang['dashboard']?></span>
                </a>
            </li>
            <?php
            foreach ($menus['data'] as $menu) {
                ?>
                    <?php
                // 多層選單
                if (isset($menu['submenu'])) {
                    ?>
                <li class="nav-item dropdown" id="<?=$menu['id']?>">
                    <a class="dropdown-toggle" href="javascript:void(0);">
                        <span class="icon-holder">
                            <i class="<?=$menu['symbol']?>"></i>
                        </span>
                        <span class="title"><?=$menu['name']?></span>
                        <span class="arrow">
                            <i class="ti-angle-right"></i>
                        </span>
                    </a>
                   <?php
                    foreach ($menu['submenu'] as $menu) {
                        $url = BASE_URL . $menu['url'] . '.php';
                        $active = '';
                        if ($_SERVER['PHP_SELF'] == $url) {
                            $active = 'active';
                            $parent = $menu['parent'];
                        }
                        ?>
                        <ul class="dropdown-menu">                                
                            <li class="<?=$active?>">
                                <a href="<?=$url?>"><?=$menu['name']?></a>
                            </li>
                        </ul>
                    <?php
                    }
                    ?>
                </li>
                 <?php
                } else {
                    // 一層選單
                    ?>
                 <li class="nav-item">
                    <a class="mrg-top-30" href="<?=BASE_URL . $menu['url']?>.php">
                        <span class="icon-holder">
                            <i class="<?=$menu['symbol']?>"></i>
                        </span>
                        <span class="title"><?=$menu['name']?></span>
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
$( document ).ready(function() {
	//打開MENU
	$('#<?=$parent?>').addClass('open');
	
});
</script>