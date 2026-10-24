<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';

//確認權限
if (!isset($check_auth['withdrawal/bank_list'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

$data = array();
$data['page'] = 1;
$data['per_page'] = 100;
$result = curlPost('api/withdrawal/showBankList', $data);
if ($result['code'] != 1000) {
    if ($result['code'] > 9997) {
        header("location:" . BASE_URL . "login.php");
    } else {
        header('Location:' . $_SERVER['HTTP_REFERER']);
    }
    exit();
}
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
                <h4><?= $lang['menu_auto_withdrawal'] ?> > <?= $lang['bank_management'] ?></h4>
            </div>
            <div class="row">
                <a href="bank_edit.php" class="btn btn-inverse btn-success"><?= $lang['add_bank'] ?></a>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="table-overflow">
                                <table id="dt-custom" class="table table-lg table-hover">
                                    <thead>
                                    <tr>
                                        <th><?= $lang['bank_name'] ?></th>
                                        <th><?= $lang['bank_code'] ?></th>
                                        <th><?= $lang['status'] ?></th>
                                        <th></th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php
                                    foreach ($result['data'] as $row) {
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span class="pdd-left-20"><b><?= $row['bank_name'] ?></b></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span class="pdd-left-20"><?= $row['bank_code'] ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15 <?= ($row['status'] == 1) ? '' : 'text-danger' ?>">
                                                    <?= ($row['status'] == 1) ? $lang['enable'] : $lang['disable'] ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-10 text-center">
                                                    <div class="dropdown inline-block">
                                                        <button class="btn btn-default btn-sm dropdown-toggle"
                                                                type="button" data-toggle="dropdown">
                                                            <i class="ti-angle-down font-size-9"></i>
                                                        </button>
                                                        <ul class="dropdown-menu">
                                                            <li>
                                                                <a
                                                                        href="bank_edit.php?id=<?= $row['bank_id'] ?>"><?= $lang['edit_bank'] ?></a>
                                                            </li>
                                                            <li>
                                                                <a
                                                                        href="name_edit.php?id=<?= $row['bank_id'] ?>&name=<?= $row['bank_name'] ?>"><?= $lang['edit_bank_name'] ?></a>
                                                            </li>
                                                            <li>
                                                                <a href="#" class="withdrawal_bank_toggle_status"
                                                                   id="<?= $row['bank_id'] ?>"
                                                                   status="<?= ($row['status'] != 1) ? 1 : 0 ?>"><?= ($row['status'] != 1) ? $lang['enable'] : $lang['disable'] ?></a>
                                                            </li>
                                                        </ul>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php
                                    }
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
        $('#dt-custom').dataTable({
            'order': [
                [2, 'desc'],
                [0, 'asc']
            ],
            'searching': true,
            'paging': true
        });
    });

    $(document).on('click', '.withdrawal_bank_toggle_status', function (e) {
        if (e.preventDefault) {
            e.preventDefault();
        } else {
            window.event.returnValue == false;
        }
        var id = $(this).attr('id');
        var status = $(this).attr('status');
        $.blockUI({
            baseZ: 9000,
            overlayCSS: {
                backgroundColor: '#444444'
            }
        })
        $.post("<?= BASE_URL ?>ajax.php", {
            'action': 'withdrawal_bank_toggle_status',
            'id': id,
            'status': status
        }, function (data) {
            $.unblockUI();
            if (data.code == '1000') {
                window.location.reload();
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
