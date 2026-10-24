<?php
require_once '../_init.php';
require_once '../class/CreatePage.php';

$filter = array();
$filter['member_id'] = $_POST['member_id'];
$filter['start_time'] = $_POST['start_time'];
$filter['end_time'] = $_POST['end_time'];
$filter['page'] = isset($_POST['page']) ? $_POST['page'] : 1;
$filter['per_page'] = 10;
$result = curlPost('api/member/flowLimit', $filter);
if ($result['code'] != 1000) {

}
$page = new CreatePage();
?>
<div class="modal-body height-160">
    <a class="modal-close" href="" data-dismiss="modal" style="float: right;">
        <i class="ti-close"></i>
    </a>
    <div class="vertical-align text-center">
        <div class="table-cell">
            <div class="container">
                <div class="row">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-block">
                                <h4 class="card-title"><?= $lang['flow_limit'] ?></h4>
                                <div class="table-overflow">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th class="text-l"><?= $lang['kind'] ?></th>
                                                <th class="text-l"><?= $lang['event_name'] ?></th>
                                                <th class="text-r"><?= $lang['unlimit_bonus'] ?></th>
                                                <th class="text-r"><?= $lang['flow_limit'] ?></th>
                                                <th class="text-r"><?= $lang['completed_amount'] ?></th>
                                                <th><?= $lang['status'] ?></th>
                                                <th><?= $lang['memo'] ?></th>
                                                <th><?= $lang['created_time'] ?>／<?= $lang['operating_time'] ?></th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
										if (count($result['data']) > 0 && $result['code'] == 1000) {
											foreach ($result['data'] as $row) {
												?>
                                            <tr>
                                                <td class="text-l"><?= $lang['transaction_type.'. $row['type']]?></td>
                                                <td class="text-r"><?= $row['title'] ?></td>
                                                <td class="text-r"><?= $row['bonus'] ?></td>
                                                <td class="text-r"><?= $row['flow_limit'] ?></td>
                                                <td class="text-r">
                                                    <?php if($row['status'] == 9) { ?>
                                                    <span>
                                                        -
                                                    </span>
                                                    <?php } else { ?>
                                                    <span>
                                                        <?= $row['valid_flow_limit'] ?>
                                                    </span>
                                                    <?php } ?>
                                                </td>
                                                <td class="<?= "flow_status_".$row['status'] ?>">
                                                    <?= $row["statusText"] ?>
                                                </td>
                                                <td><?= $row['remark'] ?></td>
                                                <td>
                                                    <div><?= $row['created_at'] ?></div>
                                                    <div><?= $row['updated_at'] ?></div>
                                                </td>
                                                <td>
                                                    <div style="min-height: 40px;">
                                                        <?php if($row['status'] == 0) { ?>
                                                        <button class="btn btn-primary"
                                                            onclick="cancel_flow_limit('<?= $row["id"] ?>')">
                                                            <?= $lang["delete"] ?>
                                                        </button>
                                                        <?php } ?>
                                                    </div>
                                                </td>

                                            </tr>
                                            <?php
											}
										}
                                        ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div>
                                    <?php if (count($result['data']) > 0) { ?>
                                    <div class="dataTables_info" id="dt-opt_info" role="status" aria-live="polite">
                                        <?= $lang['total'] ?> <?= $result['pagination']['total'] ?>
                                        <?= $lang['count'] ?>
                                    </div>
                                    <div class="dataTables_paginate paging_simple_numbers" id="dt-opt_paginate">
                                        <?= $page->createPager($result['pagination']['pages'], $result['pagination']['current'], $lang) ?>
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
</div>
<script>
function cancel_flow_limit(id) {

    let msg = "<?= $lang["please_write_memo_info"] ?>";

    Swal.fire({
        title: msg,
        input: 'text',
        inputAttributes: {
            autocapitalize: 'off'
        },
        showCancelButton: true,
        confirmButtonText: '<?= $lang["submit"] ?>',
        cancelButtonText: '<?= $lang["cancel"] ?>',
        showLoaderOnConfirm: true,
        allowOutsideClick: () => !Swal.isLoading()
    }).then((result) => {

        if (result.isConfirmed) {

            let remark = result.value;
            $.blockUI({
                baseZ: 9000,
                overlayCSS: {
                    backgroundColor: '#444444'
                }
            });

            let body = "id=" + id + "&action=cancel_flow_limit&remark=" + remark;
            $.post("<?= BASE_URL ?>ajax.php", body, function(data) {
                $.unblockUI();
                if (data.code == 1000) {
                    successAlert();
                    load_flow_limit(1);
                } else {
                    alert(data.message + "." + data.data);
                }
            }, 'json');

        }
    })

}
</script>
<style>
.flow_status_0 {
    color: #ff3c7e !important;
}

.flow_status_1 {
    color: #2d8c27 !important;
}

.flow_status_9 {
    color: #a326cc !important;
}
</style>