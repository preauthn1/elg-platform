<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, shrink-to-fit=no">
    <title>Agent Backend</title>
    <!-- Favicon -->
    <link rel="shortcut icon" href="assets/images/logo/favicon.png">
    <!-- plugins css -->
    <link rel="stylesheet" href="/assets/vendors/bootstrap/dist/css/bootstrap.css" />
    <link rel="stylesheet" href="/assets/vendors/PACE/themes/blue/pace-theme-minimal.css" />
    <link rel="stylesheet" href="/assets/vendors/perfect-scrollbar/css/perfect-scrollbar.min.css" />
    <!-- page plugins css -->
    <link rel="stylesheet" href="/assets/vendors/selectize/dist/css/selectize.default.css" />
    <link rel="stylesheet" href="/assets/vendors/bootstrap-daterangepicker/daterangepicker.css" />
    <link rel="stylesheet" href="/assets/vendors/bootstrap-datepicker/dist/css/bootstrap-datepicker3.css" />
    <link rel="stylesheet" href="/assets/vendors/summernote/dist/summernote.css" />
    <!-- core css -->
    <link href="/assets/css/ei-icon.css" rel="stylesheet">
    <link href="/assets/css/themify-icons.css" rel="stylesheet">
    <link href="/assets/css/font-awesome.min.css" rel="stylesheet">
    <link href="/assets/css/animate.min.css" rel="stylesheet">
    <link href="/assets/css/app.css" rel="stylesheet">
</head>
<body>
    <div class="app">
        <div class="layout">
            <!-- Side Nav START -->
            <div class="side-nav">
                <div class="side-nav-inner">
                    <div class="side-nav-logo">
                        <a href="/index.html">
                            <!--<div class="logo logo-dark" style="background-image: url('assets/images/logo/logo.png')"></div>
                            <div class="logo logo-white" style="background-image: url('assets/images/logo/logo-white.png')"></div>-->
                        </a>
                        <div class="mobile-toggle side-nav-toggle">
                            <a href="">
                                <i class="ti-arrow-circle-left"></i>
                            </a>
                        </div>
                    </div>
                    <ul class="side-nav-menu scrollable">
                        <li class="nav-item active">
                            <a class="mrg-top-30" href="/index.html">
                                <span class="icon-holder">
                                    <i class="ti-home"></i>
                                </span>
                                <span class="title">Dashboard</span>
                            </a>
                        </li>
                        <li class="nav-item dropdown">
                            <a class="dropdown-toggle" href="javascript:void(0);">
                                <span class="icon-holder">
                                    <i class="ti-file"></i>
                                </span>
                                <span class="title">系統管理</span>
                                <span class="arrow">
                                    <i class="ti-angle-right"></i>
                                </span>
                            </a>
                            <ul class="dropdown-menu">
                                <li>
                                    <a href="/validation/index.html">會員認證選項管理</a>
                                </li>
                                <li>
                                    <a href="/domain/index.html">網域/版型管理</a>
                                </li>
                                <li>
                                    <a href="/order/index.html">儲值訂單查詢</a>
                                </li>
                            </ul>
                        </li>
                        <li class="nav-item dropdown">
                            <a class="dropdown-toggle" href="javascript:void(0);">
                                <span class="icon-holder">
                                    <i class="ti-palette"></i>
                                </span>
                                <span class="title">下線代理管理</span>
                                <span class="arrow">
                                    <i class="ti-angle-right"></i>
                                </span>
                            </a>
                            <ul class="dropdown-menu">
                                <li>
                                    <a href="/agent/index.html">帳號管理</a>
                                </li>
                                <li>
                                    <a href="/agent/add.html">建立帳號</a>
                                </li>
                            </ul>
                        </li>
                        <li class="nav-item dropdown">
                            <a class="dropdown-toggle" href="javascript:void(0);">
                                <span class="icon-holder">
                                    <i class="ti-file"></i>
                                </span>
                                <span class="title">下線會員管理</span>
                                <span class="arrow">
                                    <i class="ti-angle-right"></i>
                                </span>
                            </a>
                            <ul class="dropdown-menu">
                                <li>
                                    <a href="/member/index.html">帳號管理</a>
                                </li>
                                <li>
                                    <a href="/member/add.html">建立帳號</a>
                                </li>
                            </ul>
                        </li>
                        <li class="nav-item dropdown">
                            <a class="dropdown-toggle" href="javascript:void(0);">
                                <span class="icon-holder">
                                    <i class="ti-map-alt"></i>
                                </span>
                                <span class="title">統計報表查詢</span>
                                <span class="arrow">
                                    <i class="ti-angle-right"></i>
                                </span>
                            </a>
                            <ul class="dropdown-menu">
                                <li>
                                    <a href="/statistics/depositpoint.html">儲值報表</a>
                                </li>
                                <li>
                                    <a href="/statistics/points.html">轉點報表</a>
                                </li>
                                <li>
                                    <a href="/statistics/register.html">會員註冊報表</a>
                                </li>
                                
                            </ul>
                        </li>
                    </ul>
                </div>
            </div>
            <!-- Side Nav END -->
            <!-- Page Container START -->
            <div class="page-container">
                <!-- Header START -->
                <div class="header navbar">
                    <div class="header-container">
                        <ul class="nav-left">
                            <li>
                                <a class="side-nav-toggle" href="javascript:void(0);">
                                    <i class="ti-view-grid"></i>
                                </a>
                            </li>
                        </ul>
                        <ul class="nav-right">
                            <li class="user-profile dropdown">
                                <a href="" class="dropdown-toggle" data-toggle="dropdown">
                                    <img class="profile-img img-fluid" src="assets/images/user.jpg" alt="">
                                    <div class="user-info">
                                        <span class="name pdd-right-5">Aaron WU</span>
                                        <i class="ti-angle-down font-size-10"></i>
                                    </div>
                                </a>
                                <ul class="dropdown-menu">
                                    <li>
                                        <a href="">
                                            <i class="ti-settings pdd-right-10"></i>
                                            <span>設定</span>
                                        </a>
                                    </li>
                                    <!--<li>
                                        <a href="">
                                            <i class="ti-user pdd-right-10"></i>
                                            <span>Profile</span>
                                        </a>
                                    </li>
                                    <li>
                                        <a href="">
                                            <i class="ti-email pdd-right-10"></i>
                                            <span>Inbox</span>
                                        </a>
                                    </li>-->
                                    <li role="separator" class="divider"></li>
                                    <li>
                                        <a href="">
                                            <i class="ti-power-off pdd-right-10"></i>
                                            <span>登出</span>
                                        </a>
                                    </li>
                                </ul>
                            </li>
                            <li class="notifications dropdown">
                                <a href="" class="dropdown-toggle" data-toggle="dropdown">
                                    <i class="ei-show"></i>
                                </a>
                                <ul class="dropdown-menu ">
                                    <li class="notice-header">
                                        <i class="ti-bell pdd-right-10"></i>
                                        <span>語系</span>
                                    </li>
                                    <li>
                                        <ul class="list-info overflow-y-auto relative scrollable">
                                            <li>
                                                <a href="">
                                                    <span class="thumb-img bg-primary">
                                                        <span class="text-white">T</span>
                                                    </span>
                                                    <div class="info">
                                                        <span class="title">
                                                            <span class="font-size-14 text-semibold">繁體中文</span>
                                                            <!--<span class="text-gray">likes your <span class="text-dark">photo</span></span>-->
                                                        </span>
                                                        <span class="sub-title">切換為繁體中文</span>
                                                    </div>
                                                </a>
                                            </li>
                                            <li>
                                                <a href="">
                                                    <span class="thumb-img bg-danger">
                                                        <span class="text-white">E</span>
                                                    </span>
                                                    <div class="info">
                                                        <span class="title">
                                                            <span class="font-size-14 text-semibold">English</span>
                                                            <!--<span class="text-gray">likes your <span class="text-dark">photo</span></span>-->
                                                        </span>
                                                        <span class="sub-title">Switch to English</span>
                                                    </div>
                                                </a>
                                            </li>
                                            <li>
                                                <a href="">
                                                    <span class="thumb-img bg-success">
                                                        <span class="text-white">M</span>
                                                    </span>
                                                    <div class="info">
                                                        <span class="title">
                                                            <span class="font-size-14 text-semibold">Melayu</span>
                                                            <!--<span class="text-gray">likes your <span class="text-dark">photo</span></span>-->
                                                        </span>
                                                        <span class="sub-title">Beralih ke Bahasa Melayu</span>
                                                    </div>
                                                </a>
                                            </li>
                                        </ul>
                                    </li>
                                </ul>
                            </li>
                        </ul>
                    </div>
                </div>
                <!-- Header END -->
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
                                                        <label for="form-1-2" class="col-md-2 control-label">Date Range</label>
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
                <!-- Footer START -->
                <footer class="content-footer">
                    <div class="footer">
                        <div class="copyright">
                            <span>Copyright © 2019 <b class="text-dark">XSG</b>. All rights reserved.</span>
                            <span class="go-right">
                                <a href="#" class="text-gray mrg-right-15">Term &amp; Conditions</a>
                                <a href="#" class="text-gray">Privacy &amp; Policy</a>
                            </span>
                        </div>
                    </div>
                </footer>
                <!-- Footer END -->
            </div>
            <!-- Page Container END -->
        </div>
    </div>
    <script src="/assets/js/vendor.js"></script>
    <!-- page plugins js -->
    <script src="/assets/vendors/chart.js/dist/Chart.min.js"></script>
    <script src="/assets/js/app.min.js"></script>
    <!-- page plugins js -->
    <script src="/assets/vendors/selectize/dist/js/standalone/selectize.min.js"></script>
    <script src="/assets/vendors/moment/min/moment.min.js"></script>
    <script src="/assets/vendors/bootstrap-daterangepicker/daterangepicker.js"></script>
    <script src="/assets/vendors/bootstrap-datepicker/dist/js/bootstrap-datepicker.js"></script>
    <script src="/assets/vendors/bootstrap-timepicker/js/bootstrap-timepicker.js"></script>
    <script src="/assets/vendors/summernote/dist/summernote.min.js"></script>
    <!-- page js -->
    <script src="/assets/js/forms/form-elements.js"></script>
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
</body>
</html>