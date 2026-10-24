<?php

declare(strict_types=1);

use App\Helpers\Common;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * 掛載於 agent/external/frontend 三個 API 面向，供 target/tickets#4187 IP 偽造測試用
 * curl -H "X-Forwarded-For: 0.0.0.0" https://{host}/{area}/api/detect_ip
 *
 * 參考 gsrc/WEB_AgentBackend/_release/login_detect_ip.php：除了目前 getIp() 的
 * 解析結果，同時列出各家候選 header 原始值與完整 header 清單，方便比對哪個
 * header 被 CDN／LB 覆寫或濾掉。
 *
 * 路徑不帶 .php：正式環境 nginx 的 `location ~ \.php$` 沒有 try_files 導回
 * index.php 的 fallback，會直接把不存在的實體檔案丟給 PHP-FPM 找檔案，
 * 回「File not found.」，永遠進不到 Laravel 路由層（本機 docker-compose 的
 * marker-file nginx template 恰好有這個 fallback，才會在本機誤以為正常）。
 */
Route::get('detect_ip', function (Request $request) {
    $candidateHeaders = [
        'REMOTE_ADDR (直接連線 IP)' => $request->server('REMOTE_ADDR') ?? '未偵測到',
        'X-Forwarded-For (完整鏈)' => $request->header('X-Forwarded-For') ?? '未偵測到',
        'CF-Connecting-IP' => $request->header('CF-Connecting-IP') ?? '未偵測到',
        'Ali-CDN-Real-IP' => $request->header('Ali-CDN-Real-IP') ?? '未偵測到',
        'Client-IP' => $request->header('Client-IP') ?? '未偵測到',
        'True-Client-IP' => $request->header('True-Client-IP') ?? '未偵測到',
        'V-HTTP-CLIENT-IP' => $request->header('V-HTTP-CLIENT-IP') ?? '未偵測到',
        'getIp() 解析結果' => Common::getIp(),
        'resolveClientIp() 解析結果' => Common::resolveClientIp(),
        'TRUSTED_PROXY_SKIP_LAST_HOP' => config('trustedproxy.skip_last_hop') ? 'true' : 'false',
        'TRUSTED_PROXY_EXTRA_CIDRS' => (function () {
            $extraCidrs = (array) config('trustedproxy.extra_cidrs', []);

            return $extraCidrs === [] ? '(空)' : implode(', ', $extraCidrs);
        })(),
    ];

    $lines = [];
    $lines[] = str_repeat('=', 50);
    $lines[] = '           核心 IP 欄位檢查 (您的測試欄位)         ';
    $lines[] = str_repeat('=', 50);

    foreach ($candidateHeaders as $label => $value) {
        $lines[] = "{$label}: {$value}";
    }

    $lines[] = '';
    $lines[] = str_repeat('=', 50);
    $lines[] = '         當前請求的全部 HTTP Headers 列表          ';
    $lines[] = str_repeat('=', 50);

    $headers = $request->headers->all();
    ksort($headers);

    foreach ($headers as $key => $values) {
        $lines[] = sprintf('%-30s : %s', $key, implode(', ', $values));
    }

    $lines[] = str_repeat('=', 50);

    return response(implode("\n", $lines), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
});
