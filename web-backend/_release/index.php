<?php
require_once '_init.php';
require_once '_header.php';
require_once '_menu.php';
?>
<!-- Page Container START -->
<div class="page-container">
<?php
require_once '_top.php';
require_once '_theme.php';
?>
    <!-- Theme Toggle Button START -->
    <button class="theme-toggle btn btn-rounded btn-icon">
        <i class="ti-palette"></i>
    </button>
    <!-- Theme Toggle Button END -->
    <!-- Content Wrapper START -->
    <div class="main-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-lg-3">
                    <div class="card">
                        <div class="card-block">
                            <div class="inline-block">
                                <h1 class="no-mrg-vertical">$168.90</h1>
                                <p>本月總儲值</p>
                            </div>
                            <div class="pdd-top-25 inline-block pull-right">
                                <span class="label label-success label-lg mrg-left-5">+18%</span>
                            </div>
                            <div class="mrg-top-25">
                                <div id="bar-config"></div>
                            </div>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-block">
                            <p class="mrg-btm-5">本季 (1-3月)</p>
                            <h1 class="no-mrg-vertical font-size-35">$3,936<b class="font-size-16">.80</b></h1>
                            <p class="text-semibold">總營收</p>
                            <div class="mrg-top-10">
                                <h2 class="no-mrg-btm">88</h2>
                                <span class="inline-block mrg-btm-10 font-size-13 text-semibold">儲值 (會員購點)</span>                                            
                            </div>
                            <div class="mrg-top-10">
                                <h2 class="no-mrg-btm">88</h2>
                                <span class="inline-block mrg-btm-10 font-size-13 text-semibold">點數 (轉入遊戲)</span>
                                <span class="pull-right pdd-right-10 font-size-13">70%</span>
                                <div class="progress progress-success">
                                    <div class="progress-bar" role="progressbar" aria-valuenow="70" aria-valuemin="0" aria-valuemax="100" style="width:70%">
                                    </div>
                                </div>
                            </div>
                            <div class="mrg-top-10">
                                <h2 class="no-mrg-btm">69</h2>
                                <span class="inline-block mrg-btm-10 font-size-13 text-semibold">投注 (遊戲中)</span>
                                <span class="pull-right pdd-right-10 font-size-13">50%</span>
                                <div class="progress progress-warning">
                                    <div class="progress-bar" role="progressbar" aria-valuenow="50" aria-valuemin="0" aria-valuemax="100" style="width:50%">
                                    </div>
                                </div>
                            </div>
                            <div class="mrg-top-10">
                                <h2 class="no-mrg-btm">69</h2>
                                <span class="inline-block mrg-btm-10 font-size-13 text-semibold">總贏點數 (遊戲中)</span>
                                <span class="pull-right pdd-right-10 font-size-13">50%</span>
                                <div class="progress progress-danger">
                                    <div class="progress-bar" role="progressbar" aria-valuenow="50" aria-valuemin="0" aria-valuemax="100" style="width:50%">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-9">
                    <div class="card">                                    
                        <div class="card-block">
                            <p class="mrg-btm-5">本季國家投注 (1-3月)</p>                                        
                        </div>
                        <div class="main-content">
                            <div class="full-container">
                                <div class="maps map-fs">
                                    <div id="vector-map-opt">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-lg-7 col-md-12">
                    <div class="widget card">
                        <div class="card-block">
                            <h5 class="card-title">本季(1-3月) - 投注額排名前 10 遊戲</h5>
                            <div class="row mrg-top-30">
                                <div class="col-md-3 col-sm-6 col-6 border right border-hide-md">
                                    <div class="text-center pdd-vertical-10">
                                        <h2 class="font-primary no-mrg-top">$1,730</h2>
                                        <p class="no-mrg-btm">AG/遊戲A</p>
                                    </div>
                                </div>
                                <div class="col-md-3 col-sm-6 col-6 border right border-hide-md">
                                    <div class="text-center pdd-vertical-10">
                                        <h2 class="font-primary no-mrg-top">$1,730</h2>
                                        <p class="no-mrg-btm">MG/遊戲B</p>
                                    </div>
                                </div>
                                <div class="col-md-3 col-sm-6 col-6 border right border-hide-md">
                                    <div class="text-center pdd-vertical-10">
                                        <h2 class="font-primary no-mrg-top">$1,730</h2>
                                        <p class="no-mrg-btm">MG/遊戲C</p>
                                    </div>
                                </div>
                                <div class="col-md-3 col-sm-6 col-6 border right border-hide-md">
                                    <div class="text-center pdd-vertical-10">
                                        <h2 class="font-primary no-mrg-top">$1,730</h2>
                                        <p class="no-mrg-btm">AG/遊戲D</p>
                                    </div>
                                </div>
                                <div class="col-md-3 col-sm-6 col-6 border right border-hide-md">
                                    <div class="text-center pdd-vertical-10">
                                        <h2 class="font-primary no-mrg-top">$1,730</h2>
                                        <p class="no-mrg-btm">MG/遊戲E</p>
                                    </div>
                                </div>
                                <div class="col-md-3 col-sm-6 col-6 border right border-hide-md">
                                    <div class="text-center pdd-vertical-10">
                                        <h2 class="font-primary no-mrg-top">$1,730</h2>
                                        <p class="no-mrg-btm">AG/遊戲F</p>
                                    </div>
                                </div>
                                <div class="col-md-3 col-sm-6 col-6 border right border-hide-md">
                                    <div class="text-center pdd-vertical-10">
                                        <h2 class="font-primary no-mrg-top">$1,730</h2>
                                        <p class="no-mrg-btm">MG/遊戲G</p>
                                    </div>
                                </div>
                                <div class="col-md-3 col-sm-6 col-6 border right border-hide-md">
                                    <div class="text-center pdd-vertical-10">
                                        <h2 class="font-primary no-mrg-top">$1,730</h2>
                                        <p class="no-mrg-btm">MG/遊戲H</p>
                                    </div>
                                </div>
                                <div class="col-md-3 col-sm-6 col-6 border right border-hide-md">
                                    <div class="text-center pdd-vertical-10">
                                        <h2 class="font-primary no-mrg-top">$1,730</h2>
                                        <p class="no-mrg-btm">AG/遊戲I</p>
                                    </div>
                                </div>
                                <div class="col-md-3 col-sm-6 col-6 border right border-hide-md">
                                    <div class="text-center pdd-vertical-10">
                                        <h2 class="font-primary no-mrg-top">$1,730</h2>
                                        <p class="no-mrg-btm">AG/遊戲J</p>
                                    </div>
                                </div>
                            </div>                                        
                        </div>
                    </div>
                </div>
                <div class="col-lg-5 col-md-12">
                    <div class="card">
                        <div class="card-heading">
                            <h4 class="card-title inline-block pdd-top-5">本季(1-3月) - 投注額排名前 10 營運商</h4>
                            <a href="" class="btn btn-default pull-right no-mrg">查看全部排名</a>
                        </div>
                        <div class="pdd-horizon-20 pdd-vertical-5">
                            <div class="overflow-y-auto relative scrollable" style="max-height: 381px">
                                <table class="table table-lg table-hover">
                                    <tbody>
                                        <tr>
                                            <td>
                                                <div class="list-info">
                                                    <img class="thumb-img" src="assets/images/avatars/thumb-1.jpg" alt="">
                                                    <div class="info">
                                                        <span class="title">營運商A</span>
                                                        <span class="sub-title">ID 863</span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-10">
                                                    <span>1</span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="relative mrg-top-10">
                                                    <div class="no-mrg-vertical">$168.90</div>
                                                </div>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td>
                                                <div class="list-info">
                                                    <img class="thumb-img" src="assets/images/avatars/thumb-4.jpg" alt="">
                                                    <div class="info">
                                                        <span class="title">營運商B</span>
                                                        <span class="sub-title">ID 868</span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-10">
                                                    <span>2</span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="relative mrg-top-10">
                                                    <div class="no-mrg-vertical">$168.90</div>
                                                </div>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td>
                                                <div class="list-info">
                                                    <img class="thumb-img" src="assets/images/avatars/thumb-5.jpg" alt="">
                                                    <div class="info">
                                                        <span class="title">營運商C</span>
                                                        <span class="sub-title">ID 860</span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-10">
                                                    <span>3</span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="relative mrg-top-10">
                                                    <div class="no-mrg-vertical">$168.90</div>
                                                </div>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td>
                                                <div class="list-info">
                                                    <img class="thumb-img" src="assets/images/avatars/thumb-6.jpg" alt="">
                                                    <div class="info">
                                                        <span class="title">營運商D</span>
                                                        <span class="sub-title">ID 861</span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-10">
                                                    <span>4</span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="relative mrg-top-10">
                                                    <div class="no-mrg-vertical">$168.90</div>
                                                </div>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td>
                                                <div class="list-info">
                                                    <img class="thumb-img" src="assets/images/avatars/thumb-7.jpg" alt="">
                                                    <div class="info">
                                                        <span class="title">營運商E</span>
                                                        <span class="sub-title">ID 862</span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-10">
                                                    <span>5</span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="relative mrg-top-10">
                                                    <div class="no-mrg-vertical">$168.90</div>
                                                </div>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td>
                                                <div class="list-info">
                                                    <img class="thumb-img" src="assets/images/avatars/thumb-7.jpg" alt="">
                                                    <div class="info">
                                                        <span class="title">營運商F</span>
                                                        <span class="sub-title">ID 862</span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-10">
                                                    <span>6</span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="relative mrg-top-10">
                                                    <div class="no-mrg-vertical">$168.90</div>
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
require_once '_bottom.php';
?>
</div>
<!-- Page Container END -->
<?php
require_once '_footer.php';
?>