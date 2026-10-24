<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow;

use App\Console\Commands\Maker\MakeCommand;
use Throwable;

/**
 * 建立支付服務提供商腳手架命令
 *
 * 功能說明：
 * - 在 App\Services\Payment\Providers 目錄下建立支付商資料夾
 * - 自動建立代收相關檔案（Driver, Deposit, DepositCallback）
 * - 可選建立代付相關檔案（Withdrawal, WithdrawalCallback）
 * - 自動建立共用 Trait 檔案
 *
 * 使用範例：
 *   # 互動式執行
 *   php artisan make:payment
 *
 *   # 直接指定金流商名稱
 *   php artisan make:payment EpicPay
 */
class MakePaymentProvider extends MakeCommand
{
    /**
     * 命令簽名
     *
     * @var string
     */
    protected $signature = 'make:payment {name? : 金流商名稱（大駝峰格式，例如：EpicPay）}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '建立三方支付服務提供商的基礎檔案';

    /**
     * 執行命令
     *
     * 執行流程：
     * 1. 取得或詢問金流商名稱
     * 2. 驗證名稱格式（大駝峰）
     * 3. 檢查並準備目錄
     * 4. 建立代收相關檔案
     * 5. 詢問是否建立代付檔案
     * 6. 建立共用 Trait 檔案
     * 7. 顯示統計資訊和後續步驟提示
     *
     * @return int 命令執行結果（SUCCESS 或 FAILURE）
     *
     * @throws Throwable
     */
    public function handle(): int
    {
        $this->info('支付服務提供商腳手架生成器');
        $this->newLine();

        // 取得金流商名稱
        $dirName = $this->argument('name');

        if (!$dirName) {
            $dirName = $this->ask('請輸入金流商的英文代號（請使用大駝峰格式，例如：EpicPay）');
        }

        if (!$dirName) {
            $this->error('❌ 金流商名稱不可為空');

            return self::FAILURE;
        }

        // 驗證名稱格式
        if (!$this->validateProviderName($dirName)) {
            $this->error(sprintf('❌ 金流商名稱格式錯誤: %s', $dirName));
            $this->warn('   名稱必須符合大駝峰格式（PascalCase），例如：EpicPay、AllPay、PayHub');
            $this->warn('   - 首字母大寫');
            $this->warn('   - 只包含字母和數字');
            $this->warn('   - 每個單詞首字母大寫');

            return self::FAILURE;
        }

        $this->comment(sprintf('準備為 %s 建立支付服務檔案...', $dirName));
        $this->line(sprintf('目標路徑: App\\Services\\Payment\\Providers\\%s', $dirName));
        $this->newLine();

        // 檢查並準備目錄
        $dirPath = app_path('Services/Payment/Providers/' . $dirName);

        if (!$this->prepareDirectory($dirPath, sprintf('目錄 %s 已存在', $dirName))) {
            $this->warn('✓ 操作已取消');

            return self::FAILURE;
        }

        $basePath = base_path('app/Services/Payment/Providers/' . $dirName);
        $stubDir = base_path('app/Console/Stubs/Payment');

        // 驗證 stub 目錄
        if (!is_dir($stubDir)) {
            $this->error(sprintf('❌ Stub 目錄不存在: %s', $stubDir));

            return self::FAILURE;
        }

        // 統計資訊
        $stats = [
            'files_created' => 0,
            'files_skipped' => 0,
            'deposit_files' => 0,
            'withdrawal_files' => 0,
        ];

        try {
            // 建立代收檔案
            $this->comment('建立代收相關檔案...');
            $depositFiles = ['Driver', 'Deposit', 'DepositCallback'];
            $this->scaffoldFromStubs($basePath, $stubDir, $depositFiles, ['{{$name}}' => $dirName], $stats);
            $stats['deposit_files'] = count($depositFiles);

            // 建立代付檔案
            if ($this->confirm('是否需要建立代付相關檔案？', true)) {
                $this->newLine();
                $this->comment('建立代付相關檔案...');
                $withdrawalFiles = ['Withdrawal', 'WithdrawalCallback'];
                $this->scaffoldFromStubs($basePath, $stubDir, $withdrawalFiles, ['{{$name}}' => $dirName], $stats);
                $stats['withdrawal_files'] = count($withdrawalFiles);
            } else {
                $this->line('  - 已跳過代付檔案建立');
            }

            // 建立 Trait
            $this->newLine();
            $this->comment('建立共用 Trait 檔案...');
            $traitFile = $basePath . '/' . $dirName . 'Trait.php';
            $traitStub = $stubDir . '/Trait.stub';

            if ($this->createFileFromStub($traitFile, $traitStub, ['{{$name}}' => $dirName])) {
                $this->info('  ✓ ' . str_replace(base_path(), '...', $traitFile));
                $stats['files_created']++;
            } else {
                $stats['files_skipped']++;
            }

            $this->newLine();
            $this->info('✓ 所有檔案已成功建立！');

            // 顯示統計資訊
            $this->printStats($stats);

            // 顯示後續步驟提示
            $this->printNextSteps($dirName);

            return self::SUCCESS;

        } catch (Throwable $e) {
            $this->newLine();
            $this->error(sprintf('❌ 發生錯誤: %s', $e->getMessage()));
            $this->line(sprintf('錯誤位置: %s:%d', $e->getFile(), $e->getLine()));

            return self::FAILURE;
        }
    }

    /**
     * 驗證金流商名稱格式
     *
     * 規則：
     * - 必須是大駝峰格式（PascalCase）
     * - 首字母大寫
     * - 只包含字母和數字
     * - 不包含特殊字符、空格、底線、連字符
     *
     * @param  string  $name  金流商名稱
     * @return bool 是否有效
     */
    protected function validateProviderName(string $name): bool
    {
        // 檢查是否只包含字母和數字
        if (!ctype_alnum($name)) {
            return false;
        }

        // 檢查首字母是否大寫
        if (!ctype_upper($name[0])) {
            return false;
        }

        // 檢查是否包含小寫字母（確保不是全大寫）
        if ($name === strtoupper($name)) {
            return false;
        }

        return true;
    }

    /**
     * 從 stub 建立檔案（覆寫父類方法以支援統計）
     *
     * @param  string  $basePath  基礎路徑
     * @param  string  $stubDir  Stub 目錄
     * @param  array  $files  檔案列表
     * @param  array  $replacements  替換規則
     * @param  array  $stats  統計資訊（引用傳遞）
     */
    protected function scaffoldFromStubs(
        string $basePath,
        string $stubDir,
        array $files,
        array $replacements,
        array &$stats = []
    ): void {
        foreach ($files as $file) {
            $target = $basePath . '/' . $file . '.php';
            $stubPath = $stubDir . '/' . $file . '.stub';

            if ($this->createFileFromStub($target, $stubPath, $replacements)) {
                $this->info('  ✓ ' . str_replace(base_path(), '...', $target));
                $stats['files_created']++;
            } else {
                $stats['files_skipped']++;
            }
        }
    }

    /**
     * 輸出統計資訊
     *
     * @param  array  $stats  統計資料
     */
    protected function printStats(array $stats): void
    {
        $this->newLine();
        $this->info('執行統計:');

        $this->table(
            ['項目', '數量'],
            [
                ['代收檔案', $stats['deposit_files']],
                ['代付檔案', $stats['withdrawal_files']],
                ['Trait 檔案', 1],
                ['成功建立', $stats['files_created']],
                ['跳過/失敗', $stats['files_skipped']],
            ]
        );
    }

    /**
     * 顯示後續步驟提示
     *
     * @param  string  $providerName  金流商名稱
     */
    protected function printNextSteps(string $providerName): void
    {
        $this->newLine();
        $this->warn('📋 後續步驟:');
        $this->newLine();

        $this->line('1. 在 config/payment.php 新增金流商配置');
        $this->comment('   provider => [');
        $this->comment(sprintf('       \'%s\' => Providers\\%s\\Driver::class,', strtoupper($providerName), $providerName));
        $this->comment('   ]');
        $this->newLine();

        $this->line('2. 在 app/Console/Commands/CashFlow/Data/cash_flows.php 新增金流商資料');
        $this->comment('   [');
        $this->comment('       \'id\' => 99,  // 請使用實際的 ID');
        $this->comment(sprintf('       \'name\' => \'%s\',', $providerName));
        $this->comment('       \'type\' => 1,  // 1:代收, 3:代付');
        $this->comment('       // ... 其他欄位');
        $this->comment('   ]');
        $this->newLine();

        $this->line('3. 開發環境執行同步命令');
        $this->comment('   php artisan cash-flow:sync --test  # 先測試');
        $this->comment('   php artisan cash-flow:sync         # 正式執行');
        $this->newLine();

        $this->line(sprintf('4. 實作 %s/Driver 相關的業務邏輯', $providerName));
        $this->comment('   - Driver 定義金流商需要實作的接口，例如：代收、代收回調等');
        $this->comment(sprintf('   - 實作 %sTrait 中的共用方法，例如：簽名生成、參數驗證、錯誤處理等', $providerName));
        $this->comment('   - 實作 Deposit 類別的代收邏輯');
        $this->comment('   - 實作 DepositCallback 類別的代收回調');
        if (file_exists(app_path(sprintf('Services/Payment/Providers/%s/Withdrawal.php', $providerName)))) {
            $this->comment('   - 實作 Withdrawal 類別的代付邏輯');
            $this->comment('   - 實作 WithdrawalCallback 類別的代付回調');
        }
        $this->newLine();
    }
}
