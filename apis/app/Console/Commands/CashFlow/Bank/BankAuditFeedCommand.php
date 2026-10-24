<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Bank;

use App\Services\CashFlow\Bank\BankMismatchAuditor;
use Illuminate\Console\Command;

/**
 * 銀行配置稽核命令
 *
 * 【分類】Bank — 唯讀稽核工具，長期保留。
 *
 * 找出需要補 config 才能解的銀行：
 * - lost                 : DB 銀行 row 無法匹配任何 standard
 * - low_confidence_feed  : provider feed 匹配信心度 < 80
 *
 * 完全 read-only，不寫入 DB。
 *
 * 使用範例：
 *   php artisan bank:audit-feed                  # 全 5 幣別
 *   php artisan bank:audit-feed --currency=IDR   # 單一幣別
 *   php artisan bank:audit-feed --json           # JSON 輸出（給 skill 消費）
 *
 * ## 退役條件
 *
 * **不設退役條件。** `lost` 與 `low_confidence_feed` 的來源是「`standard_banks` registry
 * 還沒收錄這家銀行」或「provider feed 的名稱對不上」——只要還會開新站、接新金流商、
 * 新增銀行，這兩種缺口就會再出現，本命令是找出它們的唯一入口。
 */
class BankAuditFeedCommand extends Command
{
    protected $signature = 'bank:audit-feed
                            {--currency= : 指定幣別 (MYR|VND|SGD|INR|IDR)，省略則全部}
                            {--json : 以 JSON 輸出（給 skill 消費）}';

    protected $description = '稽核銀行配置：找出需要補 alias / keyword / entry 的條目';

    public function __construct(protected BankMismatchAuditor $auditor)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $currencies = $this->resolveCurrencies();

        if (empty($currencies)) {
            $this->error('未找到任何指定幣別');

            return self::FAILURE;
        }

        $audit = [];
        foreach ($currencies as $code => $id) {
            $audit[$code] = [
                'currency_id' => $id,
                ...$this->auditor->auditCurrency($code, $id),
            ];
        }

        if ($this->option('json')) {
            $this->renderJson($audit);
        } else {
            $this->renderText($audit);
        }

        return self::SUCCESS;
    }

    /**
     * 解析 --currency 選項，回傳 [code => id] 陣列
     *
     * @return array<string, int>
     */
    protected function resolveCurrencies(): array
    {
        $supported = $this->auditor->supportedCurrencies();
        $filter = $this->option('currency');

        if ($filter === null) {
            return $supported;
        }

        return isset($supported[$filter]) ? [$filter => $supported[$filter]] : [];
    }

    protected function renderText(array $audit): void
    {
        foreach ($audit as $currency => $data) {
            $this->info(str_repeat('=', 60));
            $this->info(sprintf('Currency: %s  (currency_id=%d)', $currency, $data['currency_id']));
            $this->info(str_repeat('=', 60));

            if (!empty($data['lost'])) {
                $this->newLine();
                $this->warn(sprintf('[LOST] %d 筆 — 需 dev 編輯 %s.php', count($data['lost']), $currency));
                foreach ($data['lost'] as $item) {
                    $this->line(sprintf(
                        '  - id=%d  "%s"  信心度 %d%%  最佳猜測: %s',
                        $item['bank_id'],
                        $item['name'],
                        $item['confidence'],
                        $item['best_guess_standard'] ?? '?'
                    ));
                }
            }

            if (!empty($data['low_confidence_feed'])) {
                $this->newLine();
                $this->warn(sprintf(
                    '[LOW-CONFIDENCE-FEED] %d 筆 — 需 dev 編輯 %s.php',
                    count($data['low_confidence_feed']),
                    $currency
                ));
                foreach ($data['low_confidence_feed'] as $item) {
                    $this->line(sprintf(
                        '  - %s  "%s"  信心度 %d%%  code=\'%s\'',
                        $item['provider'],
                        $item['feed_name'],
                        $item['confidence'],
                        $item['feed_code']
                    ));
                }
            }
        }

        $this->newLine();
        $this->info('Summary:');
        foreach ($audit as $currency => $data) {
            $this->line(sprintf(
                '  %s: lost=%d, low-conf-feed=%d',
                $currency,
                count($data['lost']),
                count($data['low_confidence_feed'])
            ));
        }
    }

    protected function renderJson(array $audit): void
    {
        $output = [
            'generated_at' => now()->toIso8601String(),
            'currencies' => [],
            'summary' => [
                'total_lost' => 0,
                'total_low_confidence_feed' => 0,
            ],
        ];

        foreach ($audit as $currency => $data) {
            $output['currencies'][$currency] = [
                'currency_id' => $data['currency_id'],
                'lost' => $data['lost'],
                'low_confidence_feed' => $data['low_confidence_feed'],
            ];
            $output['summary']['total_lost'] += count($data['lost']);
            $output['summary']['total_low_confidence_feed'] += count($data['low_confidence_feed']);
        }

        $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
