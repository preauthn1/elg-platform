<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor\Wbet;

use App\Enums\StatusEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Exceptions\AccountNotExistsException;
use App\Models\Member;
use App\Services\VendorService;
use App\Vendors\Models\WbetAccount;
use App\Vendors\Wbet\Driver as WbetDriver;
use Illuminate\Console\Command;
use Throwable;

/**
 * 手動於 WBET 遊戲商端補建帳號 (三方 /api/createmember)
 *
 * php artisan vendor:wbet:register-account v102i1a00000986
 * php artisan vendor:wbet:register-account member001 --force
 */
class RegisterAccount extends Command
{
    protected $signature = 'vendor:wbet:register-account
        {username? : WBET 遊戲帳號 (wbet_accounts.username)，查無時改以平台會員帳號 (members.username) 查詢}
        {--force : 略過確認直接送出}';

    protected $description = '呼叫 WBET /api/createmember 在遊戲商端補建指定帳號';

    public function handle(VendorService $vendorService): int
    {
        $username = trim((string) ($this->argument('username') ?? $this->ask('請輸入帳號名稱 (WBET 遊戲帳號或平台會員帳號)')));

        if ($username === '') {
            $this->error('帳號名稱不可為空');

            return self::FAILURE;
        }

        $account = $this->findVendorAccount($username);

        if ($account === false) {
            return self::FAILURE;
        }

        $member = $account
            ? Member::find($account->member_id)
            : $this->findMember($username);

        if (!$member) {
            $this->error(sprintf('找不到帳號 %s 對應的 WBET 遊戲帳號或平台會員', $username));

            return self::FAILURE;
        }

        try {
            /** @var WbetDriver $driver */
            $driver = $vendorService->getDriver(VendorIdEnum::WBET->value, $member);

            // 未指定既有遊戲帳號時, 沿用會員綁定 (無則自動建立) 的本地遊戲帳號
            $account = $account ?: $driver->getVendorAccount();

            if ($account->vendor_info_id !== $driver->vendorInfo->id) {
                $this->error(sprintf(
                    '遊戲帳號 %s 屬於 vendor_info_id=%d, 與會員 %s (id=%d) 目前的商戶 vendor_info_id=%d 不符',
                    $account->username,
                    $account->vendor_info_id,
                    $member->username,
                    $member->id,
                    $driver->vendorInfo->id
                ));

                return self::FAILURE;
            }

            $driver->setVendorAccount($account);

            $this->table(['項目', '內容'], [
                ['DB 連線', config('database.default')],
                ['商戶 (vendor_info)', sprintf('%s (id=%d)', $driver->vendorInfo->name, $driver->vendorInfo->id)],
                ['三方位址', $driver->vendorInfo->info['AUTH_URL'] ?? ''],
                ['會員', sprintf('%s (id=%d)', $member->username, $member->id)],
                ['遊戲帳號 (account_id)', $account->username],
            ]);

            // 先查一次餘額, 確認遊戲商端真的沒這個帳號才補建
            try {
                $balance = $driver->fetchBalance();

                $this->info(sprintf('遊戲商端已有此帳號 (餘額: %s)，不需補建', $balance));

                return self::SUCCESS;
            } catch (AccountNotExistsException) {
                $this->warn('遊戲商端查無此帳號 (accountNotExists)');
            }

            if (!$this->option('force') && !$this->confirm('確認呼叫三方 /api/createmember 建立此帳號?', false)) {
                $this->line('已取消');

                return self::SUCCESS;
            }

            $driver->registerAccount();

            $this->info(sprintf('三方建立帳號成功: %s', $account->username));

            $this->line(sprintf('查詢三方餘額: %s', $driver->fetchBalance()));
        } catch (Throwable $e) {
            $this->error('三方建立帳號失敗: ' . $e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * 以 WBET 遊戲帳號查詢, 查無回傳 null, 重複回傳 false
     */
    private function findVendorAccount(string $username): WbetAccount|false|null
    {
        $accounts = WbetAccount::query()
            ->where('username', $username)
            ->where('member_id', '>', 0)
            ->where('status', StatusEnum::ENABLE->value)
            ->get();

        if ($accounts->count() > 1) {
            $this->error(sprintf(
                '遊戲帳號 %s 有多筆啟用中紀錄 (id: %s)，請先確認資料',
                $username,
                $accounts->pluck('id')->implode(', ')
            ));

            return false;
        }

        return $accounts->first();
    }

    private function findMember(string $username): ?Member
    {
        $members = Member::query()
            ->where('username', $username)
            ->get();

        if ($members->count() > 1) {
            $this->error(sprintf(
                '會員帳號 %s 有多筆紀錄 (id: %s)，請改用 WBET 遊戲帳號指定',
                $username,
                $members->pluck('id')->implode(', ')
            ));

            return null;
        }

        return $members->first();
    }
}
