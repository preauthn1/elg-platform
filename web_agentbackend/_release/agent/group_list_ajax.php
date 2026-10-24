<?php
require_once '../_init.php';
require_once '../class/CreatePage.php';$data = array();

$queries = array();
parse_str($_SERVER['QUERY_STRING'], $queries);

$result = curlPost('api/agent/group/list', $_GET);
 if ($result['code'] != 1000) {
     echo display_error_msg_html($api_response["message"]);
     exit;
 }

$record = $result;
$record['pagination'] = (array) $result['pagination'];

$page = new CreatePage();

?>
<div class="col-md-12">
    <div class="card">
        <div class="card-block">
            <div class="table-overflow">
                <div id="dt-opt_wrapper" class="dataTables_wrapper no-footer">
                    <table class="table table-lg table-hover no-footer">
                        <thead>
                            <tr>
                                <th><?= $lang['id'] ?></th>
                                <th><?= $lang['type'] ?></th>
                                <th><?= $lang['organization'] . $lang['name'] ?></th>
                                <th><?= $lang['name'] ?></th>
                                <th><?= $lang['memo'] ?></th>
                                <th><?= $lang['totalPeople'] ?></th>
                                <th><?= $lang['time'] ?></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if (count($record['data']) > 0) {
                                $i = 0;
                                foreach ($record['data'] as $row) {
                                    $row = (array) $row;
                                    $i++;
                                    
                                    $typeName = $lang["agent_group_type_". $row["agent_group_type"]]; ?>
                            <tr>
                                <td>
                                    <div class="mrg-top-15">
                                        <?= $row["id"]?>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15">
                                        <span class="<?= "agent_group_type_".$row["agent_group_type"] ?>">
                                            <?= $typeName ?>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15">
                                        <span>
                                            <?= $row["organization_name"]?>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15">

                                        <a href="javascript:void(0)" class="link-text"
                                            onclick="showEditModal('<?= $row['id'] ?>' , '<?= $row['name'] ?>' , '<?= $row['memo'] ?>' , '<?= $typeName ?>')">
                                            <?= $row["name"]?>
                                        </a>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15">
                                        <span>
                                            <?= $row["memo"]?>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15 ">
                                        <span class='link-text'
                                            onclick="showGroupAgents('<?= $row["agent_group_type"] ?>' , '<?= $row["id"] ?>')">
                                            <?= $row["total_agent_count"]?>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <div class="mrg-top-15">
                                        <span><?=  date('Y-m-d H:i:s', strtotime($row['created_at']. '+8hour')) ?></span>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($row['agent_group_type'] == 1 || $row['agent_group_type'] == 3) { ?>
                                    <div class="dropdown inline-block">
                                        <button class="btn btn-default btn-sm dropdown-toggle" type="button"
                                            data-toggle="dropdown">
                                            <i class="ti-angle-down font-size-9"></i>
                                        </button>
                                        <ul class="dropdown-menu">
                                            <?php if ($row['agent_group_type'] == 3) { ?>
                                            <li>
                                                <a href="/agent/agent_sms_setting.php?group_id=<?= $row["id"] ?>">
                                                    <?= $lang['menu_agent_advance_setting'] ?>
                                                </a>
                                            </li>
                                            <?php } else { ?>
                                            <li>
                                                <a
                                                    href="/agent/agent_customized_setting.php?group_id=<?= $row["id"] ?>">
                                                    <?= $lang['menu_agent_advance_setting'] ?>
                                                </a>
                                            </li>
                                            <li onclick="copyAndCreateNew('<?= $row["id"] ?>')">
                                                <a href="javascript:void(0)">
                                                    <?= $lang['duplicate'] ?>
                                                </a>
                                            </li>
                                            <?php } ?>
                                        </ul>
                                    </div>

                                    <?php } else if ($row['agent_group_type'] == 2) { ?>
                                    <div class="dropdown inline-block">
                                        <button class="btn btn-default btn-sm dropdown-toggle" type="button"
                                            data-toggle="dropdown">
                                            <i class="ti-angle-down font-size-9"></i>
                                        </button>

                                        <ul class="dropdown-menu">

                                            <li>
                                                <a
                                                    href="/agent/agent_online_deposit_setting.php?group_id=<?= $row["id"] ?>">
                                                    <?= $lang['online_deposit_setting'] ?>
                                                </a>
                                            </li>

                                        </ul>
                                    </div>
                                    <?php }  else if ($row['agent_group_type'] == 5) {?>
                                        <div class="dropdown inline-block">
                                            <button class="btn btn-default btn-sm dropdown-toggle" type="button"
                                                    data-toggle="dropdown">
                                                <i class="ti-angle-down font-size-9"></i>
                                            </button>

                                            <ul class="dropdown-menu">

                                                <li>
                                                    <a
                                                            href="/agent/vendor_customized_items_setting.php?group_id=<?= $row["id"] ?>">
                                                        <?= $lang['menu_agent_advance_setting'] ?>
                                                    </a>
                                                </li>
                                                <li onclick="copyAndCreateNew('<?= $row["id"] ?>')">
                                                    <a href="javascript:void(0)">
                                                        <?= $lang['duplicate'] ?>
                                                    </a>
                                                </li>
                                            </ul>
                                        </div>
                                    <?php }?>
                                </td>
                            </tr>
                            <?php
                                }
                            } else { ?>
                            <tr>
                                <td colspan="12" align='center'>
                                    <div class="mrg-top-15">
                                        <span class="text-info">
                                            <b>
                                                <?= $lang['no_record'] ?>
                                            </b>
                                        </span>
                                    </div>
                                </td>
                            </tr>
                            <?php }
                            ?>
                        </tbody>
                    </table>
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
                <?php
                } ?>
            </div>
        </div>
    </div>
</div>
<style>
span.red {
    color: #dd5050;
}
</style>