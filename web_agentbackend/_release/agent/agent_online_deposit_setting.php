<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';

//確認權限
// if (!isset($check_auth['agent/edit']) && $_GET['id'] != $_SESSION['id']) {
//     echo display_error_msg_html($lang['nopermission']);
//     exit;
// }

//新增頁，部分設置要從當前代理帶預設值

$post_data = array();
$post_data['group_id'] = $_GET['group_id'] ;

$api_response = curlPost('api/agent/onlineDeposit/cashFlowList', $post_data);

$code = $api_response["code"];

if ($code != 1000) {
    echo display_error_msg_html($api_response["message"]);
    exit;
}
$agent_group = $api_response["data"]["agent_group"];
$cash_flow_list = $api_response["data"]["cash_flow_list"];
$agent_cash_flow_list = $api_response["data"]["agent_cash_flow_list"];

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
                    <?= $lang['menu_agent_group'] ?> >
                    <?= $lang['online_deposit_setting'] ?>
                </h4>
            </div>

            <div>
                <div class="agent_name_row">
                    <div class="row">
                        <div class="col-md-6">
                            <input type="hidden" id="group_id" name="group_id" value="<?= $agent_group["id"] ?>">
                            <span>
                                <b>
                                    <?= $lang["name"] ?>
                                </b>
                            </span>：
                            <span>
                                <?= $agent_group["name"]?>
                            </span>
                            <span>
                                (<?= $agent_group["id"]?>)
                            </span>
                            <a href="/agent/group_list.php" style="float:right" class="linkText">
                                <?= $lang["btn_back"]?>
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
                <a href="#" data-toggle="modal" data-target="#modal-agent-cash-flow-form" class="btn btn-inverse btn-success createAgentCashFlowBtn"><?= $lang['add'] ?></a>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="table-overflow">
                                <table id="dt-opt" class="table table-lg table-hover">
                                    <thead>
                                        <tr>
                                            <th><?=$lang['deposit_vendor_name']?></th>
                                            <th><?=$lang['type']?></th>
                                            <th><?=$lang['deposit_rate']?></th>
                                            <th class="sorting_desc"><?=$lang['sorts']?></th>
                                            <th><?=$lang['status']?></th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php
                                    foreach ($agent_cash_flow_list as $row) {
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="list-info mrg-top-10">
                                                    <div class="info">
                                                        <span class="title">
                                                            <?=$row['name']?>
                                                        </span>
                                                        <span class="sub-title">
                                                            <?= $lang['id'] ?> <?=$row['id']?>
                                                        </span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <?= $lang['cash_flow_' . $row['type']] ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <?= $row['rate'] ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <?=$row['sorts']?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="toggle-checkbox checkbox-inline toggle-sm mrg-top-15">
                                                    <input type="checkbox" name="toggle_status" id="toggle_<?=$row['id']?>" <?=($row['status'] == 1)?"checked":""?> data-row-id="<?=$row['id']?>">
                                                    <label for="toggle_<?=$row['id']?>"></label>
                                                </div>
                                            </td>

                                            <td>
                                                <div class="mrg-top-10 text-center">
                                                    <div class="dropdown inline-block">
                                                        <button class="btn btn-default btn-sm dropdown-toggle" type="button" data-toggle="dropdown">
                                                            <i class="ti-angle-down font-size-9"></i>
                                                        </button>
                                                        <ul class="dropdown-menu">
                                                            <li>
                                                                <a class="editAgentCashFlowBtn" data-target="#modal-agent-cash-flow-form" data-row-id="<?=$row['id']?>" href="#"><?=$lang['edit']?></a>
                                                            </li>
                                                            <?php if($row['cash_flow_id'] != 5 && $row['cash_flow_id'] != 6) { ?>
                                                            <li>
                                                                <a class="editOrderTypeBtn" data-target="#modal-order-type-list" data-row-id="<?=$row['id']?>" href="#"><?=$lang['deposit_order_type_setting']?></a>
                                                            </li>
                                                            <?php } ?>
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
    <div class="modal fade" id="modal-agent-cash-flow-form">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-body">
                    <div>
                        <div class="row">
                            <div class="card col-md-12">
                                <div class="card-heading border bottom">
                                    <h4 class="card-title"><?=$lang['edit'] . " " . $lang['online_deposit_setting']?></h4>
                                </div>
                                <div class="card-block">
                                    <div class="mrg-top-20">
                                        <div class="row">
                                            <div class="col-md-12 ml-auto mr-auto">
                                                <form id="editAgentCashFlowForm">
                                                    <input type="hidden" name="id" />
                                                    <div class="row">
                                                        <div class="col-md-12">
                                                            <div class="form-group">
                                                                <label><?=$lang['deposit_vendor_name']?></label>
                                                                <select name="cash_flow_id">
                                                                    <?php
                                                                    foreach ($cash_flow_list as $row) {
                                                                    ?>
                                                                    <option value="<?=$row['id']?>"><?=$row['name']?></option>
                                                                    <?php } ?>
                                                                </select>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="row">
                                                        <div class="col-md-6">
                                                            <div class="form-group">
                                                                <label><?=$lang['deposit_rate']?></label>
                                                                <input type="text" class="form-control" name="rate" required="" aria-required="true">
                                                            </div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <div class="form-group">
                                                                <label><?=$lang['sorts']?></label>
                                                                <input type="text" class="form-control" name="sorts" required="" aria-required="true">
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="row">
                                                        <div class="col-md-12">
                                                            <div class="form-group">
                                                                <label><?=$lang['deposit_vendor_info']?></label>
                                                                <textarea class="form-control" rows="3" name="info"></textarea>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="row">
                                                        <div class="col-md-12 col-xs-6">
                                                            <div class="text-right mrg-top-5">
                                                                <button type="submit" class="btn btn-primary"><?=$lang['btn_update']?></button>
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
        </div>
    </div>

    <div class="modal fade modal-fs" id="modal-order-type-list">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-body">
                    <div>
                        <div class="row">
                            <a href="#" data-toggle="modal" data-target="#modal-agent-cash-flow-order-type-form" class="btn btn-inverse btn-success createAgentCashFlowOrderTypeBtn"><?= $lang['add'] ?></a>
                        </div>
                        <div class="row">
                            <div class="card col-md-12">
                                <div class="card-heading border bottom">
                                    <h4 class="card-title"><?=$lang['deposit_order_type_setting']?></h4>
                                </div>
                                <div class="card-block">
                                    <div class="table-overflow">
                                        <table id="dt-order-type-list" class="table table-lg table-hover" style="width:100%">
                                            <thead>

                                            </thead>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <a class="modal-close" href="" data-dismiss="modal">
                        <i class="ti-close"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modal-agent-cash-flow-order-type-form">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-body">
                    <div>
                        <div class="row">
                            <div class="card col-md-12">
                                <div class="card-heading border bottom">
                                    <h4 class="card-title"><?=$lang['edit'] . " " . $lang['deposit_order_type_setting']?></h4>
                                </div>
                                <div class="card-block">
                                    <div class="mrg-top-20">
                                        <div class="row">
                                            <div class="col-md-12 ml-auto mr-auto">
                                                <form id="editAgentCashFlowOrderTypeForm">
                                                    <input type="hidden" name="id" />
                                                    <input type="hidden" name="agent_cash_flow_id" />
                                                    <div class="row">
                                                        <div class="col-md-12">
                                                            <div class="form-group">
                                                                <label><?=$lang['deposit_order_type_name']?></label>
                                                                <select name="order_type_id">

                                                                </select>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="row">
                                                        <div class="col-md-4">
                                                            <div class="form-group">
                                                                <label><?=$lang['deposit_order_type_min_amount']?></label>
                                                                <input type="text" class="form-control" name="min_amount" required="" aria-required="true">
                                                            </div>
                                                        </div>
                                                        <div class="col-md-4">
                                                            <div class="form-group">
                                                                <label><?=$lang['deposit_order_type_max_amount']?></label>
                                                                <input type="text" class="form-control" name="max_amount" required="" aria-required="true">
                                                            </div>
                                                        </div>
                                                        <div class="col-md-4">
                                                            <div class="form-group">
                                                                <label><?=$lang['sorts']?></label>
                                                                <input type="text" class="form-control" name="sorts" required="" aria-required="true">
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="row">
                                                        <div class="col-md-12">
                                                            <div class="form-group">
                                                                <label><?=$lang['deposit_order_type_available_amount']?></label>
                                                                <textarea class="form-control" rows="3" name="available_amount"></textarea>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="row">
                                                        <div class="col-md-12 col-xs-6">
                                                            <div class="text-right mrg-top-5">
                                                                <button type="submit" class="btn btn-primary"><?=$lang['btn_update']?></button>
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
    let agent_cash_flow_list = <?=json_encode($agent_cash_flow_list)?>;
    let order_type_list = {};
    let agent_cash_flow_order_type_list = {};
    function agentCashFlowModel(data) {
        if(data.cash_flow_id) {
            $('#editAgentCashFlowForm select[name=cash_flow_id]')[0].selectize.setValue(data.cash_flow_id);
            $('#editAgentCashFlowForm select[name=cash_flow_id]')[0].selectize.disable();
        } else {
            $('#editAgentCashFlowForm select[name=cash_flow_id]')[0].selectize.clear();
            $('#editAgentCashFlowForm select[name=cash_flow_id]')[0].selectize.enable();
        }
        $('#editAgentCashFlowForm input[name=id]').val(data.id);
        $('#editAgentCashFlowForm input[name=rate]').val(data.rate);
        $('#editAgentCashFlowForm input[name=sorts]').val(data.sorts);
        try {
            $('#editAgentCashFlowForm textarea[name=info]').val(JSON.stringify(JSON.parse(example.invalid), null, '\t'));
        } catch (e) {
            $('#editAgentCashFlowForm textarea[name=info]').val("");
        }
    }

    function agentCashFlowOrderTypeModel(data) {
        console.log(data);
        if(data.id) {
            $('#editAgentCashFlowOrderTypeForm select[name=order_type_id]')[0].selectize.setValue(data.order_type_id);
            $('#editAgentCashFlowOrderTypeForm select[name=order_type_id]')[0].selectize.disable();
        } else {
            $('#editAgentCashFlowOrderTypeForm select[name=order_type_id]')[0].selectize.clear();
            $('#editAgentCashFlowOrderTypeForm select[name=order_type_id]')[0].selectize.enable();
        }
        $('#editAgentCashFlowOrderTypeForm input[name=id]').val(data.id);
        $('#editAgentCashFlowOrderTypeForm input[name=min_amount]').val(data.min_amount);
        $('#editAgentCashFlowOrderTypeForm input[name=max_amount]').val(data.max_amount);
        $('#editAgentCashFlowOrderTypeForm input[name=sorts]').val(data.sorts);
        try {
            $('#editAgentCashFlowOrderTypeForm textarea[name=available_amount]').val(data.available_amount);
        } catch (e) {
            $('#editAgentCashFlowOrderTypeForm textarea[name=info]').val("");
        }
    }

    function loadAgentCashFlowOrderTypeListDatatable() {
        let table_id = "#dt-order-type-list";
        let tbl = null;
        if ($.fn.DataTable.isDataTable(table_id)) {
            $(table_id).DataTable().destroy();
        }
        tbl = $(table_id).dataTable({
            data: agent_cash_flow_order_type_list,
            columns: [{
                "data": "id",
                "title": "ID"
            }, {
                "data": "name",
                "title": "<?=$lang['deposit_order_type_name']?>"
            }, {
                "data": "min_amount",
                "title": "<?=$lang['deposit_order_type_min_amount']?>"
            }, {
                "data": "max_amount",
                "title": "<?=$lang['deposit_order_type_max_amount']?>"
            }, {
                "data": "available_amount",
                "title": "<?=$lang['deposit_order_type_available_amount']?>"
            }, {
                "data": "sorts",
                "title": "<?=$lang['sorts']?>"
            }, {
                "title": "<?=$lang['status']?>",
                "render": function(data, type, row, meta) {
                    let checked = (row['status'] == 1)?"checked":"";
                    let html = '<div class="toggle-checkbox checkbox-inline toggle-sm">' +
                        '<input type="checkbox" name="toggle_status" id="ot_toggle_' + row['id']+ '" data-row-id="' + row['id'] + '"' + checked + ' />' +
                        '<label for="ot_toggle_' + row['id']+ '"></label>' +
                        '</div>';
                    return html;
                }
            }, {
                "render": function(data, type, row, meta) {
                    return '<a href="#" class="btn btn-info btn-sm editAgentCashFlowOrderTypeBtn" data-target="#modal-agent-cash-flow-order-type-form" data-row-id="' + row['id']+ '"><?=$lang['edit']?></a>'
                        + '<a href="#" class="btn btn-danger btn-sm deleteAgentCashFlowOrderTypeBtn" data-row-id="' + row['id']+ '"><?=$lang['delete']?></a>';
                }
            }]
        });
        tbl.on( 'change', 'input[name=toggle_status]', function () {
            let this_obj = this;
            let id = $(this).attr('data-row-id');
            let status = $(this).prop('checked')?1:0;
            $.ajax({
                cache: false,
                dataType: 'json',
                data: {
                    action: 'agentCashFlowOrderTypeToggleStatus',
                    id: id,
                    status: status
                },
                url: '/ajax.php',
                type: 'POST',
                success: function(data) {
                    if (data == null || data.code != 1000) {
                        alert(data.message);
                    } else {
                        successAlert();
                    }
                    $(this_obj).prop('checked', data.data.status == 1);
                },
                error: function(err) {

                    alert('error');

                }
            });
        });

        //編輯事件
        tbl.on( 'click', '.editAgentCashFlowOrderTypeBtn', function () {
            console.log(order_type_list);
            let this_obj = this;
            let id = $(this).attr('data-row-id');
            let agentCashFlowOrderType = agent_cash_flow_order_type_list.filter(function(item){
                return item.id == id;
            }).shift()
            agentCashFlowOrderTypeModel(agentCashFlowOrderType);
            //agentCashFlowModel(agentCashFlow);
            $($(this).attr('data-target')).modal('show');
        });

        //刪除事件
        tbl.on( 'click', '.deleteAgentCashFlowOrderTypeBtn', function () {
            if(confirm('<?=$lang['deposit_order_type_delete_message']?> ' + 'ID : ' + $(this).attr('data-row-id'))) {
                let this_obj = this;
                let id = $(this).attr('data-row-id');
                $.ajax({
                    cache: false,
                    dataType: 'json',
                    data: {
                        action: 'agentCashFlowOrderTypeDelete',
                        id: id,
                    },
                    url: '/ajax.php',
                    type: 'POST',
                    success: function(data) {
                        if (data == null || data.code != 1000) {
                            alert(data.message);
                        } else {
                            agent_cash_flow_order_type_list = data.data.acf_order_type_list;
                            loadAgentCashFlowOrderTypeListDatatable();
                            successAlert();
                        }
                    },
                    error: function(err) {

                        alert('error');

                    }
                });
            }
        });

        //取消事件
        tbl.on( 'destroy.dt', function ( e, settings ) {
            $(this).off( 'change', 'input[name=toggle_status]' );
            $(this).off( 'click', '.editAgentCashFlowOrderTypeBtn' );
        } );

        console.log(tbl);
    }

    console.log(agent_cash_flow_list);

    let CashFlowSel = $('select[name=cash_flow_id]').selectize({});

    $(document).ready(function() {
        // 支付商戶表單送出
        $( "#editAgentCashFlowForm" ).on( "submit", function( event ) {
            event.preventDefault();
            let data = $( this ).serialize() + "&group_id=" + $("#group_id").val() + "&action=updateAgentCashFlow";
            $.ajax({
                cache: false,
                dataType: 'json',
                data: data,
                url: '/ajax.php',
                type: 'POST',
                success: function(data) {
                    if (data == null || data.code != 1000) {
                        alert(data.message);
                    } else {
                        successAlert();
                        location.reload();
                        $('#modal-agent-cash-flow-form').modal('hide');
                    }

                },
                error: function(err) {

                    alert('error');

                }
            });
        });

        // 支付方式表單送出
        $( "#editAgentCashFlowOrderTypeForm" ).on( "submit", function( event ) {
            event.preventDefault();
            let data = $( this ).serialize() + "&action=updateAgentCashFlowOrderType";
            $.ajax({
                cache: false,
                dataType: 'json',
                data: data,
                url: '/ajax.php',
                type: 'POST',
                success: function(data) {
                    if (data == null || data.code != 1000) {
                        alert(data.message);
                    } else {
                        successAlert();
                        console.log(agent_cash_flow_order_type_list);
                        //location.reload();
                        let agentCashFlowOrderType = agent_cash_flow_order_type_list.filter(function(item){
                            return item.id == data.data.agent_cash_flow_id;
                        }).shift();

                        agent_cash_flow_order_type_list = data.data.acf_order_type_list;
                        console.log(agent_cash_flow_order_type_list);
                        loadAgentCashFlowOrderTypeListDatatable();
                        $('#modal-agent-cash-flow-order-type-form').modal('hide');
                    }

                },
                error: function(err) {

                    alert('error');

                }
            });
        });

        // 新增支付商戶按鈕
        $(".createAgentCashFlowBtn").click(function(){
            data = {
                id: '',
                cash_flow_id: '',
                name: '',
                rate: '',
                sorts: '',
                info: '',
            };
            agentCashFlowModel(data);
        });

        // 新增支付方式按鈕
        $(".createAgentCashFlowOrderTypeBtn").click(function(){
            data = {
                id: '',
                order_type_id: '',
                min_amount: '',
                max_amount: '',
                available_amount: '',
                sorts: '',
            };
            agentCashFlowOrderTypeModel(data);
        });


        $(document).on("click", ".editAgentCashFlowBtn", function() {
            let acf_id = $(this).attr('data-row-id');
            let agentCashFlow = agent_cash_flow_list.filter(function(item){
                return item.id == acf_id;
            }).shift()
            agentCashFlowModel(agentCashFlow);

            $($(this).attr('data-target')).modal('show');
        });
        // $(".editAgentCashFlowBtn").click(function(){
        //
        //     let acf_id = $(this).attr('data-row-id');
        //     let agentCashFlow = agent_cash_flow_list.filter(function(item){
        //         return item.id == acf_id;
        //     }).shift()
        //     agentCashFlowModel(agentCashFlow);
        //
        //     $($(this).attr('data-target')).modal('show');
        // });

        $(document).on("click", ".editOrderTypeBtn", function() {
            let el = this;
            let acf_id = $(this).attr('data-row-id');
            $(".createAgentCashFlowOrderTypeBtn").data('data-row-id', acf_id);
            $("#editAgentCashFlowOrderTypeForm input[name=agent_cash_flow_id]").val(acf_id);
            // let agentCashFlow = agent_cash_flow_list.filter(function(item){
            //     return item.id == acf_id;
            // }).shift()
            // agentCashFlowModel(agentCashFlow);
            $.ajax({
                cache: false,
                dataType: 'json',
                data: {
                    action: 'acfOrderTypeList',
                    acf_id: acf_id,
                },
                url: '/ajax.php',
                type: 'POST',
                success: function(data) {
                    if (data == null || data.code != 1000) {
                        alert(data.message);
                    } else {

                        agent_cash_flow_order_type_list = data.data.acf_order_type_list;
                        order_type_list = data.data.order_type_list;
                        let sel = $('#editAgentCashFlowOrderTypeForm select[name=order_type_id]').selectize({
                            valueField: 'id',
                            labelField: 'name',
                            searchField: 'name',
                        }).data('selectize');
                        sel.clearOptions();

                        sel.addOption(order_type_list);
                        sel.refreshOptions(false);
                        loadAgentCashFlowOrderTypeListDatatable();
                        //狀態事件
                        $($(el).attr('data-target')).modal('show');
                        //successAlert();
                    }
                },
                error: function(err) {
                    alert('error');
                }
            });
        });

        // $(".editOrderTypeBtn").click(function(){
        //     let el = this;
        //     let acf_id = $(this).attr('data-row-id');
        //     $(".createAgentCashFlowOrderTypeBtn").data('data-row-id', acf_id);
        //     $("#editAgentCashFlowOrderTypeForm input[name=agent_cash_flow_id]").val(acf_id);
        //     // let agentCashFlow = agent_cash_flow_list.filter(function(item){
        //     //     return item.id == acf_id;
        //     // }).shift()
        //     // agentCashFlowModel(agentCashFlow);
        //     $.ajax({
        //         cache: false,
        //         dataType: 'json',
        //         data: {
        //             action: 'acfOrderTypeList',
        //             acf_id: acf_id,
        //         },
        //         url: '/ajax.php',
        //         type: 'POST',
        //         success: function(data) {
        //             if (data == null || data.code != 1000) {
        //                 alert(data.message);
        //             } else {
        //
        //                 agent_cash_flow_order_type_list = data.data.acf_order_type_list;
        //                 order_type_list = data.data.order_type_list;
        //                 let sel = $('#editAgentCashFlowOrderTypeForm select[name=order_type_id]').selectize({
        //                     valueField: 'id',
        //                     labelField: 'name',
        //                     searchField: 'name',
        //                 }).data('selectize');
        //                 sel.clearOptions();
        //
        //                 sel.addOption(order_type_list);
        //                 sel.refreshOptions(false);
        //                 loadAgentCashFlowOrderTypeListDatatable();
        //                 //狀態事件
        //                 $($(el).attr('data-target')).modal('show');
        //                 //successAlert();
        //             }
        //         },
        //         error: function(err) {
        //             alert('error');
        //         }
        //     });
        // });

        $("#dt-opt input[name=toggle_status]").change(function(){ //開關 ajax
            let this_obj = this;
            let acf_id = $(this).attr('data-row-id');
            let status = $(this).prop('checked')?1:0;
            $.ajax({
                cache: false,
                dataType: 'json',
                data: {
                    action: 'agentCashFlowToggleStatus',
                    acf_id: acf_id,
                    status: status
                },
                url: '/ajax.php',
                type: 'POST',
                success: function(data) {
                    if (data == null || data.code != 1000) {
                        alert(data.message);
                    } else {
                        successAlert();
                    }
                    $(this_obj).prop('checked', data.data.status == 1);
                },
                error: function(err) {

                    alert('error');

                }
            });
        });
    });
//
//
//
// function save(parent_elementID, isEditMode) {
//
//     let settingIDs = "";
//
//     $("#" + parent_elementID + " .js_setting_id").each(function() {
//         settingIDs += $(this).val() + ",";
//     });
//
//     let group_id = $("#group_id").val();
//     settingIDs = settingIDs.substr(0, (settingIDs.length - 1));
//
//     var form_post = $("#" + parent_elementID + " form").serialize() +
//         "&action=update_agent_customized_setting&setting_ids=" +
//         settingIDs + "&group_id=" + group_id;
//
//     $.ajax({
//         cache: false,
//         dataType: 'json',
//         data: form_post,
//         url: '/ajax.php',
//         type: 'POST',
//         success: function(data) {
//
//             if (data == null || data.code != 1000) {
//                 alert(data.message);
//             } else {
//                 successAlert();
//             }
//
//             switchEditOrNotEdit(parent_elementID, 0);
//
//         },
//         error: function(err) {
//
//             alert('error');
//
//         }
//     });
// }
//
//
//
// function switchEditOrNotEdit(parent_elementID, isEditMode) {
//
//     $("#" + parent_elementID).removeClass("disabledEdit");
//     $("#" + parent_elementID).find(".js_SwitchToEdit").hide();
//     $("#" + parent_elementID).find(".js_Cancel").hide();
//     $("#" + parent_elementID).find(".js_Save").hide();
//
//     if (isEditMode == 1) {
//
//         $("#" + parent_elementID).find(".js_Cancel").show();
//         $("#" + parent_elementID).find(".js_Save").show();
//         $("#" + parent_elementID).find("input").removeAttr("disabled");
//
//     } else {
//
//         $("#" + parent_elementID).addClass("disabledEdit");
//         $("#" + parent_elementID).find(".js_SwitchToEdit").show();
//         $("#" + parent_elementID).find("input").attr("disabled", "disabled");
//     }
//
//
// }
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