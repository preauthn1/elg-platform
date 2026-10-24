<?php
require_once '../_init.php';
require_once '../_header.php';
?>
<div class="app">
    <div class="authentication">
        <div class="sign-in">
            <div class="row no-mrg-horizon" style="margin-top: 200px;">

                <div class="col-md-3">
                </div>

                <div class="col-md-6">

                    <div style="background: #FFF;padding: 25px 25px 75px 25px;">

                        <div>
                            <h2>
                                <b><?=$lang['label_change_password'] ?></b>
                            </h2>
                        </div>
                        <div style="padding: 15px 5px;color: #ea944a;">
                            <?=$lang['hint_please_change_your_password_every_period'] ?>
                        </div>

                        <form id="data-form">


                            <div class="form-group">
                                <span class="label"><?=$lang['member_account']?></span>

                                <input type="text" class="form-control" disabled="disabled"
                                    value="<?=$_SESSION['username'] ?>">

                            </div>


                            <div class="form-group">
                                <span class="label"><?=$lang['original_password']?></span>
                                <input type="password" name="password" class="form-control" placeholder="password">
                            </div>

                            <div class="form-group">
                                <span class="label"><?=$lang['new_password']?></span>
                                <input type="password" name="new_password" class="form-control" placeholder="password">
                            </div>

                            <div class="form-group">
                                <span class="label"><?=$lang['new_password_confirm']?></span>
                                <input type="password" name="new_password_confirmation" class="form-control"
                                    placeholder="password">
                            </div>

                            <input type="hidden" name="action" value="reset_password">
                            <button id="submit" class="btn btn-info"><?=$lang['label_change_password']?></button>
                        </form>

                    </div>



                </div>


                <div class="col-md-3">
                </div>


            </div>
        </div>
    </div>
    <?php
    
require_once '../_footer.php';
?>




    <style>
    .label {
        color: #888;
        font-weight: 100;
    }
    </style>

    <script>
    $(document).ready(function() {
        $("#submit").click(function(e) {
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
            })

            $.post("/agent/ajax.php", $("#data-form").serialize(), function(data) {
                $.unblockUI();
                if (data.code == 1000) {
                    window.location = '/index.php';
                } else {
                    alert(data.message);
                }
            }, 'json');
        });
    });
    </script>