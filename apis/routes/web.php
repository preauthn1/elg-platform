<?php

declare(strict_types=1);

use App\Http\Controllers\Olympus\AzureAuthController;
use App\Http\Middleware\ReportDocsAccess;
use Dedoc\Scramble\Scramble;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 | Olympus 後台 Office 365 SSO。實例分流與 AdminPanelProvider::register() 一致（僅 Agent_API 與本機）。
 | 必須註冊在 Filament panel 之外，否則 panel 的 catch-all 有機會吃掉 callback
 |（php artisan route:list --path=olympus 可驗證順序）。
 */
if (in_array(config('app.name'), ['Laravel', 'Agent_API'], true)) {
    Route::prefix('olympus/auth')->name('olympus.azure.')->group(function () {
        Route::get('redirect', [AzureAuthController::class, 'redirect'])->name('redirect');
        Route::get('callback', [AzureAuthController::class, 'callback'])->name('callback');
    });
}

/*
 | 內部報告中心（docs/reports/*.html）。報告含金流安全稽核資料，沿用 Scramble 開關把關：
 | API 文件可見的環境（本機預設 / dev2 等 SCRAMBLE_ENABLED=true）即可瀏覽，正式環境一律 abort。
 */
Route::middleware(ReportDocsAccess::class)->prefix('docs/reports')->group(function () {
    /** 已知報告中介資料：檔名（不含 .html）→ 標題 / 說明 / 圖示 / 重生指令。 */
    $registry = [
        'payment-providers-report' => [
            'title' => '金流商完整盤點',
            'desc' => '對接金流商清單、跨白標啟用矩陣、回調邏輯與安全風險旗標。',
            'icon' => 'card',
            'command' => 'php artisan report:payment-providers',
        ],
        'deposit-status-lifecycle-report' => [
            'title' => '代收訂單 status 生命週期',
            'desc' => '代收（存款）訂單各 status 的流轉規範與生命週期說明，附 file:line 追蹤。',
            'icon' => 'in',
            'command' => 'report-order-status-lifecycle skill',
        ],
        'withdrawal-status-lifecycle-report' => [
            'title' => '代付訂單 status 生命週期',
            'desc' => '正式版・線上現況。代付（提款）訂單各 status 的流轉規範與生命週期說明，附 file:line 追蹤；已含 #4137 代付事務邊界改造（23 廢除、結果未知停留 21、29 移出回調放行），該批於 2026-08-20 隨 release_202608201117 部署六站生效。',
            'icon' => 'out',
            'command' => 'report-order-status-lifecycle skill',
        ],
        'withdrawal-status-lifecycle-target-flow' => [
            'title' => '代付事務邊界改造會議紀錄（備查）',
            'desc' => '歷史文件，不再維護。2026-08 針對 op#2831（發版終止 pod 導致代付事務回滾）的改造提案簡報：事故根因、方案取捨、目標流程與五個中斷點對照。該設計已由 #4137 實作並於 2026-08-20 上線，線上現況請看「代付訂單 status 生命週期」。',
            'icon' => 'out',
            'command' => null,
        ],
        'payment-risk-findings' => [
            'title' => '金流商風險發現',
            'desc' => '逐家稽核金流商發現的代收／代付缺陷：依問題或依金流商檢視，含嚴重度、程式位置、修法與處理狀態（待處理／已開單／拍板不修／已修）。資料來源 docs/reports/data/payment-risk-findings.json。',
            'icon' => 'doc',
            'command' => 'report-payment-audit skill（產報告）',
        ],
        'multi-currency-wallet-pm-brief' => [
            'title' => '多幣別錢包 PM 摘要',
            'desc' => '多幣別錢包案對外的現行版：給 PM 的一頁摘要與待決題，標有會議結論。技術細節與決議以 docs/plans/2026-08-13-multi-currency-wallet/ 的決議清單與 roadmap 為準。',
            'icon' => 'card',
            'command' => 'docs/plans/2026-08-13-multi-currency-wallet/',
        ],
        'multi-currency-wallet-target-flow' => [
            'title' => '多幣別錢包目標架構（歷史會審材料）',
            'desc' => '歷史文件，不再維護，內容停在 09-14 版。會員多幣別錢包的目標流程與六期實作計劃：現況實證、切換幣別、中央錢包搬遷、遊戲中的幣別歸屬、注單與分潤鏈路傳播、TBR／SBR 冷熱分離。現行決議以決議清單 multi-currency-wallet-decisions.md 為準，對外摘要看「多幣別錢包 PM 摘要」。',
            'icon' => 'card',
            'command' => null,
        ],
        'stored-procedure-inventory' => [
            'title' => 'Stored Procedure 全貌盤點',
            'desc' => '單一站點的 08-19 快照，數字不隨程式更新。351 支 SP 的使用現況與改寫評估：48 支排程驅動、8 個 repo 應用層呼叫來源、複雜度與改寫成本指標（游標 76／迴圈 103／僅 11 支有交易控制），含死代碼候選與技術債樣態。之後已有部分 SP 改由 PHP 排程承接，現況以 routes/console.php 為準。內部討論用。',
            'icon' => 'doc',
            'command' => null,
        ],
        'sr-report-inventory' => [
            'title' => 'SR 報表盤點',
            'desc' => 'SR 系列報表的用途、依據資料表與多幣別改造影響：35＋ 支路由跨 4 支 Controller、28 支讀 TBR／SBR，含風險五級分級、資料表反查清單與死代碼等意外發現。多幣別錢包案的下游影響盤點，內部討論用。',
            'icon' => 'doc',
            'command' => null,
        ],
        'sbr-summary-queue-redesign' => [
            'title' => 'SBR 整點彙總 Queue 化改造（備查）',
            'desc' => '歷史文件，不再維護。target/tickets#4257 改造的新舊設計對照簡報；頁內「審查中・未部署」為當時狀態，該改造已合併上線。現行機制見 app/Jobs/SummarizeBetRecordHour.php 的 docblock。報告設計規範拿它當設計對照簡報型範本。',
            'icon' => 'doc',
            'command' => null,
        ],
    ];

    Route::get('/', function () use ($registry) {
        $reports = [];
        foreach ($registry as $name => $meta) {
            $path = base_path("docs/reports/{$name}.html");
            $exists = File::exists($path);
            $reports[] = [
                ...$meta,
                'name' => $name,
                'exists' => $exists,
                'mtime' => $exists ? date('Y-m-d H:i', File::lastModified($path)) : null,
            ];
        }

        // 補上 registry 未登記、但實際存在的 HTML（未知報告）。
        foreach (File::glob(base_path('docs/reports/*.html')) as $path) {
            $name = basename($path, '.html');
            if (isset($registry[$name])) {
                continue;
            }
            $reports[] = [
                'title' => $name,
                'desc' => '未登記於 registry 的報告。',
                'icon' => 'doc',
                'command' => null,
                'name' => $name,
                'exists' => true,
                'mtime' => date('Y-m-d H:i', File::lastModified($path)),
            ];
        }

        return view('reports.index', ['reports' => $reports]);
    })->name('reports.index');

    // 報告共用樣式等靜態資產（docs/reports/assets/*.css），供各報告以相對路徑 assets/*.css 引用。
    Route::get('/assets/{file}', function (string $file) {
        $path = base_path("docs/reports/assets/{$file}");
        abort_unless(File::exists($path), 404);

        return response(File::get($path))->header('Content-Type', 'text/css; charset=UTF-8');
    })->where('file', '[A-Za-z0-9_-]+\.css')->name('reports.assets');

    Route::get('/{name}', function (string $name) {
        $path = base_path("docs/reports/{$name}.html");
        abort_unless(File::exists($path), 404);

        // 注入「回報告中心」浮動鈕，讓所有報告經路由瀏覽時都能返回索引。
        $back = '<a href="' . route('reports.index') . '" style="position:fixed;bottom:24px;right:24px;z-index:9999;'
            . 'display:inline-flex;align-items:center;gap:7px;padding:11px 18px;border-radius:30px;text-decoration:none;'
            . 'font:700 13px/1 \'Public Sans\',-apple-system,BlinkMacSystemFont,sans-serif;color:#fff;'
            . 'background:linear-gradient(118deg,#5A8DEE,rgba(90,141,238,.85));box-shadow:0 6px 20px rgba(90,141,238,.55)">'
            . '← 報告中心</a>';
        $html = File::get($path);
        $html = str_contains($html, '</body>')
            ? str_replace('</body>', $back . '</body>', $html)
            : $html . $back;

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
    })->where('name', '[A-Za-z0-9_-]+')->name('reports.show');
});

if (app()->bound('scramble.boot.ready')) {
    Scramble::registerUiRoute('docs/frontend', api: 'frontend');
    Scramble::registerJsonSpecificationRoute('docs/frontend/api.json', api: 'frontend');

    Scramble::registerUiRoute('docs/agent', api: 'agent');
    Scramble::registerJsonSpecificationRoute('docs/agent/api.json', api: 'agent');

    Scramble::registerUiRoute('docs/external', api: 'external');
    Scramble::registerJsonSpecificationRoute('docs/external/api.json', api: 'external');
}
