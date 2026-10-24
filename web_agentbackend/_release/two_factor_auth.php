<?php
require_once '_init.php';
require_once '_header.php';

$data = explode('_', $_GET['u']);
$username = $data[0];
$password = [REDACTED];
$myIP = getIp();
?>
<div class="app">
    <div class="authentication">
        <div class="sign-in">
            <div class="row no-mrg-horizon">
                <div class="col-md-8 no-pdd-horizon d-none d-md-block">
                    <div class="full-height bg" style="background-image: url('assets/images/others/img-29.jpg')">
                    </div>
                </div>
                <div class="col-md-4 no-pdd-horizon">
                    <div class="full-height bg-white height-100">
                        <div class="vertical-align full-height pdd-horizon-70">
                            <div class="table-cell">
                                <div class="pdd-horizon-15">
                                    <h2><?=$lang['2fa']?></h2>
                                    <p class="mrg-btm-15 font-size-13"><?=$lang['2fa_subtitle']?></p>
                                    <form id="data-form">
                                        <div class="form-group">
                                            <input type="text" name="secret" class="form-control"
                                                   placeholder="<?=$lang['secret']?>">
                                        </div>
                                        <input type="hidden" name="action" value='auth'>
                                        <button id="auth" class="btn btn-info">
                                            <?=$lang['auth']?>
                                        </button>
                                        <button id="cancel" class="btn btn-warning">
                                            <?= $lang['cancel'] ?>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
require_once '_footer.php';
?>
<script>
$(document).ready(function() {
    $('#auth').button('complete');

    $("#auth").click(function(e) {
        if (e.preventDefault) {
            e.preventDefault();
        } else {
            window.event.returnValue == false;
        }
        $.blockUI({
            baseZ: 9000,
            overlayCSS: {
                backgroundColor: '#444444'
            }
        });
        $.post("ajax_login.php", $("#data-form").serialize(), function(data) {
            $.unblockUI();
            if (data.code == 1000) {
                var is_update_password_required = data.data.is_update_password_required;

                if (data.data.action == 'auth') {
                    window.location = 'two_factor_auth.php';
                } else if (is_update_password_required != null && is_update_password_required == 1) {
                    window.location = 'agent/reset_password.php';
                } else {
                    window.location = 'index.php';
                }
            } else if(data.code == 2042){
                alert('System Maintenance!\nSistem dalam penyelenggaraan!\n系统维护中!\nHệ thống đang được bảo trì!');
            } else {
                alert(data.message);
            }
        }, 'json');
    });

    $("#cancel").click(function(e) {
        if (e.preventDefault) {
            e.preventDefault();
        } else {
            window.event.returnValue == false;
        }
        $.blockUI({
            baseZ: 9000,
            overlayCSS: {
                backgroundColor: '#444444'
            }
        });
        window.location = 'index.php';
    });
});
</script>