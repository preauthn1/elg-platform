<?php
$id = isset($_GET['id']) ? $_GET['id'] : 1;
$amount = isset($_GET['amount']) ? $_GET['amount'] : 100;
?>
<html lang="en"><head>
<meta charset="UTF-8">
<title> Payment Form </title>
<link rel="stylesheet" href="css/reset.min.css">
<link rel="stylesheet" href="css/bootstrap.min.css">
<link rel="stylesheet" href="css/font-awesome.min.css">
<style>
      body { margin:50px auto; width:600px;}

/* CSS for Credit Card Payment form */
.credit-card-box .panel-title {
    display: inline;
    font-weight: bold;
}
.credit-card-box .form-control.error {
    border-color: red;
    outline: 0;
    box-shadow: inset 0 1px 1px rgba(0,0,0,0.075),0 0 8px rgba(255,0,0,0.6);
}
.credit-card-box label.error {
  font-weight: bold;
  color: red;
  padding: 2px 8px;
  margin-top: 2px;
}
.credit-card-box .payment-errors {
  font-weight: bold;
  color: red;
  padding: 2px 8px;
  margin-top: 2px;
}
.credit-card-box label {
    display: block;
}
/* The old "center div vertically" hack */
.credit-card-box .display-table {
    display: table;
}
.credit-card-box .display-tr {
    display: table-row;
}
.credit-card-box .display-td {
    display: table-cell;
    vertical-align: middle;
    width: 50%;
}
/* Just looks nicer */
.credit-card-box .panel-heading img {
    min-width: 180px;
}
    </style>
<script>
  window.console = window.console || function(t) {};
</script>
<script>
  if (document.location.search.match(/type=embed/gi)) {
    window.parent.postMessage("resize", "*");
  }
</script>
</head>
<body translate="no">
<div class="container">
<div class="row">

<div class="col-xs-12 col-md-4">

<div class="panel panel-default credit-card-box">
<div class="panel-heading display-table">
<div class="row display-tr">
<h3 class="panel-title display-td">Payment Details</h3>
<div class="display-td">
<img class="img-responsive pull-right" src="images/accepted_c22e0.png">
</div>
</div>
</div>
<div class="panel-body">
<form role="form" id="payment-form">

<div class="row">
<div class="col-xs-12">
<div class="form-group">
<label for="cardNumber">Payment amount</label>
<div class="input-group">
$ <?=$amount?>
</div>
</div>
</div>
</div>

<div class="row">
<div class="col-xs-12">
<div class="form-group">
<label for="cardNumber">CARD NUMBER</label>
<div class="input-group">
<input type="tel" class="form-control" name="cardNumber" placeholder="Valid Card Number" autocomplete="cc-number" required="" autofocus="" value="4485 0837 3972 4773">
<span class="input-group-addon"><i class="fa fa-credit-card"></i></span>
</div>
</div>
</div>
</div>

<div class="row">
<div class="col-xs-7 col-md-7">
<div class="form-group">
<label for="cardExpiry"><span class="hidden-xs">EXPIRATION</span><span class="visible-xs-inline">EXP</span> DATE</label>
<input type="tel" class="form-control" name="cardExpiry" placeholder="MM / YY" autocomplete="cc-exp" required="" value="22/07">
</div>
</div>
<div class="col-xs-5 col-md-5 pull-right">
<div class="form-group">
<label for="cardCVC">CV CODE</label>
<input type="tel" class="form-control" name="cardCVC" placeholder="CVC" autocomplete="cc-csc" required="" value="759">
</div>
</div>
</div>
<div class="row">
<div class="col-xs-12">
<button class="btn btn-success btn-lg btn-block" type="submit" id="submit">Start Subscription</button>
</div>
</div>
<div class="row" style="display:none;">
<div class="col-xs-12">
<p class="payment-errors"></p>
</div>
</div>
</form>
</div>
</div>

</div>
</div>
</div>
<script src="js/jquery-3.4.1.min.js"></script>
<script src="js/jquery.validate.min.js"></script>
<script src="js/jquery.payment.min.js"></script>
<script>

$( document ).ready(function() {
	$( "#submit" ).click(function(e) {
		if(e.preventDefault){
			e.preventDefault();
		}else{
			window.event.returnValue == false;
		}

		//$.get("http://localhost/frontend/public/order/update/<?=$id?>", function(data) { 
		$.get("https://example.invalid/order/update/<?=$id?>", function(data) { 
			 if(data.code == 1000){
			window.location = data.data;
			 }else{
			 alert(data.message);
			 }
			 console.log(data);
			 },'json');
	});
});

</script>


