<?php
require_once '_init.php';
require_once 'commonFunction.php';
require_once '_header.php';

$data = array();
$data['type'] = isset($_GET['type']) ? $_GET['type'] : 0;
$data['page'] = isset($_GET['page']) ? $_GET['page'] : 1;
$data['per_page'] = 20;
$result = curlPost('api/announcement/showByAgentId', $data);
if ($result['code'] >= 9997) {
    header("location:" . BASE_URL . "login.php");
    exit();
}

?>
<div class="main-content">
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-block">
                        <div style="text-align:center;font-size:large;"
                        <h4><?= $lang['menu_backend_announcement'] ?></h4>
                    </div>
                    <div class="table-overflow">
                        <table class="table table-sm table-hover">
                            <thead>
                            <tr>
                                <th width="10%" style="text-align: center;"><?= $lang['num'] ?></th>
                                <th width="20%" align='left'><?= $lang['time'] ?></th>
                                <th width="10%" align='left'><?= $lang['type'] ?></th>
                                <th colspan="2" style="text-align: center;"><?= $lang['content'] ?></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php
                            if (count($result['data']) > 0 && $result['code'] == 1000) {
                                foreach ($result['data'] as $row) {
                                    ?>
                                    <tr>
                                        <td align='center'>
                                            <div class="mrg-top-15">
                                                <?= $row['id'] ?>
                                            </div>
                                        </td>
                                        <td align='left'>
                                            <div class="mrg-top-15">
                                                <?= $row['created_at'] ?>
                                            </div>
                                        </td>
                                        <td align='left'>
                                            <div class="mrg-top-15">
                                                <p style='color:<?= ($row['type'] == 3) ? 'red' : '' ?>'>
                                                    <?php
                                                    switch ($row['type']) {
                                                        case 1 :
                                                            echo $lang['general_announcement'];
                                                            break;
                                                        case 2:
                                                            echo $lang['vendor_announcement'];
                                                            break;
                                                        case 3:
                                                            echo $lang['emergency_announcement'];
                                                            break;
                                                        case 4:
                                                            echo $lang['important_announcement'];
                                                            break;
                                                        default:
                                                            echo $row['type'];
                                                            break;
                                                    }
                                                    ?>
                                                </p>
                                            </div>
                                        </td>
                                        <td colspan="2" align='left'>
                                            <div class="mrg-top-15">
                                                <p class="ellipsis" data-toggle="modal" data-target="#info"
                                                   data-info="<?= $row['content'] ?>"
                                                   data-uid="<?= $row['id'] ?>"
                                                   id="content_info"
                                                   style="cursor:pointer;"><?= $row['content'] ?></p>
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
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</div>
<div class="modal" id="info" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" style="margin: 0 auto;"><?= $lang['announcement_content'] ?></h5>
            </div>
            <div class="modal-body">
                <p style="word-wrap:break-word;white-space: pre-line;" id="model_content"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<script>
    $(function () {
        var len = 100; // 超過50個字以"..."取代
        $(".ellipsis").each(function (i) {
            if ($(this).text().length > len) {
                $(this).attr("title", $(this).text());
                var text = $(this).text().substring(0, len - 1) + "...";
                $(this).text(text);
            }
        });
    });

    $(document).on('click', '#content_info', function (e) {
        $("#model_content").html($(this).attr('data-info'));
        var id = $(this).attr('data-uid');
        $.post("<?= BASE_URL ?>ajax.php", {
            'action': 'update_agent_announcement_status',
            'id': id
        }, function (data) {
            if (data.code == '1000') {
            } else {
                if (data.code == '2002') {
                    alert(data.data);
                } else {
                    alert(data.message);
                }
            }
        }, 'json');
    });
</script>
<style>
    .table {
        width: 100%;
        height: 100%;
        table-layout: fixed;

    }

    /*.ellipsis {
        overflow: hidden;
       white-space: nowrap;
       text-overflow: ellipsis;
    }*/

    .modal-backdrop {
        opacity: 0 !important;
        filter: alpha(opacity=0) !important;
    }
</style>