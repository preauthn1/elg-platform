<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';

//確認權限
if (!isset($check_auth['game/index'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

?>
<!-- Page Container START -->
<div class="page-container">
    <?php
    require_once '../_top.php';
    require_once '../_theme.php';
    // 廠商列表
    $data = array();
    $data['type'] = 1;
    $result = curlPost('api/vendor/dropdownList', $data);
    if ($result['code'] == 1000) {
        $vendors = array();
        foreach ($result['data'] as $row) {
            $vendors[$row['id']] = $row['name'];
        }
    }
    // 遊戲類型
    $result = curlPost('api/gameType/list');
    if ($result['code'] == 1000) {
        $types = array();
        foreach ($result['data'] as $row) {
            if ($row['parent'] == 0) {
                $types[$row['id']] = $row['name'];
            } else {
                $types[$row['id']] = $types[$row['parent']] . '>' . $row['name'];
            }
        }
    }
    $data = [
        'page' => 1,
        'per_page' => 1000
    ];

    $isEditPage = isset($_GET['id']);
    $gameID = $_GET['id'];
    
    if ($isEditPage) {
        $data['id'] = $_GET['id'];
        $result = curlPost('api/game/view', $data);
        if ($result['code'] != 1000) {
            if ($result['code'] > 9997) {
                header("location:" . BASE_URL . "login.php");
            } else {
                header('Location:' . $_SERVER['HTTP_REFERER']);
            }
            exit();
        }
        $game = $result['data'];
        $language_data = array();
        foreach ($game['names'] as $row) {
            $language_data[$row['language_id']] = $row;
        }

        $vendor_info = curlPost('api/vendor_info/getVendorInfoGame', ['id' => $_GET['id']]);
        $debug = "";
    }
    ?>
    <!-- Content Wrapper START -->
    <div class="main-content">
        <div class="container-fluid">
            <div class="page-title">
                <h4><?= $lang['menu_new_vendor'] ?> > <?= $lang['menu_game_management'] ?> > <?= $lang['edit'] ?></h4>
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
                                                        <label>遊戲廠商</label>
                                                        <select id="selectize-dropdown1" name="vendor_id">
                                                            <?php
                                                            foreach ($vendors as $key => $value) {
                                                                ?>
                                                            <option value="<?= $key ?>"
                                                                <?= ($game['vendor_id'] == $key) ? 'selected' : '' ?>>
                                                                <?= $value ?></option>
                                                            <?php
                                                            }
                                                            ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label>遊戲類型</label>
                                                        <select id="selectize-dropdown2" name="type_id">
                                                            <?php
                                                            foreach ($types as $key => $value) {
                                                                ?>
                                                            <option value="<?= $key ?>"
                                                                <?= ($game['type_id'] == $key) ? 'selected' : '' ?>>
                                                                <?= $value ?></option>
                                                            <?php
                                                            }
                                                            ?>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="card">
                                                <div class="card-block">
                                                    <div class="pill-info nav-vertical nav-stacked">
                                                        <ul class="nav nav-pills" role="tablist">
                                                            <li class="nav-item">
                                                                <a href="#default" class="nav-link active" role="tab"
                                                                    data-toggle="tab">預設</a>
                                                            </li>
                                                            <?php
                                                            foreach ($_LANGUAGES as $key => $value) {
                                                                ?>
                                                            <li class="nav-item">
                                                                <a href="#pills-vertical-<?= $value['id'] ?>"
                                                                    class="nav-link" role="tab"
                                                                    data-toggle="tab"><?= $value['name'] ?></a>
                                                            </li>
                                                            <?php
                                                            }
                                                            ?>
                                                        </ul>
                                                        <div class="tab-content" style="margin-top: 5px">
                                                            <div role="tabpanel" class="tab-pane fade in active"
                                                                id="default">
                                                                <div class="form-group">
                                                                    <p class="mrg-btm-15 font-size-13"><span
                                                                            style="color:red">*</span>遊戲名稱</p>
                                                                    <input type="text" class="form-control" name="name"
                                                                        value="<?= $game['name'] ?>">
                                                                </div>
                                                            </div>
                                                            <?php
                                                            foreach ($_LANGUAGES as $key => $value) {
                                                                ?>
                                                            <div role="tabpanel" class="tab-pane fade"
                                                                id="pills-vertical-<?= $value['id'] ?>">
                                                                <div class="form-group">
                                                                    <p class="mrg-btm-15 font-size-13">遊戲名稱</p>
                                                                    <input type="text" class="form-control"
                                                                        name="names[<?= $value['id'] ?>]"
                                                                        value="<?= $language_data[$value['id']]['name'] ?>">
                                                                </div>
                                                            </div>
                                                            <?php
                                                            }
                                                            ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label>遊戲代碼</label>
                                                        <input type="text" name="game_type"
                                                            value="<?= $game['game_type'] ?>" class="form-control">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label>顯示排序(數字越大越前)</label>
                                                        <input type="text" name="sort"
                                                            value="<?= $game['sort'] ? $game['sort'] : 0 ?>"
                                                            class="form-control"
                                                            oninput="value=value.replace(/[^\d\-]/g,'')" maxlength="8">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label>備註</label>
                                                        <input type="text" name="memo" value="<?= $game['memo'] ?>"
                                                            class="form-control" maxlength="50">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="form-group">
                                                        <label>狀態</label>
                                                        <div class="col-md-10">
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="status" value="1" id="rad1"
                                                                    <?= ($game['status'] == 1 || !isset($game['status'])) ? 'checked' : '' ?>>
                                                                <label for="rad1">啟用</label>
                                                            </div>
                                                            <div class="radio radio-inline">
                                                                <input type="radio" name="status" value="0" id="rad2"
                                                                    <?= ($game['status'] != 1 and isset($game['status'])) ? 'checked' : '' ?>>
                                                                <label for="rad2">停用</label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-12">
                                                    <div class="form-group">
                                                        <label>圖片</label>
                                                        <div>
                                                            <label for="img-upload" class="pointer">
                                                                <img src="<?= $game['image'] ?>" id="show_image"
                                                                    style="max-height:300px;max-width:300px" />
                                                                <input type="hidden" name="image">
                                                                <span
                                                                    class="btn btn-default display-block no-mrg-btm">Choose
                                                                    file</span>
                                                                <input class="d-none" type="file" name="image_file"
                                                                    id="img-upload">
                                                            </label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>



                                            <?php if($isEditPage) { ?>

                                            <div class="col-md-12" style="margin-bottom:10px;padding-left: 0px;">
                                                <hr>
                                                <h3>
                                                    遊戲商戶設定


                                                    <button class='btn'
                                                        onclick="insert_unassign_vendorInfo('<?= $gameID ?>')">
                                                        自動產生vendor_info_games
                                                    </button>
                                                </h3>



                                                <div style="clear:both"></div>
                                            </div>

                                            <?php } ?>


                                            <div class='vigWrapper'>

                                                <?php foreach ($vendor_info['data'] as $row) { ?>

                                                <?php 

                                            $elementID1 = "radio_item_enable_".$row['id'];
                                            $elementID2 = "radio_item_disable_".$row['id'];

                                                ?>

                                                <div class="row vigItem">

                                                    <div class="col-md-3" title="商戶名稱">
                                                        <div class=''>
                                                            <div class="vifName">
                                                                <?= $row['name'] ?>
                                                            </div>
                                                            <div style="margin-top:10px;">
                                                                <div class="radio radio-inline">
                                                                    <input type="radio" value="1"
                                                                        id="<?= $elementID1 ?>"
                                                                        name="vig_status<?= $row['id'] ?>"
                                                                        <?= ($row['status'] == 1 || !isset($row['status'])) ? 'checked' : '' ?>>
                                                                    <label for="<?= $elementID1 ?>">啟用</label>
                                                                </div>
                                                                <div class="radio radio-inline">
                                                                    <input type="radio" value="0"
                                                                        id="<?= $elementID2 ?>"
                                                                        name="vig_status<?= $row['id'] ?>"
                                                                        <?= ($row['status'] != 1 and isset($row['status'])) ? 'checked' : '' ?>>
                                                                    <label for="<?= $elementID2 ?>">停用</label>
                                                                </div>
                                                            </div>

                                                        </div>
                                                    </div>


                                                    <div class="col-md-9">


                                                        <div class="row form-items">

                                                            <div class="col-md-4">
                                                                <input id="is_new<?= $row['id'] ?>" type="checkbox"
                                                                    name="is_new<?= $row['id'] ?>"
                                                                    <?= ($row['is_new'] == 1) ? 'checked' : '' ?> ?>

                                                                <label for="is_new<?= $row['id'] ?>">
                                                                    新遊戲
                                                                </label>


                                                            </div>
                                                            <div class="col-md-4">
                                                                <input id="is_hot<?= $row['id'] ?>" type="checkbox"
                                                                    name="is_hot<?= $row['id'] ?>"
                                                                    <?= ($row['is_hot'] == 1) ? 'checked' : '' ?> ?>

                                                                <label for="is_hot<?= $row['id'] ?>">
                                                                    熱門遊戲
                                                                </label>

                                                            </div>
                                                            <div class="col-md-4">
                                                                <input id="join_bet_event<?= $row['id'] ?>"
                                                                    type="checkbox"
                                                                    name="join_bet_event<?= $row['id'] ?>"
                                                                    <?= ($row['join_bet_event'] == 1) ? 'checked' : '' ?>
                                                                    ?>
                                                                <label for="join_bet_event<?= $row['id'] ?>">
                                                                    投注活動
                                                                </label>


                                                            </div>
                                                            <div class="col-md-4">
                                                                <input id="join_win_event<?= $row['id'] ?>"
                                                                    type="checkbox"
                                                                    name="join_win_event<?= $row['id'] ?>"
                                                                    <?= ($row['join_win_event'] == 1) ? 'checked' : '' ?>
                                                                    ?>
                                                                <label for="join_win_event<?= $row['id'] ?>">
                                                                    贏分活動
                                                                </label>
                                                            </div>




                                                            <div class="col-md-4">
                                                            </div>

                                                            <div class="col-md-4">
                                                            </div>

                                                            <div class="col-md-4">
                                                                排序
                                                                <input type="text" name="sort<?= $row['id'] ?>"
                                                                    class="form-control" value="<?= $row['sort'] ?>" ?>
                                                            </div>
                                                            <div class="col-md-4">
                                                                無限代有效投注轉換比例
                                                                <input type="text"
                                                                    name="valid_bet_rate<?= $row['id'] ?>"
                                                                    class="form-control"
                                                                    value="<?= $row['valid_bet_rate'] ?>" ?>
                                                            </div>


                                                            <div class="col-md-4"></div>

                                                        </div>

                                                    </div>

                                                </div>
                                                <!-- <hr> -->
                                                <?php } ?>

                                            </div>

                                            <div class="row">
                                                <div class="col-md-12 col-xs-6">
                                                    <div class="text-right mrg-top-5">
                                                        <input type="hidden" name="action" value="save_game">
                                                        <input type="hidden" name="id" value="<?= $_GET['id'] ?>">
                                                        <input type="button" class="btn btn-default" value="回上頁"
                                                            onclick="history.back();" />
                                                        <button type="submit" class="btn btn-primary" id="save">儲存
                                                        </button>
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
<script type="text/javascript">
$(document).ready(function() {
    $('#selectize-dropdown1').selectize({
        create: false,
        sortField: {
            field: 'text',
            direction: 'asc'
        },
        dropdownParent: 'body'
    });

    $('#selectize-dropdown2').selectize({
        create: false,
        sortField: {
            field: 'text',
            direction: 'asc'
        },
        dropdownParent: 'body'
    });

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

                let isToEditPage = data.data && data.data > 0;
                let gameID = 0;

                if (isToEditPage) {
                    gameID = data.data;
                }

                Swal.fire({
                    icon: 'success',
                    title: '儲存成功！是否返回遊戲列表?',
                    showDenyButton: true,
                    confirmButtonText: `返回列表頁`,
                    denyButtonText: `繼續編輯本遊戲`,
                }).then((result) => {
                    /* Read more about isConfirmed, isDenied below */
                    if (result.isConfirmed) {
                        window.location.href = "/game/index.php";
                    } else {
                        if (isToEditPage) {
                            window.location.href = "/game/edit.php?id=" + gameID;
                        } else {
                            location.reload();
                        }
                    }
                })

            } else {
                if (data.code == '2002') {
                    alert(data.data);
                } else {
                    alert(data.message);
                }
            }
        }, 'json');
    });

    //上傳圖片後可預覽
    $('#img-upload').change(function(e) {
        if (e.preventDefault) {
            e.preventDefault();
        } else {
            window.event.returnValue == false;
        }
        var file = document.querySelector('input[type="file"]').files[0];
        getBase64(file); // prints the base64 string
    });
});

//圖片轉成BASE64
function getBase64(file) {
    var reader = new FileReader();
    reader.readAsDataURL(file);
    reader.onload = function() {
        //RETURN後變undefined，所以先在這處理
        var image = reader.result;
        $('#show_image').attr('src', image);
        $('[name="image"]').val(image);
        return reader.result;
    };
    reader.onerror = function(error) {
        return 'Error: ', error;
    };
}


function insert_unassign_vendorInfo(game_id) {

    $.blockUI({
        baseZ: 9000,
        overlayCSS: {
            backgroundColor: '#444444'
        }
    })
    $form = "action=insert_unassign_vendorInfo&game_id=" + game_id;

    $.post("<?= BASE_URL ?>ajax.php", $form, function(data) {
        $.unblockUI();

        if (data.code == 1000) {
            alert('成功! 將自動刷新此頁面');
            location.reload();
        } else {
            alert(data.message);
        }
    }, 'json');
}
</script>


<style>
.form-items .col-md-4 {
    padding: 5px;
}

.vigItem {
    padding: 30px 15px 30px 10px;
    border: solid 1px #EEE;
    margin-left: 30px;
}

.vigWrapper .vigItem:not(:last-child) {
    border-bottom: 0px;
}

.vifName {
    color: #888;
    font-size: 16px;
    /* font-weight: 700; */
}
</style>