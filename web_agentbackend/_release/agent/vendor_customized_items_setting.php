<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';

//確認權限
if (!isset($check_auth['agent/group_list'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}
//新增頁，部分設置要從當前代理帶預設值
$post_data = array();
$post_data['group_id'] = $_GET['group_id'];

$result = curlPost('api/agent/vendor_customized_item/list', $post_data);
$items = $result['data']['items'];
$vendors = $result['data']['vendors'];
$game_types = $result['data']['game_types'];
$group = $result['data']['group'];

// 內部連結列表
$data = array();
$data['key'] = 'advertise_internal';
$result = curlPost('api/param/list', $data);
if ($result['code'] != 1000) {
    echo 'internal_type';exit;
    if ($result['code'] > 9997) {
        header("location:" . BASE_URL . "login.php");
    } else {
        header('Location:' . $_SERVER['HTTP_REFERER']);
    }
    exit();
} else {
    $internal_types = $result['data'];
}
//var_dump($result);
?>


<!-- Page Container START -->
<div class="page-container">
    <?php
    require_once '../_top.php';
    require_once '../_theme.php';
    ?>
    <!-- page plugins css -->
    <link rel="stylesheet" href="/assets/vendors/dragula-master/dist/dragula.min.css" />
    <link rel="stylesheet" type="text/css" href="https://csshake.surge.sh/csshake.min.css">
    <style>
        #board {
            max-width: 450px;
            margin:auto;
        }
        .item_disable {
            -webkit-filter: grayscale(1);
        }
        .current_edit {
            background-color : #999;
            -webkit-border-radius: 10px;
            -moz-border-radius: 10px;
            border-radius: 10px;
        }
        .delete-btn {
            display:none;
        }
        .current_edit .delete-btn{
            display:inline-block;
        }
        .imageUploadWrapper {
            background-color: #333;
            box-shadow: 0 .125rem .25rem rgba(0,0,0,.075)!important;
        }
        .imagePreview {
            max-width: 100%;
            max-height: 180px;

        }
        .btn-upload {
            float:none;
            display:block;
            border-radius:0px;
            margin:0
        }
    </style>
    <!-- Content Wrapper START -->
    <div class="main-content">
        <div class="container-fluid">

            <div class="page-title">
                <h4>
                    <?= $lang['agnet_management'] ?> >
                    <?= $lang['menu_agent_group'] ?> >
                    <?= $lang['game_vendor_settings'] ?>
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
                                <?= $group["name"] ?>
                            </span>

                            <span>
                                (<?= $group["id"] ?>)
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
                <input id="group_id" type="hidden" value="<?=$_GET['group_id']?>">
                <div class="col-md-6 col-xl-5">
                    <div class="card">
                        <div class="container pt-2">
                            <button id="draggable-save" class="btn btn-info d-none"><i class="fa fa-save"></i> <?= $lang['btn_update'] ?></button>
                            <button id="draggable-cancel" class="btn btn-default d-none"><i class="fa fa-reply"></i> <?= $lang['cancel'] ?></button>
                            <button id="draggable-btn" class="btn btn-default"><i class="fa fa-arrows"></i> <?= $lang['draggable'] ?></button>
                            <button id="add_item" class="btn btn-primary" style="float:left;"><i class="fa fa-plus"></i> <?= $lang['add'] ?></button>
                        </div>
                    </div>
                    <div class="card">
                        <div class="container py-2">
                            <div id="board" class="row">
                            <?php
                            foreach($items as $item) {
                            ?>
                                <div class="col-4 py-2 draggable-item position-relative" data-id="<?=$item['id']?>">
                                    <div class="position-relative w-100<?=$item['status'] == 0?' item_disable':''?>">
                                        <img class="w-100" src="data:image/png;base64,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">
                                        <div class="position-absolute h-100 w-100 d-flex justify-content-center align-items-center" style="top:0;left:0;">
                                            <img class="mw-100 mh-100" src="<?=$item['image']?>" />
                                        </div>
                                        <?php if($item['is_new']) { ?>
                                        <img class="position-absolute" style="top:-2px;left:-2px;width:50px;" src="data:image/png;base64,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">
                                        <?php } ?>
                                        <?php if($item['is_hot']) { ?>
                                        <img class="position-absolute" style="top:-2px;right:-2px;width:50px;" src="data:image/png;base64,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">
                                        <?php } ?>
                                        <?php if($item['status'] == 2) { ?>
                                        <img class="position-absolute" style="bottom:5px;left:-2px;max-width:90%;" src="data:image/png;base64,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">
                                        <?php } ?>
                                    </div>
                                    <button class="btn btn-danger btn-icon position-absolute p-1 delete-btn" style="top:0;right:-5px;" data-name="<?=$item['name']?>" data-id="<?=$item['id']?>"><i class="ti-close"></i></button>
                                    <div class="text-center"><?=$item['name']?></div>
                                </div>
                            <?php
                            }
                            ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-xl-7">
                    <div class="card">
                        <div class="card-block">
                            <h4 class="card-title"><?= $lang["edit"] ?></h4>
                            <form id="editor">
                                <input type="hidden" name="id" value="">
                                <input type="hidden" name="vendor_image">
                                <input type="hidden" name="action" value="save_item">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <div class="imageUploadWrapper">
                                                <div class="d-flex justify-content-center">
                                                    <img src="data:image/gif;base64,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" class="imagePreview" data-default-src="data:image/gif;base64,R0lGODlhLAEsAeYAAAAAAP////f39/b29vX19fT09PPz8/Ly8vHx8fDw8O/v7+7u7u3t7ezs7Ovr6+rq6unp6ejo6Ofn5+bm5uXl5eTk5OPj4+Li4uHh4eDg4N/f397e3t3d3dzc3Nvb29ra2tnZ2djY2NfX19bW1tXV1dTU1NPT09LS0tHR0dDQ0M/Pz87Ozs3NzczMzMvLy8rKysnJycjIyMfHx8bGxsXFxcTExMPDw8LCwsHBwcDAwL+/v76+vr29vby8vLu7u7q6urm5ubi4uLe3t7a2trW1tbS0tLOzs7KysrGxsbCwsK+vr66urq2traysrKurq6qqqqmpqf///wAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAACH5BAEAAFEALAAAAAAsASwBAAf/gA6Cg4SFhoeIiYqLjI2Oj5CRkpOUlZaXmJmam5ydnp+goaKjpKWmp6ipqqusra6vsLGys7S1tre4ubq7vL2+v8DBwsPExcbHyMnKy8zNzs/Q0dLT1NXW19jZ2tvc3d7f4OHi4+Tl5ufo6err7O3u7/Dx8vP09fb3+Pn6+/z9/v8AAwocSLCgwYMIEypcyLChw4cQI0qcSLGixYsYM2rcyLGjx48gQ4ocSbKkyZMoU6pcybKly5cwY8qcSbOmzZs4c+rcybOnz59AgwodSrSo0aNIkypdyrSp06dQo0qdSrWq1atYs2rdyrWr169gw4odS7as2bNo06pdy7atvAaT/+C6PfVg0IO7ePM6aMCX7wO4defmggCBr+BQdxs8gLCob18HdevKPcyJb+EGCRAo2My5s2cFCRYgYMCYcifDDT7IoCGjtevXsGPMoMFBcWDTmzCDENLEiZMmvYEL7+37dxMmSoZkmIx7k4IWTYwMMVLEyBEj2LNjv16EyJEjSUzsba6Jr4IYT4x8iEBhQoUJ8ONPoEB/AoQTSJKgWHCbPKYEMER3AQIOMLAXAwgm2BcDCICgRBIpIMCcf5QopsAMSyBxgQKl9VdIAwuUQEQRKRhI4SUgJiCDEkdcsEBpioA4QhJLkKBAKxN2dZeKTSCBAQOKGUaIkCCKYIQSKaDiGP9geH31AAMJxMDEET8qdgiRDJBgBBInAMnKY06CGMMSRVwAGCMMhEBjCQrkWIpeJnaVwAEwOGEEBQos0ECCfC6wAIILKCACeCf4yeehiCaq6KJ88vWnAxF4eBWQHbQQxJY0zDBDDZlq6qmmNIQqAw9IHMHDbJ+mquqqrLb6aQ0wfPBAm1lBsEAEDEwgQxFJMJEEEkosIeywxBa7xINJBGvsssw26yyxwSJRQwUG/uWmU3UlIIENwh4RxBBDCCHuuOSWa+656Kar7rpBKIFEEztM4CVc1zL1wAIoKKEEECZcQEEFF1gg8MAEF2zwwQgnrLDCF4zgHRMvMLAAZEJGZWv/BD7s+8FoeVq75McghyzyyCSD7AACHAzhhA8V3PgXVU9OkF8O1RK2iYd3CSJpJU9CBgMTRXCg5187N9VABUkkMUMCE6BWScVLHnhgvZE0UBgDLSRxRAdAEj3VkxUgscTSpVnJc5MOQBBBhwVOfJoLSxghtCBUKwW22GTTXfQjE8rFZM6VwS2323UndffYCZS9NyN+5exnoHkGOvTbcc893tcMhI244jw7sEBoDmQAggosqHACCBhI4ACtuQlueeFIHZ73XosbMqGBHMDAg3XJPvgdEC50YCsDtTvSgOuEw5w53onrDclihU3cwQ1IMGF9EtRtCWwTceMAgsSXLfZy/9XI06285rOb3cgDEliNwAQxFMG9EUDQoAIIHHQAAgoz/HCE9UaggQXaFJgIVMx45bucVGTXPNpJIgEb6EGvjpCDE0BAAQjaE2gYIAES4OAIwfJBBzjkOcBB4niVSx7m0NdA9TliARwQgq+GcIIGSEhne7HaBW9EAiEkSwgfSIDVinelBMLuKAzk3CMYQIEg9KgHGUDAYgrxFwZESoMW2EGGgsABpkFGEigcnPlWyDwlKqIwBXqADoqghBxsAEh7uk1kJEMxJuIgOj54kgsRmMIxLnB5m3MeI6pogv/5wEUPvIAEkbAC24DRiOcroyARYbVbJcACQ1BCEUBwwwdyoP8ISCgCBtxGvj4qMCpJnOQhSCOIBawgWC3gD4waYRsQvUBYMCDQEQcRxtdFMpAOTMRdIJCrjA1BAxPb5ReBxIALZHIIFFAm3SBJRmDucUgSIB4DPHCEJtAALqR8xAMM+AAEzMBdIkgAEYdEzT+y0IyI4JACUqAvE0zMhJJQAAmA5QLW8a2dqARk+mrHTAwdIQMKWJslGKABIijBBqekJUChkspgUvJeDujBEoggL79Y4gE/UMIPIhBOiZpSmkOp6DWH5DkI/IAJPKgiBNaJiAXwYAlCkEBJGTfRp6h0cdaSwEt1QDRb0dQQC9hBE4Rgq1KKMaI+FWgLa3fBB/BgqYz/oddHb+qD9jnVl9Uc6CIisJkaNEGUionTJBhggSAsAQdoPGFPsSVVeCKVYisolQkSp9YHguBdMDigSZ+KUqH8tDGeC8ERllCDZB61EAxgQROUQAI9fVWF7pSkRQ+RVQVUAAhLGIIFHLlWC/SgCUFw0WN7idmAvlOVh3jRX2CgrxUAqUOFe5IVE4CCxdJgcnI96S/Fmghi3gWGDlUOCfnS10O8zLNAUIJ61PlI4YZ1qooITF0Y4AImIEEHE3hRm/BENdJMLAe9moHLpMlaP7pWsyvlpWSISYEeICcGLS1QpLLrJxgsFgguKmx7oUrX127WEOMkZtoWoAEjLCEJMpiA/wKmuIhbvQA8RxDBrSo016YcVhEGgpECRmCEZPFAaM2FTBUbgIEa+EoJJXgRaeL6T+tm1po0NQwCSDCEYxFhBhpY3eMQlKcGZEAGQygC0Cqr1bh02F51he0gIVAADfCARkggwg1OhwH4WKADJriBgx+0Aw88yhIDLmxQPry+OM4qAirgTY+W4K0fACEIwErCZIEngQk/1nZPXgqbHVFFQXhWBTk4khKs9yAA7mAFo2QmJtI8XOw+IpvVGsSEM1CCFczgBjaIAQtOoAEgyZiVKAq0UmxlASI0YQYG+PMgGJOY8ImGgK0UIi+vKGu7MOAF7hqhXqRCzAvIjwY3ki3xPv86R5ZSEYdPK4wLkhA0P/c6KAuwgL5mIEXUlOzbjUPbkMD97QKxgM5vDIyaf7InChBhCT2ggBUNFKm82Pve+M63vvfNb7zMVAETQO8QfkS0dftkmDPoUQweEJoDJODhEI+4xCdOcQRY3OIJ8AzFN85xiCeUBXq2Aa7+QmOKrs4DbFRCD1rgghe04OUwj7nMZ05zmbvg5jivuc537gIdLLYIH7Cs1WAGFwaAgAhMGNZxnsX0pjv96b56MJsMLhTL+IkDNXDoEZDA9a57/etgD7vYx072soPHCDXYQOKuPRQE1cVQFtCAwC5A97rb/e54z7ve9873vtMdo166irXSFin/0OhpT4xKvOIXz3g+baZAadPRFync78pb/vL8nunQLesVw8xUGH6j+olGT/rSm54obD+9OAnxsqx6jF5RU/0J5ZIYnWmXdviUPSQ+bxi9DPtDYMJh6nEDuL/Z/va1tMti1jb8zk8GNYEPPC8NHWJcIcCyDE9AzUCUNjhiBgGZCU180VLFB8xgBPxhwAhquAAR2IAGODCBee8VgRi4xwaVrQsFcDACxeBLBuNhRSyAAzeQAyNwIyYAfzGQKfIyF7RHAeHSNOYUAwWQAj/wARKgAT0QMXeROTyAARywBDzQFyawBC9gKxXAA0BgZnvyfhkgASIABCmgADwgAxnwAR/g/wEUwHltsV0QgAM+QAOrAwMpkIIaYAAQwDRAkAGJswAToAMXoAE7wAMboAASEAM74AIOwFsucAIuECgmQDP3ggAeUEM1EAICoAAHYACo5hYydQMgoAMoMAAwwAIgsAMN0D6MIQMnkAAGlEVxlwN1aAAbMAMuAANpUwMcAAFQuAAzgAI38kV8YQMz0AEiQAK1kXtpMRkSwAMZkAFAMAF1GAI40CaKcQCGyCHZxgMWsAE4wAHftAIpoAIxAEFAgDo8UAIJYAMz2AAnkAM48AIPYAM78AIwEAMmEAFfRH6+JwgRoAMcYAALyIcesANoxBc3IALaNAEfyAHWeAMfQAMUkP8CNVAALfADM4CFNrAALSdFEkABITBS3iMhgEI8/WFvZKFNt/IDtQEBNGAEKhABNGACeZIAJxBv6nQrPtCKPeCLNnADCkCLnbgBehIBO9ABG7ADVcgAChACPTABNSACbdImyxZ8Y/Exf+GEOlBqZHgEK1AAGHAqKAADOjACUnRBFqADFcABPOAAFKAEL0AALNACJ4ADErOFMvACCIACPPACKjAqXWIDOaAChwgDGDBhaXFvhKCDN8JQGQABCCABI9ACJjBAC7YXHRABEvCVDPABFXAAGbABGHABhbEYGTgr43gCJWABQMIBJjACIhACIpBN4xEyTmJ7xqWMecIYYLn/Jw7QZwcwGgZkG59TIMQDlreCGSASARHghxhEGJ+zJ31RkA2AQS4EOOLGFUP0fEPHXH9TLU+yGBPDTC/zfAUCTkPUfQZSZGcGeA1ATjlUZDkkfVZBGo4CijHAFxMgBF0iCAayACEgBBRpkTugjIkBAj8QRSIQBD9CRVaDAzxAATnUACTQHUIwBERAA8vBABnwAzYiPveSAkVwnkYwIkQQAqIHFJKRVi6gBELAATfCA0QQmznEA0PQmeWpBASZkiegBB6AACqwBBmgVi+yACQAHmxCTJGVBC+wAi3AAkBQJgkQAkWAAva4J47YBC1AOirwcmaSFV6znCqgAy8QGmoS/wLVkgAZoAQqgAARIIgysANpY0OFFI350p2spwA3QAMw4AP0wl1DIGGi8ZMwwGBGQJBScx5FcDKbAX5aYZxPYgJF0AAtIASdCYHryBgKAAMi2gFDAAIbEDT+hwJF0AEHgB8TSghkpQGbpAFH4AETtgBlSgHadytJsAIHwAFXam1kFQNEQFIZ5JhYoT4+IAPZRgT2tAAqUCaB0kQ3gCCOaiBBEAP8sQAmQAQbUAD4cQFqhRk1AAR3wQM3UKEtYAQp4AEjUAI5QAMTcAAodwJ+NlNRcgQm4AEgEAIhsB75CRTg1AH/iQAHQAPWuAAVcAQskHEzUgIGcAFEgAIPdwLQtP86JYAEG2AAKeAjlwkZADcELGAACGACQjBaDLACSUAERLAlSkADnckB3SpEdxEBCDAmQlAdlxKKKRZQmCEDTHADMjADQIAEGsAhNPADq7MDQSCuUIADNjAqTEACpblPHWAAhaQBomk1CUAC8LYaN8AEfegAKzAEIlABFGABItICCrABRrAftocAMvCfEnABGQC0BxsVRddqQXgDOkAD6aknHZAEGYA0PfqEPwaMNiAEOpBNJ1CnBnACEupRfNEDQVCAOWADQTBSrlQEEyAhfnIDRQABYbNXBRclQ9BnEnN4WaEYCZBXFRCALnAEFnAAD9ADojYEG8JN2mghI7AEH3D/p3V6ACmQBL1qHgiwAUhQAjrDABtAWQjQAkUgAQQiRDeQR35aAgdQt5gRA0FATNrHTMt6cMQTBDbAIRq0AUfQAkJUAuh5A3yhtFW1OhEgBDlwskhgpyagBChgrB8AAhYgA0Swg8YZAT+wAxBKrB7gAR8wJiuQACgXA8cKAiCgAeaUYfozAtX7RlhxLx+ABIBqPg2QAzvQPhPgA0gQAnApBC8wMXGVAlHqMAA6AllGBElWBC/QAzLAOkCSAkZwAeeaZNMRBC0wKykjBERwnkkQA5GVHPU5sEgwAwRGtBSAARjFSxOQOhODAUHmhBigjHaRNhsgARGQAQY0ARowwxmA/wECgwHyRjwoKgFdFgEY8Ik1jCsTMwEWgAFG/MMV0AAy/MMZEHcaIAHN9xN6BCIe4ifmkyeQYcWEoKatFCSb8RhPmjNykUF/Uo9pk1WHQnIcCSKmuVNTwReRsmxbHD62F3nP9iIq7JiXwXqaB3m2520z1WzjZi20ZhlakRhDh2Ca5zdX8piOeZQWZZt9nFXQxiSC9TL7ZTPjh0q451yQkVWb3BepSS+B8XmfRzeOMbTj5hjO2bpVR0mSSDV9k10KVC+ClV1RrHu6vMu83Mu+/MvAHMzCPMzEXMzGfMzInMzKvMzM3MzO/MzQHM3SPM3UXM3WfM3YnM3avM3c3M3e/FjN4BzO4jzO5FzO5nzO6JzO6rzO7NzO7vzO8BzP8jzP9FzP9nzP+JzP+rzP/NzP/vzPAB3QAj3QBF3QBn3QCJ3QCr3QDN3QDv3QEB3REj3RFF3RFn3RhBAIADs=">
                                                </div>
                                                <label class="btn btn-info btn-upload">
                                                    Upload<input name="image" type="file" class="uploadFile img" value="Upload Photo" style="width: 0px;height: 0px;overflow: hidden;">
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label><?= $lang["game_vendor_entrance_type"] ?></label>
                                            <select id="item_type" class="selectize" name="item_type" data-default="1">
                                                <option value="1" selected>
                                                    <?= $lang["game_vendor_entrance_type_1"] ?>
                                                </option>
                                                <option value="2">
                                                    <?= $lang["game_vendor_entrance_type_2"] ?>
                                                </option>
                                                <option value="3">
                                                    <?= $lang["game_vendor_entrance_type_3"] ?>
                                                </option>
                                                <option value="4">
                                                    <?= $lang["game_vendor_entrance_type_4"] ?>
                                                </option>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label><?= $lang["name"] ?></label>
                                            <input type="text" name="name" placeholder="" class="form-control">
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label><?= $lang["vendor"] ?></label>
                                            <select id="vendor_id" name="vendor_id" class="selectize">
                                                <option value=""></option>
                                            <?php foreach($vendors as $vendor) { ?>
                                                <option value="<?=$vendor['id']?>">
                                                    <?=$vendor['name']?>
                                                </option>
                                            <?php } ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label><?= $lang["game_type"] ?></label>
                                            <select id="type_id" name="type_id" class="selectize">
                                                <option value=""></option>
                                                <?php foreach($game_types as $game_type) { ?>
                                                    <option value="<?=$game_type['id']?>">
                                                        <?=$game_type['name']?>
                                                    </option>
                                                <?php } ?>
                                            </select>
                                        </div>
                                    </div>

                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label><?= $lang["game"] ?></label>
                                            <select id="game_id" name="game_id" class="selectize">
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label><?= $lang["game_vendor_entrance_type_4"] ?></label>
                                            <select id="path" name="path" class="selectize">
                                                <option value=""></option>
                                                <?php
                                                foreach ($internal_types as $row) {
                                                    ?>
                                                    <option value="<?= $row['parameter'] ?>">
                                                        <?= $lang['advertise_internal_'. strtolower($row['parameter'])] ?>
                                                    </option>
                                                    <?php
                                                }
                                                ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label><?= $lang['enable_status'] ?></label>
                                            <select id="status" class="selectize" name="status" data-default="1">
                                                <option value="0">
                                                    <?= $lang["disable"] ?>
                                                </option>
                                                <option value="1" selected>
                                                    <?= $lang["enable"] ?>
                                                </option>
                                                <option value="2">
                                                    <?= $lang["coming_soon"] ?>
                                                </option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label><?= $lang["is_new"] ?></label>
                                            <div class="toggle-checkbox toggle-sm">
                                                <input type="checkbox" name="is_new" id="is_new">
                                                <label for="is_new"></label>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label><?= $lang["is_hot"] ?></label>
                                            <div class="toggle-checkbox toggle-sm">
                                                <input type="checkbox" name="is_hot" id="is_hot">
                                                <label for="is_hot"></label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">

                                </div>
                                <div class="row">
                                    <div class="col-12">
                                        <div class="text-right mrg-top-5">
                                            <button type="submit" class="btn btn-primary"><?= $lang["btn_update"] ?></button>
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
<!-- Content Wrapper END -->
<?php
require_once '../_bottom.php';
?>


<!-- Page Container END -->
<?php
require_once '../_footer.php';
?>

<!-- page plugins js -->
<script src="/assets/vendors/dragula-master/dist/dragula.js"></script>
<script>
(function ($) {
'use strict';
    var draggableState = false;

    var enableInputByItemType = function() {
        $('select.selectize:not([name=\'item_type\'])').not('[name=\'status\']').each(function(i, el){
            $(el).data('selectize').disable();
        })
        var item_type = $('#editor [name=item_type]').data('selectize').getValue();
        switch(item_type) {
            case "1":
                $('#editor [name=vendor_id]').data('selectize').enable();
                break;
            case "2":
                $('#editor [name=vendor_id]').data('selectize').enable();
                $('#editor [name=game_id]').data('selectize').enable();
                break;
            case "3":
                $('#editor [name=vendor_id]').data('selectize').enable();
                $('#editor [name=type_id]').data('selectize').enable();
                break;
            case "4":
                $('#editor [name=path]').data('selectize').enable();
                break;
        }
    }
    var editorReset = function(){
        $(".draggable-item").removeClass('current_edit');
        $('#editor select.selectize').each(function(i, el){
            $(el).data('selectize').setValue($(el).data('default'));
        });
        $('#editor input[type=checkbox]').each(function(i, el){
            $(el).prop('checked', false);
        });
        $('#editor img').each(function(i, el){
            $(el).attr('src', $(el).data('default-src'));
        });
        $('#editor input').val('').attr('placeholder', '');
        $('#editor [name=action]').val('save_item');
    }
    var refershBoard = function() {
        if(draggableState) {
            $(".draggable-item").removeClass('current_edit');
            $(".draggable-item").unbind('click');
            $(".draggable-item .delete-btn").unbind('click');
        }
        else {
            $(".draggable-item .delete-btn").bind('click', function () {
                var id = $(this).data('id');
                var item_text = $(this).data('name') + "(" + $(this).data('id') + ")";
                var text = "You will remove the item " + item_text + "!";
                Swal.fire({
                    title: 'Are you sure?',
                    text: text,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, remove it!',
                    cancelButtonText: 'No, keep it'
                }).then((result) => {
                    if (result.value) {
                        $.blockUI();
                        $.post(
                            "<?= BASE_URL ?>agent/vendor_customized_items_setting_ajax.php",
                            {
                                action: 'remove_item',
                                id: id
                            },
                            function (data) {
                                $.unblockUI();
                                if (data.code == 1000) {
                                    Swal.fire(
                                        'Deleted!',
                                        item_text + ' has been deleted.',
                                        'success'
                                    ).then((result) => {
                                        location.reload();
                                    });
                                } else {
                                    Swal.fire(
                                        'Cancelled',
                                        data.message?data.message:'unknown error',
                                        'error'
                                    )
                                }
                            },
                            'json'
                        ).fail(function() {
                            $.unblockUI();
                            Swal.fire(
                                'Error',
                                'Unknown error',
                                'error'
                            )
                        });

                        // For more information about handling dismissals please visit
                        // https://example.invalid/#handling-dismissals
                    } else if (result.dismiss === Swal.DismissReason.cancel) {
                        Swal.fire(
                            'Cancelled',
                            item_text + ' is safe :)',
                            'error'
                        )
                    }
                });
            });
            $(".draggable-item").bind('click', function () {
                if($(this).hasClass('current_edit')) {
                    return;
                }
                $.blockUI();
                $(".draggable-item").removeClass('current_edit');
                $(this).addClass('current_edit');
                var id = $(this).data('id');
                $.post(
                    "<?= BASE_URL ?>agent/vendor_customized_items_setting_ajax.php",
                    {
                        action: 'get_item',
                        id: id
                    },
                    function (data) {
                        $.unblockUI();
                        if (data.code == 1000) {
                            var item = data.data.item;
                            var vendor = data.data.vendor?data.data.vendor:item;
                            $('#editor [name=id]').val(item.id);
                            $('#editor .imagePreview').attr('src', item.image?item.image:(vendor.image?vendor.image:$('#editor .imagePreview').data('default-src')));
                            $('#editor [name=item_type]').data('selectize').setValue(item.item_type);
                            $('#editor [name=name]').val(item.name?item.name:"").attr('placeholder', vendor.name);
                            $('#editor [name=vendor_id]').data('selectize').setValue(item.vendor_id?item.vendor_id:"");
                            $('#editor [name=game_id]').data('default', item.game_id?item.game_id:"").data('selectize').setValue(item.game_id?item.game_id:"");
                            $('#editor [name=type_id]').data('selectize').setValue(item.type_id?item.type_id:"");
                            $('#editor [name=path]').data('selectize').setValue(item.path?item.path:"");
                            $('#editor [name=is_new]').prop('checked', item.is_new?true:false);
                            $('#editor [name=is_hot]').prop('checked', item.is_hot?true:false);
                            $('#editor #status').data('selectize').setValue(item.status);
                            $('[enable-for]').each(function(i, el){
                                $('#'+$(this).attr('enable-for')).prop('disabled', !$(el).prop('checked'));
                            });
                            enableInputByItemType();
                        } else {
                            alert(data.message);
                            $(".draggable-item").removeClass('current_edit');
                        }
                    },
                    'json'
                );
            });
        }
    }
    $('.selectize').selectize({
        create: false,
        dropdownParent: 'body'
    });
    dragula([document.getElementById('board')], {
        moves: function (el, source, handle, sibling) {
            return (draggableState && $(el).hasClass('draggable-item')); // elements are always draggable by default
        }
    });
    $('#editor [name=item_type]').change(enableInputByItemType);
    $('#editor [name=vendor_id]').change(function(){
        $.blockUI();
        var vendor_id = $(this).data('selectize').getValue();
        var item = $(this).data('selectize').getItem($(this).data('selectize').getValue());
        var text = item.length?item[0].innerHTML.trim():'';
        $('#editor [name=name]').attr('placeholder', text)
        $('#editor [name=game_id]').data('selectize').clearOptions();
        $.post(
            "<?= BASE_URL ?>agent/vendor_customized_items_setting_ajax.php",
            {
                action: 'get_games',
                vendor_id: vendor_id
            },
            function (data) {
                $.unblockUI();
                if (data.code == 1000) {
                    for(var i=0;i<data.games.length;i++) {
                        $('#editor [name=game_id]').data('selectize').addOption({value:data.games[i].id, text: data.games[i].name});
                    }
                    $('#editor [name=game_id]').data('selectize').setValue($('#editor [name=game_id]').data('default'));
                }
            },
            'json'
        );
    });
    $('.uploadFile').change(function(){
        var uploadFile = $(this);
        var files = !!this.files ? this.files : [];
        if (!files.length || !window.FileReader) return; // no file selected, or no FileReader support

        if (/^image/.test( files[0].type)){ // only image file
            var reader = new FileReader(); // instance of the FileReader
            reader.readAsDataURL(files[0]); // read the local file

            reader.onloadend = function(){ // set image data as background of div
                //alert(uploadFile.closest(".upimage").find('.imagePreview').length);
                uploadFile.closest(".imageUploadWrapper").find('.imagePreview').attr("src", this.result);
                $("#editor [name=vendor_image]").val(this.result);
            };
        }
    });
    $('#draggable-cancel').click(function(){
       location.reload();
    });
    $('#draggable-btn').click(function(){
        $(this).toggleClass('d-none');
        $('#draggable-save').toggleClass('d-none');
        $('#draggable-cancel').toggleClass('d-none');
        draggableState = true;
        refershBoard();
    });
    $('#draggable-save').click(function(){
        $.blockUI();
        var btn_save = this;
        var ids_array = [];
        $(".draggable-item").each(function(i, el){
            ids_array.push($(el).data('id'));
        });
        var ids = ids_array.join(",");
        $.post(
            "<?= BASE_URL ?>agent/vendor_customized_items_setting_ajax.php",
            {
                action: 'update_sorts',
                group_id: $("#group_id").val(),
                ids: ids
            },
            function (data) {
                $.unblockUI();
                if (data.code == 1000) {
                    $(btn_save).toggleClass('d-none');
                    $('#draggable-btn').toggleClass('d-none');
                    $('#draggable-cancel').toggleClass('d-none');
                    draggableState = false;
                    refershBoard();
                } else {
                    alert(data.message);
                }
            },
            'json'
        );
    });
    $('#editor').submit(function(){
        $.blockUI();
        var formData = $(this).serializeArray();

        formData.push({
           name: 'group_id',
           value: $("#group_id").val(),
        });
        $.post(
            "<?= BASE_URL ?>agent/vendor_customized_items_setting_ajax.php",
            formData,
            function (data) {
                $.unblockUI();
                if (data.code == 1000) {
                    location.reload();
                } else if (data.code == 23000) {
                    Swal.fire(
                        'Error',
                        '<?= $lang['item_exists'] ?>',
                        'error'
                    )
                }
                else {
                    Swal.fire(
                        'Error',
                        data.message?data.message:'unknown error',
                        'error'
                    )
                }
            },
            'json'
        ).fail(function() {
            $.unblockUI();
            Swal.fire(
                'Cancelled',
                'Unknown error',
                'error'
            )
        });

        return false;
    });
    $('#add_item').click(editorReset);
    refershBoard();
    enableInputByItemType();

})(jQuery);
</script>