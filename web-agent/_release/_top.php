<?php
require_once '_init.php';
$self = curlPost('api/agent/view', ['id' => $_SESSION['id']]);
if ($self['code'] != 1000) {
    header("location:" . BASE_URL . "login.php");
    exit();
} else {
    $self = $self['data'];
    $_SESSION['has_transaction'] = $self['agent_backend_transaction'];
}
?>
<!-- Header START -->
<div class="header navbar">
    <div class="header-container">
        <ul class="nav-left">
            <li>
                <a class="side-nav-toggle" href="javascript:void(0);">
                    <i class="ti-view-grid"></i>
                </a>
            </li>
            <li><br>
                <span><?= $lang['sharecode'] ?>:<?= $_SESSION['code'] ?></span>
            </li>
        </ul>
        <ul class="nav-right">
            <?php if ($_SESSION['has_transaction']) { ?>
            <li><br>
                <span><?= $lang['balance'] ?> :
                    <?= ($_SESSION['balance'] != -1) ? $_SESSION['balance'] : '無限制' ?></span>
            </li>

            <?php } ?>
            <li class="user-profile dropdown">
                <a href="" class="dropdown-toggle" data-toggle="dropdown">
                    <img class="profile-img img-fluid" src="/assets/images/user.jpg" alt="">
                    <div class="user-info">
                        <span class="name pdd-right-5"><?= $_SESSION['username'] ?></span>
                        <i class="ti-angle-down font-size-10"></i>
                    </div>
                </a>
                <ul class="dropdown-menu">
                    <li>
                        <a href="<?= BASE_URL ?>agent/edit.php?id=<?= $_SESSION['id'] ?>">
                            <i class="ti-settings pdd-right-10"></i>
                            <span><?= $lang['setting'] ?></span>
                        </a>
                    </li>
                    <li role="separator" class="divider"></li>
                    <li>
                        <a href="#" id="logout">
                            <i class="ti-power-off pdd-right-10"></i>
                            <span><?= $lang['logout'] ?></span>
                        </a>
                    </li>
                </ul>
            </li>
            <li class="notifications dropdown">
                <a href="" class="dropdown-toggle" data-toggle="dropdown">
                    <i class="ei-show"></i>
                </a>
                <ul class="dropdown-menu ">
                    <li class="notice-header">
                        <i class="ti-bell pdd-right-10"></i>
                        <span><?= $lang['language'] ?></span>
                    </li>
                    <li>
                        <ul class="list-info overflow-y-auto relative scrollable">
                            <?php
                            foreach ($_LANGUAGES as $key => $value) {
                                ?>
                            <li>
                                <a id="<?= $key ?>" class="lang">
                                    <span class="thumb-img <?= $value['color'] ?>">
                                        <span class="text-white"><?= $value['icon'] ?></span>
                                    </span>
                                    <div class="info">
                                        <span class="title">
                                            <span class="font-size-14 text-semibold"><?= $value['name'] ?></span>
                                            <!--<span class="text-gray">likes your <span class="text-dark">photo</span></span>-->
                                        </span>
                                        <span class="sub-title"><?= $value['description'] ?></span>
                                    </div>
                                </a>
                            </li>
                            <?php
                            }
                            ?>
                        </ul>
                    </li>
                </ul>
            </li>
        </ul>
    </div>
</div>
<!-- Header END -->
<script>
$(document).ready(function() {
    $("#logout").click(function(e) {
        if (e.preventDefault) {
            e.preventDefault();
        } else {
            window.event.returnValue == false;
        }
        $.post("<?= BASE_URL ?>ajax.php", {
            'action': 'logout'
        }, function(data) {
            window.location = '<?= BASE_URL ?>login.php';
        });
    });

    $(".lang").click(function(e) {
        if (e.preventDefault) {
            e.preventDefault();
        } else {
            window.event.returnValue == false;
        }
        var lang = $(this).attr('id');
        //     	localStorage.setItem('language',lang);
        $.post("<?= BASE_URL ?>ajax.php", {
            'action': 'language',
            'lang': lang
        }, function(data) {
            window.location.reload();
        });
    });
});
</script>