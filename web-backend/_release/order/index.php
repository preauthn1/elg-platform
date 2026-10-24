<?php
require_once '../_init.php';
require_once '../_header.php';
require_once '../_menu.php';
?>
            <!-- Page Container START -->
            <div class="page-container">
<?php
require_once '../_top.php';
?>
                <!-- theme configurator START -->
                <div class="theme-configurator">
                    <div class="configurator-wrapper">
                        <div class="config-header">
                            <h4 class="config-title">Config Panel</h4>
                            <button class="config-close">
                                <i class="ti-close"></i>
                            </button>
                        </div>
                        <div class="config-body">
                            <div class="mrg-btm-30">
                                <p class="lead font-weight-normal">Header Color</p>
                                <div class="theme-colors header-default">
                                    <input type="radio" id="header-default" name="theme">
                                    <label for="header-default"></label>
                                </div>
                                <div class="theme-colors header-primary">
                                    <input type="radio" class="primary" id="header-primary" name="theme">
                                    <label for="header-primary"></label>
                                </div>
                                <div class="theme-colors header-info">
                                    <input type="radio" id="header-info" name="theme">
                                    <label for="header-info"></label>
                                </div>
                                <div class="theme-colors header-success">
                                    <input type="radio" id="header-success" name="theme">
                                    <label for="header-success"></label>
                                </div>
                                <div class="theme-colors header-danger">
                                    <input type="radio" id="header-danger" name="theme">
                                    <label for="header-danger"></label>
                                </div>
                                <div class="theme-colors header-dark">
                                    <input type="radio" id="header-dark" name="theme">
                                    <label for="header-dark"></label>
                                </div>
                            </div>
                            <div class="mrg-btm-30">
                                <p class="lead font-weight-normal">Sidebar Color</p>
                                <div class="theme-colors sidenav-default">
                                    <input type="radio" id="sidenav-default" name="sidenav">
                                    <label for="sidenav-default"></label>
                                </div>
                                <div class="theme-colors side-nav-dark">
                                    <input type="radio" id="side-nav-dark" name="sidenav">
                                    <label for="side-nav-dark"></label>
                                </div>
                            </div>
                            <div class="mrg-btm-30">
                                <p class="lead font-weight-normal no-mrg-btm">RTL</p>
                                <div class="toggle-checkbox checkbox-inline toggle-sm mrg-top-10">
                                    <input type="checkbox" name="rtl-toogle" id="rtl-toogle">
                                    <label for="rtl-toogle"></label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- theme configurator END -->
                <!-- Theme Toggle Button START -->
                <button class="theme-toggle btn btn-rounded btn-icon">
                    <i class="ti-palette"></i>
                </button>
                <!-- Theme Toggle Button END -->
                <!-- Content Wrapper START -->
                <div class="main-content">
                    <div class="container-fluid">
                        <div class="page-title">
                            <h4>金流/訂單管理 > 儲值訂單查詢</h4>
                        </div>

                        <div class="row">
                            <div class="col-md-12">
                                <div class="card">
                                    <div class="card-block">
                                        <div class="row">
                                            <div class="col-md-7">
                                                <h4 class="card-title">Search</h4>
                                                <form class="form-horizontal mrg-top-40 pdd-right-30">
                                                    <div class="form-group row">
                                                        <label for="form-1-1" class="col-md-2 control-label">帳號</label>
                                                        <div class="col-md-6">
                                                            <input type="text" class="form-control" />
                                                        </div>
                                                    </div>
                                                    <div class="form-group row">
                                                        <label for="form-1-1" class="col-md-2 control-label">訂單編號</label>
                                                        <div class="col-md-8">
                                                            <input type="text" class="form-control" />
                                                        </div>
                                                    </div>  
                                                    <div class="form-group row">
                                                        <label for="form-1-1" class="col-md-2 control-label">代理商</label>
                                                        <div class="col-md-4">
                                                            <select id="selectize-dropdown1">
                                                                <option value="" disabled selected>全部</option>
                                                                <option value="0">全部</option>
                                                                <option value="1">Agent 1</option>
                                                                <option value="2">Agent 2</option>
                                                                <option value="3">Agent 3</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div class="form-group row">
                                                        <label for="form-1-1" class="col-md-2 control-label">管道</label>
                                                        <div class="col-md-4">
                                                            <select id="selectize-dropdown2">
                                                                <option value="" disabled selected>全部</option>
                                                                <option value="0">全部</option>
                                                                <option value="1">Paypal</option>
                                                                <option value="2">Credit Card</option>
                                                                <option value="3">微信支付</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div class="form-group row">
                                                        <label for="form-1-2" class="col-md-2 control-label"></label>
                                                        <div class="col-md-10">
                                                            <button class="btn btn-primary">Search</button>
                                                            <input type="reset" class="btn btn-default" value="Clear" />
                                                        </div>
                                                    </div>
                                                </form>
                                            </div>

                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-12">
                                <div class="card">
                                    <div class="card-block">
                                        <div class="table-overflow">
                                            <table id="dt-opt" class="table table-lg table-hover">
                                                <thead>
                                                    <tr>
                                                        <th>訂單編號</th>
                                                        <th>帳號</th>
                                                        <th>管道</th>
                                                        <th>金額</th>
                                                        <th>點數</th>
                                                        <th>狀態</th>                                                        
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr>
                                                        <td>
                                                            <div class="mrg-top-15">
                                                                <span>H11904111000</span>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <div class="list-info mrg-top-10">                                                                
                                                                <div class="info">
                                                                    <span class="title">AaronWU</span>
                                                                    <span class="sub-title">ID 861</span>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <div class="mrg-top-15">
                                                                <span>Vendor / Credit Card</span>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <div class="mrg-top-15">
                                                                <span>100</span>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <div class="mrg-top-15">
                                                                <span>100</span>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <div class="mrg-top-15">
                                                                成功
                                                            </div>
                                                        </td>                                                        
                                                    </tr>
                                                    <tr>
                                                        <td>
                                                            <div class="mrg-top-15">
                                                                <span>H11904111000</span>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <div class="list-info mrg-top-10">                                                                
                                                                <div class="info">
                                                                    <span class="title">AaronWU</span>
                                                                    <span class="sub-title">ID 861</span>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <div class="mrg-top-15">
                                                                <span>Vendor / Credit Card</span>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <div class="mrg-top-15">
                                                                <span>100</span>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <div class="mrg-top-15">
                                                                <span>100</span>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <div class="mrg-top-15 text-warning">
                                                                等待中
                                                            </div>
                                                        </td>
                                                    </tr>
                                                    <tr>
                                                        <td>
                                                            <div class="mrg-top-15">
                                                                <span>H11904111000</span>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <div class="list-info mrg-top-10">                                                                
                                                                <div class="info">
                                                                    <span class="title">AaronWU</span>
                                                                    <span class="sub-title">ID 861</span>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <div class="mrg-top-15">
                                                                <span>Vendor / Credit Card</span>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <div class="mrg-top-15">
                                                                <span>100</span>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <div class="mrg-top-15">
                                                                <span>100</span>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <div class="mrg-top-15 text-danger">
                                                                失敗
                                                            </div>
                                                        </td>
                                                    </tr>
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
    <script type="text/javascript">
        $(document).ready(function () {
            $('#selectize-dropdown1').selectize({
                create: false,
                sortField: {
                    field: 'text',
                    direction: 'desc'
                },
                dropdownParent: 'body'
            });

            $('#selectize-dropdown2').selectize({
                create: false,
                sortField: {
                    field: 'text',
                    direction: 'desc'
                },
                dropdownParent: 'body'
            });
        });
    </script>
