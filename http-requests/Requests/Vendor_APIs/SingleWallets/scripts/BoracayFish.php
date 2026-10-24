<?php

$url = "https://example.invalid/api/";

$data = [
    "cert"       => "534b9e19d130cd76d3eb5e44b23d2ccb",
    "currency"   => "HKD",
    "merchantid" => "tpowerhk",
    "gamecode"   => "boracayfish_1",
    "userid"     => "devBFSW00000002"
];

$signature = "";
foreach($data as $key => $value) {
    if ( empty($value) ) {
        continue;
    }
    if ( !empty($signature) ) {
        $signature .= "&";
    }
    $signature .= "$key=$value";
}

echo "Origin: " . $signature . "\n";
echo "Signature: " . md5($signature) . "\n";