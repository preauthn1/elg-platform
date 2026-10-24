<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';

//確認權限
if (!isset($check_auth['deposit/index'])) {
    echo display_error_msg_html($lang['nopermission']);
    exit;
}

$api_response = curlPost('api/agent/onlineDeposit/getCashFlow');

$code = $api_response["code"];

if ($code != 1000) {
    echo display_error_msg_html($api_response["message"]);
    exit;
}

$cash_flow_list = $api_response["data"]["cash_flow_list"];
$language_list = $api_response["data"]["language_list"];


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
                    <?= $lang['menu_system_management'] ?> >
                    <?= $lang['menu_deposit_vendor_management'] ?>
                </h4>
            </div>

            <div>
                <div class="agent_name_row">
                    <div class="row">
                        <div class="col-md-6">
                        </div>
                        <div class="col-md-6"></div>
                    </div>
                </div>
                <div>
                    <hr>
                </div>
            </div>
            <div class="row">
                <a href="#" data-toggle="modal" data-target="#modal-cash-flow-form"
                    class="btn btn-inverse btn-success createCashFlowBtn"><?= $lang['add'] ?></a>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="table-overflow">
                                <table id="dt-opt" class="table table-lg table-hover">
                                    <thead>
                                        <tr>
                                            <th class="sorting">ID</th>
                                            <th><?= $lang['deposit_vendor_name'] ?></th>
                                            <th><?= $lang['type'] ?></th>
                                            <th><?= $lang['status'] ?></th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                    foreach ($cash_flow_list as $row) {
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <b><?= $row['id'] ?></b>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span class="text-info"><?= $row['name'] ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <?= ($row['type'] == 1) ? $lang['onlinepay'] : $lang['offlinepay'] ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="toggle-checkbox checkbox-inline toggle-sm mrg-top-15">
                                                    <input type="checkbox" name="toggle_status"
                                                        id="toggle_<?= $row['id'] ?>"
                                                        <?= ($row['status'] == 1) ? "checked" : "" ?>
                                                        data-row-id="<?= $row['id'] ?>">
                                                    <label for="toggle_<?= $row['id'] ?>"></label>
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
                                                                <a class="editCashFlowBtn"
                                                                    data-target="#modal-cash-flow-form"
                                                                    data-row-id="<?= $row['id'] ?>"
                                                                    href="#"><?= $lang['edit'] ?></a>
                                                            </li>
                                                            <?php if($row['id'] != 5 && $row['id'] != 6) { ?>
                                                            <li>
                                                                <a class="editOrderTypeBtn"
                                                                    data-target="#modal-order-type-list"
                                                                    data-row-id="<?= $row['id'] ?>"
                                                                    href="#"><?= $lang['deposit_order_type_setting'] ?></a>
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
    <div class="modal fade" id="modal-cash-flow-form">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-body">
                    <div>
                        <div class="row">
                            <div class="card col-md-12">
                                <div class="card-heading border bottom">
                                    <h4 class="card-title"><?= $lang['edit'] . " " . $lang['online_deposit_setting'] ?>
                                    </h4>
                                </div>
                                <div class="card-block">
                                    <div class="mrg-top-20">
                                        <div class="row">
                                            <div class="col-md-12 ml-auto mr-auto">
                                                <form id="editCashFlowForm">
                                                    <input type="hidden" name="id" />
                                                    <div class="row">
                                                        <div class="col-md-6">
                                                            <div class="form-group">
                                                                <label><?= $lang['deposit_vendor_name'] ?></label>
                                                                <input type="text" class="form-control" name="name"
                                                                    required="" aria-required="true">
                                                            </div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <div class="form-group">
                                                                <label><?= $lang['type'] ?></label>
                                                                <select name="type">
                                                                    <option value="1">線上支付</option>
                                                                    <option value="2">線下支付</option>
                                                                </select>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="row">
                                                        <div class="col-md-12 col-xs-6">
                                                            <div class="text-right mrg-top-5">
                                                                <button type="submit"
                                                                    class="btn btn-primary"><?= $lang['btn_update'] ?></button>
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
                            <a href="#" data-toggle="modal" data-target="#modal-cash-flow-order-type-form"
                                class="btn btn-inverse btn-success createCashFlowOrderTypeBtn"><?= $lang['add'] ?></a>
                        </div>
                        <div class="row">
                            <div class="card col-md-12">
                                <div class="card-heading border bottom">
                                    <h4 class="card-title"><?= $lang['deposit_order_type_setting'] ?></h4>
                                </div>
                                <div class="card-block">
                                    <div class="table-overflow">
                                        <table id="dt-order-type-list" class="table table-lg table-hover"
                                            style="width:100%">
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

    <div class="modal fade" id="modal-cash-flow-order-type-form">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-body">
                    <div>
                        <div class="row">
                            <div class="card col-md-12">
                                <div class="card-heading border bottom">
                                    <h4 class="card-title">
                                        <?= $lang['edit'] . " " . $lang['deposit_order_type_setting'] ?></h4>
                                </div>
                                <div class="card-block">
                                    <div class="mrg-top-20">
                                        <div class="row">
                                            <div class="col-md-12 ml-auto mr-auto">
                                                <form id="editCashFlowOrderTypeForm">
                                                    <input type="hidden" name="id" />
                                                    <input type="hidden" name="cash_flow_id" />
                                                    <div class="row">
                                                        <div class="col-md-12">
                                                            <div class="form-group">
                                                                <label><?= $lang['deposit_order_type_name'] ?></label>
                                                                <input type="text" class="form-control" name="name"
                                                                    required="" aria-required="true">
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="row">
                                                        <div class="col-md-6">
                                                            <div class="form-group">
                                                                <label><?= $lang['deposit_order_type_key'] ?></label>
                                                                <input type="text" class="form-control" name="key"
                                                                    required="" aria-required="true">
                                                            </div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <div class="form-group">
                                                                <label><?= $lang['sorts'] ?></label>
                                                                <input type="text" class="form-control" name="sorts"
                                                                    required="" aria-required="true">
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="row">
                                                        <div class="col-md-12 col-xs-6">
                                                            <div class="text-right mrg-top-5">
                                                                <button type="submit"
                                                                    class="btn btn-primary"><?= $lang['btn_update'] ?></button>
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
    <div class="modal fade" id="modal-cash-flow-order-type-name-form">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-body">
                    <div>
                        <div class="row">
                            <div class="card col-md-12">
                                <div class="card-heading border bottom">
                                    <h4 class="card-title">
                                        <?= $lang['edit'] . " " . $lang['deposit_order_type_setting'] ?></h4>
                                </div>
                                <div class="card-block">
                                    <div class="mrg-top-20">
                                        <div class="row">
                                            <div class="col-md-12 ml-auto mr-auto">
                                                <form id="editCashFlowOrderTypeNameForm">
                                                    <input type="hidden" name="order_type_id" />
                                                    <div class="row">
                                                        <div class="col-md-2">
                                                            <div class="form-group">
                                                                <label><?= $lang['deposit_vendor_name'] ?></label>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <div class="form-group">
                                                                <input type="text" class="form-control" name="name">
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <?php foreach ($language_list as $row) { ?>
                                                    <div class="row">
                                                        <div class="col-md-2">
                                                            <div class="form-group">
                                                                <label><?= $row['name'] ?></label>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <div class="form-group">
                                                                <input type="text" class="form-control"
                                                                    name="order_type_name<?= $row['id'] ?>">
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <?php } ?>
                                                    <div class="row">
                                                        <div class="col-md-12 col-xs-6">
                                                            <div class="text-right mrg-top-5">
                                                                <button type="submit"
                                                                    class="btn btn-primary"><?= $lang['btn_update'] ?></button>
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
let cash_flow_list = <?=json_encode($cash_flow_list)?>;
let order_type_list = {};
let cash_flow_order_type_list = {};
let cash_flow_order_type_name_list = {};

function cashFlowModel(data) {
    console.log(data);
    $('#editCashFlowForm input[name=id]').val(data.id);
    $('#editCashFlowForm input[name=name]').val(data.name);
    $('#editCashFlowForm select[name=type]')[0].selectize.setValue(data.type);
}

function cashFlowOrderTypeModel(data) {
    // console.log(data.cash_flow_id);
    // if (data.cash_flow_id) {
    //     $('#editCashFlowOrderTypeForm select[name=cash_flow_id]')[0].selectize.setValue(data.cash_flow_id);
    //     $('#editCashFlowOrderTypeForm select[name=cash_flow_id]')[0].selectize.disable();
    // } else {
    //     $('#editCashFlowOrderTypeForm select[name=cash_flow_id]')[0].selectize.clear();
    //     $('#editCashFlowOrderTypeForm select[name=cash_flow_id]')[0].selectize.enable();
    // }
    $('#editCashFlowOrderTypeForm input[name=id]').val(data.id);
    // $('#editCashFlowOrderTypeForm input[name=cash_flow_id]').val(data.cash_flow_id);
    $('#editCashFlowOrderTypeForm input[name=name]').val(data.name);
    $('#editCashFlowOrderTypeForm input[name=key]').val(data.key);
    $('#editCashFlowOrderTypeForm input[name=sorts]').val(data.sorts);
    $('#editCashFlowOrderTypeForm input[name=status]').val(data.status);
    // try {
    //     $('#editCashFlowOrderTypeForm textarea[name=available_amount]').val(data.available_amount);
    // } catch (e) {
    //     $('#editCashFlowOrderTypeForm textarea[name=info]').val("");
    // }
}

function cashFlowOrderTypeNameModel(data) {
    console.log(data);
    $('#editCashFlowOrderTypeNameForm input[name=order_type_id]').val(data.order_type.id);
    $('#editCashFlowOrderTypeNameForm input[name=name]').val(data.order_type.name);
    $('#editCashFlowOrderTypeNameForm input[name=name]').attr('disabled', true);
    data.order_type_name_list.forEach(function(item) {
        let id = item.language_id;
        // console.log(id);
        $('#editCashFlowOrderTypeNameForm input[name=order_type_name' + id + ']').val(item.name);
    })
}

function loadCashFlowOrderTypeListDatatable() {
    let table_id = "#dt-order-type-list";
    console.log(cash_flow_list);
    let tbl = null;
    if ($.fn.DataTable.isDataTable(table_id)) {
        $(table_id).DataTable().destroy();
    }

    tbl = $(table_id).dataTable({
        data: cash_flow_order_type_list,
        columns: [{
            "data": "id",
            "title": "ID"
        }, {
            "data": "name",
            "title": "<?=$lang['deposit_order_type_name']?>"
        }, {
            "data": "cash_flow_name",
            "title": "<?=$lang['deposit_vendor_name']?>"
        }, {
            "data": "key",
            "title": "<?=$lang['deposit_order_type_key']?>"
        }, {
            "data": "sorts",
            "title": "<?=$lang['sorts']?>"
        }, {
            "title": "<?=$lang['status']?>",
            "render": function(data, type, row, meta) {
                let checked = (row['status'] == 1) ? "checked" : "";
                let html = '<div class="toggle-checkbox checkbox-inline toggle-sm">' +
                    '<input type="checkbox" name="toggle_status" id="ot_toggle_' + row['id'] +
                    '" data-row-id="' + row['id'] + '"' + checked + ' />' +
                    '<label for="ot_toggle_' + row['id'] + '"></label>' +
                    '</div>';
                return html;
            }
        }, {
            "render": function(data, type, row, meta) {
                return '<a href="#" class="btn btn-info btn-sm editCashFlowOrderTypeBtn" data-target="#modal-cash-flow-order-type-form" data-row-id="' +
                    row['id'] + '"><?=$lang['edit']?></a>' +
                    '<a href="#" class="btn btn-danger btn-sm editCashFlowOrderTypeNameBtn" data-target="#modal-cash-flow-order-type-name-form" data-row-id="' +
                    row['id'] + '"><?=$lang['language']?></a>';
            }
        }]
    });
    tbl.on('change', 'input[name=toggle_status]', function() {
        let this_obj = this;
        let id = $(this).attr('data-row-id');
        let status = $(this).prop('checked') ? 1 : 0;
        $.ajax({
            cache: false,
            dataType: 'json',
            data: {
                action: 'cashFlowOrderTypeToggleStatus',
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
    tbl.on('click', '.editCashFlowOrderTypeBtn', function() {
        console.log(order_type_list);
        let this_obj = this;
        let id = $(this).attr('data-row-id');
        let cashFlowOrderType = cash_flow_order_type_list.filter(function(item) {
            return item.id == id;
        }).shift()
        console.log(cashFlowOrderType);
        cashFlowOrderTypeModel(cashFlowOrderType);
        //agentCashFlowModel(agentCashFlow);
        $($(this).attr('data-target')).modal('show');
    });

    //編輯事件
    tbl.on('click', '.editCashFlowOrderTypeNameBtn', function() {
        console.log(order_type_list);

        let this_obj = this;
        let id = $(this).attr('data-row-id');
        $.ajax({
            cache: false,
            dataType: 'json',
            data: {
                action: 'getCashFlowOrderTypeName',
                id: id,
            },
            url: '/ajax.php',
            type: 'POST',
            success: function(data) {
                if (data == null || data.code != 1000) {
                    alert(data.message);
                } else {
                    cash_flow_order_type_name_list = data.data;
                    cashFlowOrderTypeNameModel(cash_flow_order_type_name_list);
                }
            },
            error: function(err) {
                alert('error');

            }
        });
        $($(this).attr('data-target')).modal('show');
    });

    //刪除事件
    //tbl.on('click', '.deleteAgentCashFlowOrderTypeBtn', function () {
    //    if (confirm('<?//=$lang['deposit_order_type_delete_message']?>// ' + 'ID : ' + $(this).attr('data-row-id'))) {
    //        let this_obj = this;
    //        let id = $(this).attr('data-row-id');
    //        $.ajax({
    //            cache: false,
    //            dataType: 'json',
    //            data: {
    //                action: 'agentCashFlowOrderTypeDelete',
    //                id: id,
    //            },
    //            url: '/ajax.php',
    //            type: 'POST',
    //            success: function (data) {
    //                if (data == null || data.code != 1000) {
    //                    alert(data.message);
    //                } else {
    //                    agent_cash_flow_order_type_list = data.data.acf_order_type_list;
    //                    loadAgentCashFlowOrderTypeListDatatable();
    //                    successAlert();
    //                }
    //            },
    //            error: function (err) {
    //
    //                alert('error');
    //
    //            }
    //        });
    //    }
    //});

    //取消事件
    tbl.on('destroy.dt', function(e, settings) {
        $(this).off('change', 'input[name=toggle_status]');
        $(this).off('click', '.editCashFlowOrderTypeBtn');
    });

    console.log(tbl);
}

let TypeSel = $('select[name=type]').selectize({});

$(document).ready(function() {
    // 支付商戶表單送出
    $("#editCashFlowForm").on("submit", function(event) {
        event.preventDefault();
        let data = $(this).serialize() + "&action=updateCashFlow";
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
                    $('#modal-cash-flow-form').modal('hide');
                }

            },
            error: function(err) {

                alert('error');

            }
        });
    });

    // 支付方式表單送出
    $("#editCashFlowOrderTypeForm").on("submit", function(event) {
        event.preventDefault();
        let data = $(this).serialize() + "&action=updateCashFlowOrderType";
        $.ajax({
            cache: false,
            dataType: 'json',
            data: data,
            url: '/ajax.php',
            type: 'POST',
            success: function(data) {
                if (data == null || data.code != 1000) {
                    console.log(data);
                    alert(data.message);
                } else {
                    successAlert();
                    console.log(cash_flow_order_type_list);
                    //location.reload();
                    let cashFlowOrderType = cash_flow_order_type_list.filter(function(
                        item) {
                        return item.id == data.data;
                    }).shift();
                    cash_flow_order_type_list = data.data.cash_flow_order_type_list;
                    console.log(cash_flow_order_type_list);
                    loadCashFlowOrderTypeListDatatable();
                    $('#modal-cash-flow-order-type-form').modal('hide');
                }

            },
            error: function(err) {

                alert('error');

            }
        });
    });

    // 新增支付商戶按鈕
    $(".createCashFlowBtn").click(function() {
        data = {
            id: '',
            name: '',
            type: '',
        };
        cashFlowModel(data);
    });

    // 新增支付方式按鈕
    $(".createCashFlowOrderTypeBtn").click(function() {
        data = {
            id: '',
            cash_flow_id: '',
            name: '',
            key: '',
            sorts: '',
        };
        cashFlowOrderTypeModel(data);
    });

    $(".editCashFlowBtn").click(function() {
        let cf_id = $(this).attr('data-row-id');
        let cashFlow = cash_flow_list.filter(function(item) {
            return item.id == cf_id;
        }).shift();
        cashFlowModel(cashFlow);
        $($(this).attr('data-target')).modal('show');
    });

    $(".editOrderTypeBtn").click(function() {
        let el = this;
        let cf_id = $(this).attr('data-row-id');
        // console.log(cf_id);
        // $(".createCashFlowOrderTypeBtn").data('data-row-id', cf_id);
        $("#editCashFlowOrderTypeForm input[name=cash_flow_id]").val(cf_id);

        $.ajax({
            cache: false,
            dataType: 'json',
            data: {
                action: 'getOrderTypeList',
                cf_id: cf_id,
            },
            url: '/ajax.php',
            type: 'POST',
            success: function(data) {
                if (data == null || data.code != 1000) {
                    alert(data.message);
                } else {
                    cash_flow_order_type_list = data.data;
                    console.log(cash_flow_order_type_list);
                    // let sel = $('#editCashFlowOrderTypeForm select[name=cash_flow_id]').selectize({
                    //     valueField: 'cash_flow_id',
                    //     labelField: 'cash_flow_name',
                    //     searchField: 'cash_flow_name',
                    // }).data('selectize');
                    // sel.clearOptions();
                    // sel.addOption(cash_flow_order_type_list);
                    // sel.refreshOptions(false);
                    loadCashFlowOrderTypeListDatatable();
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


    // $("#dt-opt input[name=toggle_status]").change(function() { 
    //$('body').on('change', '#id', function() {

    $('#dt-opt').on('change', 'input[name=toggle_status]', function() { //開關 ajax
        let this_obj = this;
        let cf_id = $(this).attr('data-row-id');
        let status = $(this).prop('checked') ? 1 : 0;
        $.ajax({
            cache: false,
            dataType: 'json',
            data: {
                action: 'cashFlowToggleStatus',
                cf_id: cf_id,
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

    //語系按鈕
    $("#editCashFlowOrderTypeNameForm").on("submit", function(event) {
        event.preventDefault();
        let data = $(this).serialize() + "&action=updateOrderTypeName";
        console.log(data);
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
                    $('#modal-cash-flow-form').modal('hide');
                }

            },
            error: function(err) {

                alert('error');

            }
        });
    });
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