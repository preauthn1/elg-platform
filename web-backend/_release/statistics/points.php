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
                            <h4>統計報表查詢 > 轉點報表</h4>
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
                                                        <label for="form-1-1" class="col-md-2 control-label">營運商</label>
                                                        <div class="col-md-10">
                                                            <select id="selectize-dropdown">
                                                                <option value="" disabled selected>Select a person...</option>
                                                                <option value="4">Thomas Edison</option>
                                                                <option value="1">Nikola</option>
                                                                <option value="3">Nikola Tesla</option>
                                                                <option value="5">Arnold Schwarzenegger</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div class="form-group row">
                                                        <label for="form-1-2" class="col-md-2 control-label">查詢區間</label>
                                                        <div class="col-md-10">
                                                            <input type="text" id="date-range-picker" class="form-control" value="01/01/2015 - 01/31/2015" placeholder="Date range picker" />
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
                                    <div class="card-heading border bottom">
                                        <h4 class="card-title">Report Chart</h4>
                                    </div>
                                    <div class="card-block">
                                        <div>
                                            <canvas id="line-chart"></canvas>
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

            var primary = '#7774e7',
                success = '#37c936',
                info = '#0f9aee',
                warning = '#ffcc00',
                danger = '#ff3c7e',
                primaryInverse = 'rgba(119, 116, 231, 0.1)',
                successInverse = 'rgba(55, 201, 54, 0.1)',
                infoInverse = 'rgba(15, 154, 238, 0.1)',
                warningInverse = 'rgba(255, 204, 0, 0.1)',
                dangerInverse = 'rgba(255, 60, 126, 0.1)',
                gray = '#f6f7fb',
                white = '#fff',
                dark = '#515365'

            //Line Chart
            var lineChart = document.getElementById("line-chart");
            var lineCtx = lineChart.getContext('2d');
            lineChart.height = 80;
            var lineConfig = new Chart(lineCtx, {
                type: 'line',
                data: {
                    labels: ["January", "February", "March", "April", "May", "June", "July"],
                    datasets: [
                    {
                        label: '轉入遊戲',
                        backgroundColor: infoInverse,
                        borderColor: info,
                        pointBackgroundColor: info,
                        borderWidth: 2,
                        data: [65, 59, 80, 81, 56, 55, 40]
                    },
                    {
                        label: '遊戲轉出',
                        backgroundColor: successInverse,
                        borderColor: success,
                        pointBackgroundColor: success,
                        borderWidth: 2,
                        data: [28, 48, 40, 19, 86, 27, 90]
                    }]
                },

                options: {
                    legend: {
                        display: false
                    }
                }
            });

        });
    </script>
