<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';
if (!isset($check_auth['member/edit'])) {
    echo $Lang['nopermission'];
    exit;
}

// 可選擇玩家是在哪個代理下
$data = array();
$data['include_sub_account'] = 0;
$data['include_self'] = 1;
$data['page'] = 1;
$data['per_page'] = 10000;
$agents = curlPost('api/agent/list', $data);
$agents = $agents['data'];

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
        $result = curlPost('api/member/view', $data);
        if ($result['code'] == 1000) {
            $member = $result['data'];
        }
    }
    $country = curlPost('api/country/list');
    $country = $country['data'];
    ?>
    <!-- Content Wrapper START -->
    <div class="main-content">
        <div class="container-fluid">
            <div class="page-title">
                <h4><?= $lang['member_lower_management'] ?> > <?= $lang['member_account_management'] ?> >
                    <?= $lang['detailinfo_user'] ?> > <?= $lang['detailinfo_edit'] ?></h4>
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
                                                        <label><?= $lang['agent_account'] ?></label>
                                                        <?php
                                                        if (isset($member)) {
                                                        ?>
                                                        <p class="text-success"><?= $member['agent_username'] ?></p>
                                                        <?php
                                                        } else {
                                                        ?>
                                                        <select class="selectize-class" name="parent">
                                                            <?php
                                                                foreach ($agents as $row) {
                                                                ?>
                                                            <option value="<?= $row['id'] ?>"
                                                                <?= ($_SESSION['id'] == $row['id']) ? 'selected' : '' ?>>
                                                                <?= $row['username'] ?></option>
                                                            <?php
                                                                }
                                                                ?>
                                                        </select>
                                                        <?php
                                                        }
                                                        ?>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label><?= !isset($member) ? '<span style="color:red">*</span>' : '' ?><?= $lang['username'] ?></label>
                                                        <?php
                                                        if (isset($member)) {
                                                        ?>
                                                        <input type="hidden" name="id" value="<?= $_GET['id'] ?>">
                                                        <p class="text-info"><?= $member['username'] ?></p>
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
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label><?= !isset($member) ? '<span style="color:red">*</span>' : '' ?><?= $lang['password'] ?></label>
                                                        <input type="password" placeholder="Password"
                                                            class="form-control" name="password">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label><?= !isset($member) ? '<span style="color:red">*</span>' : '' ?><?= $lang['password_confirm'] ?></label>
                                                        <input type="password" placeholder="Confirm Password"
                                                            class="form-control" name="password_confirmation">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label><?= $lang['first_name'] ?></label>
                                                        <input type="text" placeholder="Enter first name"
                                                            class="form-control" name="first_name"
                                                            value="<?= $member['first_name'] ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label><?= $lang['last_name'] ?></label>
                                                        <input type="text" placeholder="Enter last name"
                                                            class="form-control" name="last_name"
                                                            value="<?= $member['last_name'] ?>">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label><?= $lang['nickname'] ?></label>
                                                        <input type="text" placeholder="Enter nickname"
                                                            class="form-control" name="nickname"
                                                            value="<?= $member['nickname'] ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label><?= $lang['email'] ?></label>
                                                        <input type="email" placeholder="Enter email"
                                                            class="form-control" name="email"
                                                            value="<?= $member['email'] ?>">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label><?= $lang['country'] ?></label>
                                                        <select id="selectize-dropdown" name="country_id">
                                                            <?php
                                                            foreach ($_SESSION['country'] as $key => $value) {
                                                            ?>
                                                            <option value="<?= $key ?>"
                                                                <?= ($member['country_id'] == $key) ? 'selected' : '' ?>>
                                                                <?= $value ?></option>
                                                            <?php
                                                            }
                                                            ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label><?= $lang['city'] ?></label>
                                                        <input type="text" placeholder="City/Zone" class="form-control"
                                                            name="city" value="<?= $member['city'] ?>">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label><?= $lang['address'] ?></label>
                                                        <input type="text" placeholder="Address" class="form-control"
                                                            name="address" value="<?= $member['address'] ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label><?= $lang['tel'] ?>(<?= $lang['mobile_filter_zero'] ?>)</label>
                                                        <?php
                                                        if ($member['mobile'] == '') {
                                                        ?>
                                                        <select name="country_code">
                                                            <?php
                                                                foreach ($country as $row) {
                                                                ?>
                                                            <option value="<?= $row['country_code'] ?>">
                                                                <?= $row['name'] . ' +' . $row['country_code'] ?>
                                                            </option>
                                                            <?php
                                                                }
                                                                ?>
                                                        </select>
                                                        <?php
                                                        }
                                                        ?>
                                                        <input type="text" placeholder="Mobile" class="form-control"
                                                            name="mobile" value="<?= $member['mobile'] ?>">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label><?= $lang['status'] ?></label>
                                                        <div class="col-md-10">
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="status" value="1" id="rad1"
                                                                    <?= ($member['status'] == 1) ? 'checked' : '' ?>>
                                                                <label for="rad1"><?= $lang['enable'] ?></label>
                                                            </div>
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="status" value="0" id="rad2"
                                                                    <?= ($member['status'] != 1) ? 'checked' : '' ?>>
                                                                <label for="rad2"><?= $lang['disable'] ?></label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-12 col-xs-6">
                                                    <div class="text-right mrg-top-5">
                                                        <input type="hidden" name="action" value="save_member">
                                                        <input type="button" class="btn btn-default"
                                                            value="<?= $lang['btn_back'] ?>"
                                                            onclick="history.back();" />
                                                        <button type="submit" class="btn btn-primary"
                                                            id="save"><?= $lang['btn_update'] ?></button>
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
                if ("<?= $_GET['id'] ?>" == '') {
                    window.location = 'index.php';
                } else {
                    history.back();
                }
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