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

$setting = curlPost('api/agent/customized-setting');
$has_notification_loop = 0;
if ($setting['code'] == 1000) {
    foreach ($setting['data'] as $row) {
        if ($row['setting_key'] == 5100) {
            $has_notification_loop = $row['value'];
        }
    }
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
                <span>
                    <?= $lang['sharecode'] ?>:
                    <a href="#" id="qrcode"><?= $_SESSION['code'] ?></a>
                </span>
            </li>
        </ul>
        <ul class="nav-right">
            <li>
                <div class=" mrg-top-15">
                <p><?= $lang['thousands'] ?>:</p>
                </div>
            </li>
            <li>
                <span>
                    <div class="toggle-checkbox checkbox-inline toggle-sm mrg-top-15">
                    <input type="checkbox"
                           name="toggle_status" <?= $_SESSION['toggle_thousands'] == 1 ? "checked" : "" ?>
                           id="toggle_thousands" data-status="<?= $_SESSION['toggle_thousands'] ?>">
                    <label for="toggle_thousands"></label>
                </div>
                </span>
            </li>
            <li><br>
                <span id='auto_deposit_notice'></span>
            </li>
            <li>
                &nbsp;
            </li>
            <li><br>
                <span id='auto_withdraw_notice'></span>
            </li>
            <li>
                &nbsp;
            </li>
            <?php if ($_SESSION['has_transaction']) { ?>
                <li><br>
                    <span><?= $lang['balance'] ?> :
                    <?= ($_SESSION['balance'] != -1) ? $_SESSION['balance'] : $lang['unlimited'] ?></span>
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
                <ul class="dropdown-menu">
                    <li class="notice-header">
                        <i class="ti-bell pdd-right-10"></i>
                        <span><?= $lang['language'] ?></span>
                    </li>
                    <li>
                        <ul class="list-info overflow-y-auto relative">
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
    $(document).ready(function () {
        const audio = new Audio("<?= BASE_URL ?>assets/voice/autodeposit.mp3" );
        audio.muted = true;
        $("#logout").click(function (e) {
            if (e.preventDefault) {
                e.preventDefault();
            } else {
                window.event.returnValue == false;
            }
            $.post("<?= BASE_URL ?>ajax.php", {
                'action': 'logout'
            }, function (data) {
                window.location = '<?= BASE_URL ?>login.php';
            });
        });

        $(".lang").click(function (e) {
            if (e.preventDefault) {
                e.preventDefault();
            } else {
                window.event.returnValue == false;
            }
            var lang = $(this).attr('id');
            $.post("<?= BASE_URL ?>ajax.php", {
                'action': 'language',
                'lang': lang
            }, function (data) {
                window.location.reload();
            });
        });

        <?php
        if (isset($_SESSION['domain']) && $_SESSION['domain'] != '' && $_SESSION['code'] != '') {
        ?>
        $("#qrcode").click(function (e) {
            Swal.fire({
                title: '<?= $lang['sharecode'] ?> - <?=$_SESSION['code']?>',
                imageUrl: 'https://example.invalid/chart?chs=400x400&cht=qr&chl=https%3A%2F%2F<?= $_SESSION['domain'] . ((strpos($_SESSION['domain'], '?') != false) ? '' : '/Signup/') . $_SESSION['code']?>',
                imageHeight: 300,
                imageWidth: 300
            });
        });
        <?php
        }
        ?>

        $.post("<?= BASE_URL ?>ajax.php", {
            'action': 'check_autoDeposit_and_autoWithdraw_amount_notice',
            'code': 'auto_deposit_and_auto_withdraw'
        }, function (data) {
            var data = JSON.parse(data);
            if (data.code == '1000') {
                if (data.data == 1 || data.data == 2) {
                    notice(data.data);
                }
            } else {
                if (data.code == '2002') {
                    alert(data.data);
                } else {
                    alert(data.message);
                }
            }
        });

        function notice(hasVoice) {
            $isLoop = getAutoDepositAndAutoWithdrawAmount(hasVoice);
            //自助上分&自助洗分未處理訂單數
            if ($isLoop) {
                setInterval(function () {
                    getAutoDepositAndAutoWithdrawAmount(hasVoice);
                }, 10000);  //預設10000毫秒自動重複執行
            }

            function getAutoDepositAndAutoWithdrawAmount(hasVoice) {
                    var play = false;
                    $.post("<?= BASE_URL ?>ajax.php", {
                        'action': 'get_autoDeposit_and_autoWithdraw_amount',
                    }, function (data) {
                        var respond = JSON.parse(data);
                        if(respond.code == 1000){
                            if (respond.data.Deposit > 0) {
                                $('#auto_deposit_notice').addClass("blink");
                                play = true;
                            } else {
                                $('#auto_deposit_notice').removeClass();
                            }
                            if (respond.data.Withdraw > 0) {
                                $('#auto_withdraw_notice').addClass("blink");
                                play = true;
                            } else {
                                $('#auto_withdraw_notice').removeClass();
                            }

                            if(hasVoice == 2 && respond.data.hasNewOrder == true){
                                audio.muted = false;
                                audio.play();
                                audio.play().catch(function(error) {
                                    alert('please click anywhere on the screen after this notice to open notice voice!');
                                });
                            } else if (<?= $has_notification_loop?> == 1 && play == true) {
                                audio.muted = false;
                                audio.play();
                                // audio.play().catch(function(error) {
                                //     alert('please check here to open notice voice!');
                                // });
                            }

                            $str = "<a style='color:black;text-decoration: underline;' ";
                            $("#auto_deposit_notice").html($str + "href='/order/index.php?status=1'><?= $lang['menu_auto_deposit'] ?>(" + respond.data.Deposit + ")</a>");
                            $("#auto_withdraw_notice").html($str + "href='/withdrawal/index.php?status=1'><?= $lang['menu_auto_withdrawal'] ?>(" + respond.data.Withdraw + ")</a>");
                        } else {
                            return false;
                        }
                    });
                    return true;
            }
        }
    });
</script>
<style>
    .dataTables_paginate .paginate_button {
        min-width: 35px;
        text-align: center;
        display: inline-block;
        cursor: pointer;
    }

    .paginate_button.next,
    .paginate_button.previous {
        min-width: 80px;
    }

    @keyframes fade {
        from {
            opacity: 1.0;
        }
        50% {
            opacity: 0.0;
        }
        to {
            opacity: 1.0;
        }
    }

    .blink {
        background-color: #FF9797;
        padding-top: 5px;
        font-size: 15px;
        height: 30px;
        -webkit-animation: fade 1200ms infinite;
    }

    #auto_deposit_notice, #auto_withdraw_notice {
        color: black;
    }
</style>