<?php
require_once '_init.php';
require_once '_header.php';
require_once '_menu.php';

$show_announcement = true;
if($_SESSION['id'] != 1) {
    if($_SESSION['host_id'] != 1 && $_SESSION['parent'] != 1) {
        $result = curlPost('api/announcement/getGeneralAgent');
        if ($result['code'] != 1000) {
            if ($result['code'] > 9997) {
                header("location:" . BASE_URL . "login.php");
            } else {
                header('Location:' . $_SERVER['HTTP_REFERER']);
            }
            exit();
        } else {
            $general_agent = array();
            foreach($result['data']['host'] as $row) {
                $general_agent[] = $row['id'];
            }
            if($_SESSION['host_id'] == 0 ) {
                if(!in_array($_SESSION['id'], $general_agent)) {
                    $show_announcement = false;
                }
            } else {
                if(!in_array($_SESSION['host_id'], $general_agent)) {
                    $show_announcement = false;
                }
            }
        }
    }
}
?>
<!-- Page Container START -->
<div class="page-container">


    <?php
require_once '_top.php';
require_once '_theme.php';
?>
    <div class="main-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-lg-12" style="font-size:20px;">
                    <!-- 歡迎使用代理後台 -->
                    <div class="row"></div>
                </div>
                <?php if($show_announcement) {?>
                    <div class="col-12">
                        <iframe src="announcement_table.php" frameborder="0"
                                scrolling="auto" width="100%" style="height:700px">
                        </iframe>
                    </div>
                <?php } ?>
            </div>
        </div>
    </div>

    <?php
require_once '_bottom.php';
?>
</div>
<!-- Page Container END -->
<?php
require_once '_footer.php';
?>