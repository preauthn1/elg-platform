<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';
?>
<!-- Page Container START -->
<div class="page-container">
    <?php
    require_once '../_top.php';
    require_once '../_theme.php';
    // 取詳細資料
    if ($_GET['id']) {
        $data = array();
        $data['id'] = $_GET['id'];
        $result = curlPost('api/agent/view', $data);
        if ($result['code'] == 1000) {
            $agent = $result['data'];
        }
    }
    ?>
    <!-- Content Wrapper START -->
    <div class="main-content">
        <div class="container-fluid">
            <div class="page-title">
                <h4>營運商管理 ><?= (!isset($agent)) ? $lang['add_agent'] : $lang['edit_agent'] ?></h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="mrg-top-40">
                                <div class="row">
                                    <div class="col-md-8 ml-auto mr-auto">
                                        <form id="data-form">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label><?= !isset($agent) ? '<span style="color:red">*</span>' : '' ?>帳號</label>
                                                        <?php
                                                        if (isset($agent)) {
                                                            ?>
                                                        <input type="hidden" name="id" value="<?= $_GET['id'] ?>">
                                                        <p><?= $agent['username'] ?></p>
                                                        <?php
                                                        } else {
                                                            ?>
                                                        <input type="text" placeholder="Account" name="username"
                                                            class="form-control">
                                                        <?php
                                                        }
                                                        ?>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label>暱稱</label>
                                                        <input type="text" placeholder="Enter nickname"
                                                            class="form-control" name="nickname"
                                                            value="<?= $agent['nickname'] ?>">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-12">
                                                    <div class="form-group">
                                                        <label><?= !isset($agent) ? '<span style="color:red">*</span>' : '' ?>電子郵件</label>
                                                        <input type="email" placeholder="Enter email"
                                                            class="form-control" name="email"
                                                            value="<?= $agent['email'] ?>">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-12">
                                                    <div class="form-group">
                                                        <label><?= !isset($agent) ? '<span style="color:red">*</span>' : '' ?>密碼</label>
                                                        <input type="password" placeholder="Password"
                                                            class="form-control" name="password">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-12">
                                                    <div class="form-group">
                                                        <label><?= !isset($agent) ? '<span style="color:red">*</span>' : '' ?>確認密碼</label>
                                                        <input type="password" placeholder="Confirm Password"
                                                            class="form-control" name="password_confirmation">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label>國家</label>
                                                        <select id="selectize-dropdown" name="country_id">
                                                            <?php
                                                            foreach ($_SESSION['country'] as $key => $value) {
                                                                ?>
                                                            <option value="<?= $key ?>"
                                                                <?= ($agent['country_id'] == $key) ? 'selected' : '' ?>>
                                                                <?= $value ?></option>
                                                            <?php
                                                            }
                                                            ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label>城市</label>
                                                        <input type="text" placeholder="City/Zone" class="form-control"
                                                            name="city" value="<?= $agent['city'] ?>">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label>地址</label>
                                                        <input type="text" placeholder="Address" class="form-control"
                                                            name="address" value="<?= $agent['address'] ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label>連絡電話</label>
                                                        <input type="text" placeholder="Mobile" class="form-control"
                                                            name="mobile" value="<?= $agent['mobile'] ?>">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label>分成百分比</label>
                                                        <input type="text" placeholder="18" class="form-control"
                                                            name="percentage" value="<?= $agent['percentage'] ?>"
                                                            <?= ($agent['parent'] != 1 && $agent['id'] != '') ? 'disabled' : '' ?>>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label>狀態</label>
                                                        <div class="col-md-10">
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="status" value="1" id="rad1"
                                                                    <?= ($agent['status'] == 1) ? 'checked' : '' ?>>
                                                                <label for="rad1">啟用</label>
                                                            </div>
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="status" value="0" id="rad2"
                                                                    <?= ($agent['status'] != 1) ? 'checked' : '' ?>>
                                                                <label for="rad2">停用</label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label>是否有PC版</label>
                                                        <div class="col-md-10">
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="has_pc" value="1" id="pc1"
                                                                    <?= ($agent['has_pc'] == 1) ? 'checked' : '' ?>>
                                                                <label for="pc1">有</label>
                                                            </div>
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="has_pc" value="0" id="pc2"
                                                                    <?= ($agent['has_pc'] != 1) ? 'checked' : '' ?>>
                                                                <label for="pc2">沒有</label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label>是否有手機版</label>
                                                        <div class="col-md-10">
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="has_mobile" value="1"
                                                                    id="mob1"
                                                                    <?= ($agent['has_mobile'] == 1) ? 'checked' : '' ?>>
                                                                <label for="mob1">有</label>
                                                            </div>
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="has_mobile" value="0"
                                                                    id="mob2"
                                                                    <?= ($agent['has_mobile'] != 1) ? 'checked' : '' ?>>
                                                                <label for="mob2">沒有</label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label>是否開放聊天室</label>
                                                        <div class="col-md-10">
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="has_chat" value="1" id="chat1"
                                                                    <?= ($agent['has_chat'] == 1) ? 'checked' : '' ?>>
                                                                <label for="chat1">有</label>
                                                            </div>
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="has_chat" value="0" id="chat2"
                                                                    <?= ($agent['has_chat'] != 1) ? 'checked' : '' ?>>
                                                                <label for="chat2">沒有</label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label>是否有線上儲值</label>
                                                        <div class="col-md-10">
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="has_online_payment" value="1"
                                                                    id="pay1"
                                                                    <?= ($agent['has_online_payment'] == 1) ? 'checked' : '' ?>>
                                                                <label for="pay1">有</label>
                                                            </div>
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="has_online_payment" value="0"
                                                                    id="pay2"
                                                                    <?= ($agent['has_online_payment'] != 1) ? 'checked' : '' ?>>
                                                                <label for="pay2">沒有</label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label>是否有APP</label>
                                                        <div class="col-md-10">
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="has_app" value="1" id="app1"
                                                                    <?= ($agent['has_app'] == 1) ? 'checked' : '' ?>>
                                                                <label for="app1">有</label>
                                                            </div>
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="has_app" value="0" id="app2"
                                                                    <?= ($agent['has_app'] != 1) ? 'checked' : '' ?>>
                                                                <label for="app2">沒有</label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label>是否開放前端註冊</label>
                                                        <div class="col-md-10">
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="frontend_register" value="1"
                                                                    id="f1"
                                                                    <?= ($agent['frontend_register'] == 1) ? 'checked' : '' ?>>
                                                                <label for="f1">有</label>
                                                            </div>
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="frontend_register" value="0"
                                                                    id="f2"
                                                                    <?= ($agent['frontend_register'] != 1) ? 'checked' : '' ?>>
                                                                <label for="f2">沒有</label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label>進入遊戲時是否將所有錢轉入</label>
                                                        <div class="col-md-10">
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="transaction_all" value="1"
                                                                    id="ta1"
                                                                    <?= ($agent['transaction_all'] == 1) ? 'checked' : '' ?>>
                                                                <label for="ta1">是</label>
                                                            </div>
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="transaction_all" value="0"
                                                                    id="ta2"
                                                                    <?= ($agent['transaction_all'] != 1) ? 'checked' : '' ?>>
                                                                <label for="ta2">否</label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label>是否可以在代理後台開洗分</label>
                                                        <div class="col-md-10">
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="agent_backend_transaction"
                                                                    value="1" id="abt1"
                                                                    <?= ($agent['agent_backend_transaction'] == 1) ? 'checked' : '' ?>>
                                                                <label for="abt1">是</label>
                                                            </div>
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="agent_backend_transaction"
                                                                    value="0" id="abt2"
                                                                    <?= ($agent['agent_backend_transaction'] != 1) ? 'checked' : '' ?>>
                                                                <label for="abt2">否</label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-12 col-xs-6">
                                                    <div class="text-right mrg-top-5">
                                                        <input type="hidden" name="action" value="save_agent">
                                                        <input type="button" class="btn btn-default" value="回上頁"
                                                            onclick="history.back();" />
                                                        <button type="submit" class="btn btn-primary"
                                                            id="save">儲存</button>
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
    </div>
    <!-- Content Wrapper END -->
    <?php
    require_once '../_bottom.php';
    ?>
</div>
<!-- Page Container END -->
<?php
require_once '../_footer.php';
?>
<script>
$(document).ready(function() {
    $("#save").click(function(e) {
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
        $.post("<?= BASE_URL ?>ajax.php", $("#data-form").serialize(), function(data) {
            $.unblockUI();
            if (data.code == 1000) {
                history.back();
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