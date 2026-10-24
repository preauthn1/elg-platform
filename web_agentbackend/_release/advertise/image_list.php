<?php
require_once '../_init.php';
require_once '../class/CreatePage.php';

$data = array();
$data['page'] = isset($_POST['page']) ? $_POST['page'] : 1;
$data['per_page'] = 6;
$result = curlPost('api/advertise/image', $data);
if ($result['code'] != 1000) {
    if ($result['code'] > 9997) {
        header("location:" . BASE_URL . "login.php");
    } else {
        header('Location:' . $_SERVER['HTTP_REFERER']);
    }
    exit();
}
$images = $result['data'];

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
                                <h4 class="card-title"><?= $lang['upload_image'] ?></h4>
                                <div class="table-overflow">
                                    <table class="table table-striped">
                                        <?php
                                        foreach ($images as $row) {
                                            if ($i++ % 2 == 0) {
                                                if ($i != 0) {
                                                    ?></tr><?php
                                                }
                                                ?><tr><?php
                                            }
                                            ?>
                                                    <td class="text-l">
                                                        <img src="<?= $row['image_url'] ?>"
                                                             name="img[]"
                                                             data-id="<?= $row['id'] ?>"
                                                             data-path="<?= $row['image_url'] ?>"
                                                             style="max-height:300px; max-width:300px" />
                                                    </td>
                                            <?php
                                        }
                                        ?>
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
    $(document).ready(function() {
        $('img[name="img[]"]').click(function(e) {
            var name = "<?= $_POST['id'] ?>";
            var path = $(this).attr('data-path');
            $('#show_' + name).attr('src', path);
            $('[name="img_' + name + '"]').val(path);
            $('#id_' + name).val($(this).attr('data-id'));
            $('#advertise_image').click();
        });
    });
</script>