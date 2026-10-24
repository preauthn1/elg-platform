<?php
$token = [REDACTED]'token']) ? $_GET['token'] : '';
$id = isset($_GET['id']) ? $_GET['id'] : '';

$data = array();
$data['event_id'] = $id;
$result = curlPost('api/getPrize', $data, $token);
if ($result['code'] == 1000) {
    $data = $result['data'];
} else {
    echo '請先取得刮刮卡';
    exit();
}
if (! isset($data['effective'])) {
    echo '請先取得抽獎機會';
    exit();
} else {
    $count = count($data['effective']);
}

// 加密
function encrypt($data)
{
    $cryptText = openssl_encrypt($data, "aes-256-cbc", md5(env('API_AES_KEY')), OPENSSL_RAW_DATA, env('API_IV'));
    return base64_encode($cryptText);
}

function curlPost($url, $data = '', $token)
{
    $post = array();
    $post['params'] = encrypt(json_encode($data));
    $post['key'] = md5($post['params'] . env('API_MD5_KEY'));
    $ch = curl_init();
    if ($_SERVER['HTTP_HOST'] == 'localhost') {
        curl_setopt($ch, CURLOPT_URL, 'http://localhost/frontend/public/' . $url);
    } else {
        curl_setopt($ch, CURLOPT_URL, 'https://example.invalid/' . $url);
    }
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $header = array();
    $header[] = "Authorization: Bearer " . $token;
    $language = isset($_SESSION['language']) ? $_SESSION['language'] : 'en';
    $header[] = "Accept-Language: " . $language;
    curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
    $result = curl_exec($ch);
    curl_close($ch);
    return json_decode($result, true);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<title><?=$data['name']?></title>
<style>
* { margin: 0; padding: 0;}
.dowebok { position: relative; width: 700px; height: 438px; margin: 0 auto;}
.dowebok img { position: absolute; left: 0; top: 0;}
#robot { z-index: 1;}
#redux { position: absolute; left: 0; top: 0; z-index: 2;}
#progress { position: absolute; right: 10px; top: 10px; z-index: 3; color: #fff;}
</style>
</head>

<body background="<?=$data['background']?>">
<h1><?=$data['name']?></h1>
<h3 style="text-align:center">剩餘遊戲次數：<?=$count?></h3>
<div class="dowebok">
	<div style="text-align: center;font-size: 72px;background-color:white;width:650px;line-height:400px;top:180px"><?=$data['prize']['name']?></div>
	<img id="redux" src="images/scratcher.jpg" alt="">
</div>
<script src="js/jquery-1.8.3.min.js"></script>
<script src="js/jquery.eraser.js"></script>
<script>
$(function(){
	var is_update = false;
	$('#redux').eraser({
		progressFunction: function(p){
			if(Math.round(p*100) > 25){
				$('#redux').eraser('clear');
			}
			if(!is_update){
     		 	is_update = true;
     		 	$.post("ajax.php",{"id":"<?=$data['prize']['prize_member']?>","token":"<?=$_GET['token']?>"}, function(data) {
     	 		 console.log(data);
     			},'json');
			}
// 			console.log(Math.round(p*100));
			//$('#progress').html(Math.round(p*100) + '%');
		}
	});

	$('.reset').on('click', function(){
		$('#redux').eraser('reset');
	});

	$('.clear').on('click', function(){
		$('#redux').eraser('clear');
	});
});
</script>

<style>
* { margin: 0; padding: 0;}
body { font-family: Consolas,arial,"宋体";}
h1 { width: 900px; margin: 40px auto; font: 32px "Microsoft Yahei"; text-align: center;}
.explain, .dowebok-explain { margin-top: 20px; font-size: 14px; text-align: center; color: #f50;}

.vad { margin: 50px 0 5px; font-family: Consolas,arial,宋体; text-align:center;}
.vad a { display: inline-block; height: 36px; line-height: 36px; margin: 0 5px; padding: 0 50px; font-size: 14px; text-align:center; color:#eee; text-decoration: none; background-color: #222;}
.vad a:hover { color: #fff; background-color: #000;}
</style>

</body>
</html>