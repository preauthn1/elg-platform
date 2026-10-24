<?php
$version = "1.0.4"; //如果某些css檔案 需要避免緩存 請修改版號

$env = strtolower(RUN_ENV);
$isDSG = $env == "dsg";

$extraStyle = $isDSG ? "header-dark side-nav-dark" : "";
$extraText = "";

if($env == "dev"){
    $extraText = "(Dev)";    
} else if($env == "local"){
    $extraText = "(本地環境)";    
} else if($env == "dsg"){
    $extraText = "DSG -";    
}

?>

<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport"
        content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, shrink-to-fit=no">
    <title><?= $extraText ?> Agent management</title>
    <!-- Favicon -->
    <link rel="shortcut icon" href="<?=BASE_URL?>assets/images/logo/favicon.png">
    <!-- plugins css -->
    <link rel="stylesheet" href="<?=BASE_URL?>assets/vendors/bootstrap/dist/css/bootstrap.css" />
    <link rel="stylesheet" href="<?=BASE_URL?>assets/vendors/PACE/themes/blue/pace-theme-minimal.css" />
    <link rel="stylesheet" href="<?=BASE_URL?>assets/vendors/perfect-scrollbar/css/perfect-scrollbar.min.css" />
    <!-- page plugins css -->
    <link rel="stylesheet" href="<?=BASE_URL?>assets/vendors/bower-jvectormap/jquery-jvectormap-1.2.2.css" />
    <link rel="stylesheet" href="<?=BASE_URL?>assets/vendors/nvd3/build/nv.d3.min.css" />
    <link rel="stylesheet" href="<?=BASE_URL?>assets/vendors/selectize/dist/css/selectize.default-0.13.0.css" />
    <link rel="stylesheet" href="<?=BASE_URL?>assets/vendors/bootstrap-daterangepicker/daterangepicker.css" />
    <link rel="stylesheet" href="<?=BASE_URL?>assets/vendors/bootstrap-datepicker/dist/css/bootstrap-datepicker3.css" />
    <link rel="stylesheet" href="<?=BASE_URL?>assets/vendors/bootstrap-toggle/css/bootstrap-toggle.min.css" />
    <link rel="stylesheet" href="<?=BASE_URL?>assets/vendors/summernote/dist/summernote.css" />
    <link rel="stylesheet" href="<?=BASE_URL?>assets/vendors/nvd3/build/nv.d3.min.css" />
    <link rel="stylesheet" href="<?=BASE_URL?>assets/vendors/datatables/media/css/jquery.dataTables.css" />
    <link rel="stylesheet" href="<?=BASE_URL?>assets/css/sweetalert2/10.9.0/sweetalert2.min.css" />
    <!-- core css -->
    <link href="<?=BASE_URL?>assets/css/ei-icon.css" rel="stylesheet">
    <link href="<?=BASE_URL?>assets/css/themify-icons.css" rel="stylesheet">
    <link href="<?=BASE_URL?>assets/css/font-awesome.min.css" rel="stylesheet">
    <link href="<?=BASE_URL?>assets/css/animate.min.css" rel="stylesheet">
    <link href="<?=BASE_URL?>assets/css/app.css?v=<?= $version ?>" rel="stylesheet">

    <script src="<?=BASE_URL?>assets/js/sweetalert2/10.9.0/sweetalert2.min.js"></script>
    <script src="<?=BASE_URL?>assets/js/core-js/core.js"></script>
    <script src="<?=BASE_URL?>assets/js/vendor.js"></script>
    <script src="<?=BASE_URL?>assets/js/app.min.js"></script>
</head>

<body>
    <div class="app <?= $extraStyle ?>">
        <div class="layout">