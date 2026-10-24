<?php
$token = [REDACTED]'token']) ? $_GET['token'] : '';
$id = isset($_GET['id']) ? $_GET['id'] : '';

$data = array();
$data['event_id'] = $id;
$result = curlPost('api/getPrize', $data, $token);
if ($result['code'] == 1000) {
    $data = $result['data'];
} else {
    echo '請先取得抽獎機會';
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
<!doctype html>
<html lang="zh">
<head>
	<meta charset="UTF-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1"> 
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>輪盤</title>
	<link rel="stylesheet" type="text/css" href="css/normalize.css" />
	<link rel="stylesheet" type="text/css" href="css/default.css">
</head>
<body  background="<?=$data['background']?>">
	<div class="htmleaf-container">
	<header class="htmleaf-header">
			<h1>幸運大輪盤</h1>
			</br>
			<div>
				剩餘遊戲次數：<span id="count"><?=$count?></span>			　
			</div>
			</br>
			<div id="result">
			　
			</div>
		</header>

		<div class="htmleaf-content " >
			<canvas id="canvas" width="500" height="500"></canvas>
		</div>
	</div>
	
	<script type="text/javascript" src="js/jquery-1.11.0.min.js"></script>
	<script type="text/javascript" src="js/jquery-ui.min.js"></script>
	<script type="text/javascript" src="src/rouletteWheel.js?<?=date('U') . rand(0, 10000)?>"></script>
	<script type="text/javascript">
		$(function(){
			var items = {};
			<?php
foreach ($data['prizes'] as $row) {
    ?>
			items["<?=$row['id']?>"]="<?=$row['name']?>";
			<?php
}
?>
			
		 $('#canvas').rouletteWheel({
		    items : items,
		    selected : function(key, value){
// 				$('#result').html('本次獎勵為：'+value);
				$.post("ajax.php",{"id":"<?=$data['prize']['prize_member']?>","token":"<?=$_GET['token']?>","content_id":key}, function(data) {
					alert('本次獎勵為：'+value);
					window.location.reload();
// 					var count =$('#count').html();
// 					$('#count').html(parseInt(count)-1);
	     			},'json');
		    },
		    spinText : '開始抽獎',
		  });

		});
	</script>
</body>
</html>