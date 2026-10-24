<?php
// 與 apis DecryptRequest / AesService 相同的解法：驗 key = md5(params . MD5)，再 aes-256-cbc 解密。
[$script, $params, $key, $aesKey, $iv, $md5Key] = $argv + [null, null, null, null, null, null];
$keyOk = $key === md5($params . $md5Key);
$plain = openssl_decrypt(base64_decode($params), 'aes-256-cbc', md5($aesKey), OPENSSL_RAW_DATA, $iv);
echo json_encode(['key_ok' => $keyOk, 'plain' => $plain], JSON_UNESCAPED_UNICODE);
