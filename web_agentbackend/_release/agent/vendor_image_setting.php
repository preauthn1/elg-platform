<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';

//確認權限
// if (!isset($check_auth['agent/edit']) && $_GET['id'] != $_SESSION['id']) {
//     echo $lang['nopermission'];
//     exit;
// }

//新增頁，部分設置要從當前代理帶預設值
$post_data = array();
$post_data['group_id'] = $_GET['group_id'];
$result = curlPost('api/agent/vendor_image_settings', $post_data);
$api_response = $result['data'];

$code = $result["code"];

if ($code != 1000) {
    echo display_error_msg_html($api_response["message"]);
    exit;
}

$groups = $api_response["data"];
$queryGroup = $api_response["groups"];


?>
<!-- Page Container START -->
<div class="page-container">
    <?php
    require_once '../_top.php';
    require_once '../_theme.php';
    ?>
    <!-- Content Wrapper START -->
    <div class="main-content">
        <div class="container-fluid">

            <div class="page-title">
                <h4>
                    <?= $lang['agnet_management'] ?> >
                    <?= $lang['menu_agent_advance_setting'] ?>
                </h4>
            </div>


            <div>

                <div></div>

                <div class="agent_name_row">


                    <div class="row">
                        <div class="col-md-6">
                            <span>
                                <b>
                                    <?= $lang["name"] ?>
                                </b>
                            </span>：

                            <span>
                                <?= $queryGroup["name"] ?>
                            </span>

                            <span>
                                (<?= $queryGroup["id"] ?>)
                            </span>

                            <a href="/agent/group_list.php" style="float:right" class="linkText">
                                <?= $lang["btn_back"] ?>
                            </a>
                        </div>

                        <div class="col-md-6"></div>

                    </div>


                </div>

                <div>
                    <hr>
                </div>
            </div>


            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <h4></h4>
                            <button type="button" class="btn btn-success" id="save" style="float:right">儲存</button>
                            <div class="table-overflow">
                                <form id="popup-data-form">
                                    <input type="hidden" id="group_id" name="group_id" value="<?= $queryGroup["id"] ?>">
                                    <table class="table table-lg table-hover">
                                        <thead>
                                        <tr>
                                            <th><?= $lang['id'] ?></th>
                                            <th><?= $lang['vendor_name'] ?></th>
                                            <th><?= $lang['name'] ?></th>
                                            <th><?= $lang['show'] . $lang['picture'] ?></th>
                                            <th>遊戲商APP ICON</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php
                                        if (count($groups) > 0 && $code == 1000) {
                                            foreach ($groups as $row) {
                                                ?>
                                                <tr>
                                                    <td>
                                                        <div class="mrg-top-10">
                                                            <?= $row['id'] ?>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div class="mrg-top-10">
                                                            <?= $row['name'] ?>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div class="mrg-top-10">
                                                            <input type="text" name="show_name<?= $row["id"] ?>"
                                                                   value="<?= $row["show_name"] ?>">
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div class="mrg-top-15">
                                                            <input type="text" name="image<?= $row["id"] ?>"
                                                                   value="<?= $row["image"] ?>">
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div class="mrg-top-15">
                                                            <input type="text" name="app_icon<?= $row["id"] ?>"
                                                                   value="<?= $row["app_icon"] ?>">
                                                        </div>
                                                    </td>
                                                </tr>
                                                <?php
                                            }
                                        } else { ?>
                                            <tr>
                                                <td colspan="5" align='center'>
                                                    <div class="mrg-top-15">
                                                        <span class="text-info"><b><?= $lang['no_record'] ?></b></span>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php }
                                        ?>
                                        </tbody>
                                    </table>
                                    <input type="hidden" name="action" value="update_vendor_image">
                                </form>
                            </div>
                            <?php
                            if (count($record['data']) > 0) {
                                ?>
                                <div class="dataTables_info" id="dt-opt_info" role="status" aria-live="polite">
                                    <?= $lang['total'] ?> <?= $record['pagination']['total'] ?> <?= $lang['count'] ?>
                                </div>
                                <div class="dataTables_paginate paging_simple_numbers" id="dt-opt_paginate">
                                    <?= $page->createPager($record['pagination']['pages'], $record['pagination']['current'], $lang) ?>
                                </div>
                            <?php } ?>
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
    $(document).ready(function () {

        //變更日期格式
        $('.datetime-picker').daterangepicker({
            singleDatePicker: true,
            timePicker: true,
            timePicker24Hour: true,
            timePickerIncrement: 1,
            locale: {
                format: 'YYYY-MM-DD HH:mm'
            }
        });


    });


    $("#save").click(function (e) {
        // if (e.preventDefault) {
        //     e.preventDefault();
        // } else {
        //     window.event.returnValue == false;
        // }
        // $.blockUI({
        //     baseZ: 9000,
        //     overlayCSS: {
        //         backgroundColor: '#444444'
        //     }
        // })
        console.log($("#popup-data-form").serialize());
        $.post("<?= BASE_URL ?>ajax.php", $("#popup-data-form").serialize(), function (data) {
            $.unblockUI();
            if (data.code == 1000) {
                alert('success');
            } else {
                if (data.code == 2002) {
                    alert(data.data);
                } else {
                    alert(data.message);
                }
            }
        }, 'json');
    });

</script>


<style>
    .disabledStyle {
        opacity: .5;
    }


    .group_name {
        display: inline-block;
        font-size: 24px;
        font-weight: 700;
    }

    .group-row {
        position: relative;
    }

    .btn-row {
        display: inline-block;
        position: absolute;
        right: 5px;
    }

    .setting-panel {
        padding: 25px;
        margin-bottom: 35px;
    }

    .label-text {
        color: #646464;
    }


    .form-group {
        margin-bottom: 15px;
    }

    .agent_name_row {
        font-size: 16px;
        font-weight: 100;
    }

    .agent_name_row b {
        font-weight: 700;
    }


    .groupCard {

        padding: 25px;
        border-radius: 10px;
        border: solid 1px #EEE;
        margin-bottom: 35px;
        background: #FFF;
    }

    .sub-category {
        padding: 0px 0px 0px 20px;
        margin-bottom: 5px;
    }

    .sub-category span,
    .sub-category input {
        display: inline-block;
    }

    .language-label {
        display: inline-block;
        width: 125px;
        color: #999;
    }


    .disabledEdit .radio-label {
        opacity: .5;
    }


    /*
    .form-group input {
        width: 450px;
    } */
</style>