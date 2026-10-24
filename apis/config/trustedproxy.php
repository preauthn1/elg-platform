<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | 信任的反向代理來源（#3967 B2，配合 Horizon IP 白名單）。逗號分隔的
    | IP / CIDR 清單，或 '*'（信任所有 hop；X-Forwarded-For 可被偽造，
    | 僅限 dev 使用）。空值（預設）＝不信任任何 proxy，與未設定時行為
    | 完全一致。
    |
    | 不在 bootstrap/app.php 呼叫 $middleware->trustProxies()：該 callback
    | 於 HTTP kernel resolve 時執行，早於 .env / config 載入（已實測），
    | env() 在該處讀不到掛載的 .env 值。Laravel 內建 TrustProxies
    | middleware（全域 stack 預設啟用）會在每個 request 於未經
    | trustProxies() 靜態設定時 fallback 讀取 config('trustedproxy.proxies')，
    | 走 config 亦相容 config:cache。
    |
    */

    'proxies' => env('TRUSTED_PROXIES') ?: null,

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxy CIDR 資料檔（target/tickets#4187）
    |--------------------------------------------------------------------------
    |
    | Common::resolveClientIp() 讀取的信任 CIDR 資料檔路徑。這份 JSON 由
    | `trusted-proxies:sync` 指令重新抓取（Cloudflare 官方即時清單等）後
    | 產生並 commit 進 git——跟 gsrc/WEB_AgentBackend/_release/trusted_proxies.php
    | 同一種定位：平時是靜態、已 commit 的資料，需要更新時才手動跑一次指令、
    | 檢查 diff 後 commit。不存在時 Common::resolveClientIp() 視為沒有任何
    | 可信代理（fail-closed，一律只信任 REMOTE_ADDR）。
    |
    */

    'data_path' => storage_path('app/trusted-proxies.json'),

    // 品牌專屬額外信任清單：部分品牌會遇到只屬於自己網域的固定代理 IP
    // （例如某品牌的 Google LB VIP 不在通用的健康檢查範圍內），由該品牌的
    // .env 填入 TRUSTED_PROXY_EXTRA_CIDRS（逗號分隔），不污染其他品牌共用
    // 的信任範圍。
    'extra_cidrs' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TRUSTED_PROXY_EXTRA_CIDRS', ''))
    ))),

    // 是否無條件跳過 X-Forwarded-For 最後一跳（Google Cloud LB 自帶、每個
    // 網域各異又無法枚舉的 VIP）。只對已驗證屬於 GKE + Google Cloud LB
    // 拓樸的品牌開啟，其他拓樸啟用可能讓攻擊者偽造的值被誤判為真實 IP。
    'skip_last_hop' => (bool) env('TRUSTED_PROXY_SKIP_LAST_HOP', false),

];
