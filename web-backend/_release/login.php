<?php
require_once '_init.php';
require_once '_header.php';
?>
    <div class="app">
        <div class="authentication">
            <div class="sign-in">
                <div class="row no-mrg-horizon">
                    <div class="col-md-8 no-pdd-horizon d-none d-md-block">
                        <div class="full-height bg" style="background-image: url('assets/images/others/img-29.jpg')">
                        </div>
                    </div>
                    <div class="col-md-4 no-pdd-horizon">
                        <div class="full-height bg-white height-100">
                            <div class="vertical-align full-height pdd-horizon-70">
                                <div class="table-cell">
                                    <div class="pdd-horizon-15">
                                        <h2><?=$lang['login']?></h2>
                                        <p class="mrg-btm-15 font-size-13"><?=$lang['login_subtitle']?></p>
                                        <form id="data-form">
                                            <div class="form-group">
                                                <input type="text" name="username" class="form-control" placeholder="<?=$lang['username']?>">
                                            </div>
                                            <div class="form-group">
                                                <input type="password" name="password" class="form-control" placeholder="<?=$lang['password']?>">
                                            </div>
                                            <div class="checkbox font-size-12">
                                                <input id="agreement" name="agreement" type="checkbox">
                                                <label for="agreement">Keep Me Signed In</label>
                                            </div>
                                            <input type="hidden" name="action" value='login' >
                                            <button id="login" class="btn btn-info"><?=$lang['login']?></button>
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
<?php
require_once '_footer.php';
?>
<script>
$( document ).ready(function() {
	$( "#login" ).click(function(e) {
		if(e.preventDefault){
			e.preventDefault();
		}else{
			window.event.returnValue == false;
		}
    	$.post( "ajax_login.php",$( "#data-form" ).serialize(), function( data ) {
        	if(data.code == 1000){
            	window.location = 'index.php';
        	}else{
            	alert(data.message);
        	}
		},'json');
	});
});
</script>