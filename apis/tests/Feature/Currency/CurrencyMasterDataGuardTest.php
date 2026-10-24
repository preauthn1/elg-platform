<?php

declare(strict_types=1);

namespace Tests\Feature\Currency;

use App\Enums\CurrencyEnum;
use App\Models\Currency;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\SyncCurrenciesSeeder;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

/**
 * 兩支幣別 seeder 寫入前的主檔一致性守衛（GuardsCurrencyMasterData）。
 *
 * 守衛要擋的是 2026-09-07 圓桌會審實測出來的兩條靜默失敗路徑：
 * - CurrencySeeder 的 insertOrIgnore 遇到 id 已被別的 code 佔用，該筆被略過、零錯誤；
 * - SyncCurrenciesSeeder 的批次 insert 撞主鍵整批回滾，AbstractSyncSeeder::run() 的 catch 不重拋，
 *   app:upgrade 照樣以退出碼 0 收場。
 * 兩者都會讓 CurrencyEnum 指向別人而無人察覺，守衛把它們變成當場拋例外。
 *
 * 每支測試先灌一次 currencies.php（CI 的 currencies 是空表），再動主檔單一列製造衝突；
 * 所有寫入由 DatabaseTransactions 回滾。
 *
 * php artisan test tests/Feature/Currency/CurrencyMasterDataGuardTest.php
 */
class CurrencyMasterDataGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CurrencySeeder::class);
    }

    #[Group('rules/currency-and-wallet-currency#R-3')]
    public function test_reseeding_a_consistent_master_is_a_no_op(): void
    {
        $before = Currency::query()->pluck('code', 'id')->all();

        $this->seed(CurrencySeeder::class);
        (new SyncCurrenciesSeeder)->run();

        $this->assertSame($before, Currency::query()->pluck('code', 'id')->all());
    }

    #[Group('rules/currency-and-wallet-currency#R-3')]
    public function test_currency_seeder_refuses_when_an_id_is_occupied_by_another_code(): void
    {
        // Dev 09-07 的實況：id 22 是後台手建的測試幣別，資料檔要放的是 PHP。
        Currency::query()->whereKey(CurrencyEnum::PHP->value)->update(['code' => 'ZZT', 'name' => 'tampered']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('id 22 已被 ZZT 佔用，資料檔要放的是 PHP');

        $this->seed(CurrencySeeder::class);
    }

    #[Group('rules/currency-and-wallet-currency#R-3')]
    public function test_sync_seeder_refuses_when_an_id_is_occupied_by_another_code(): void
    {
        Currency::query()->whereKey(CurrencyEnum::PHP->value)->update(['code' => 'ZZT', 'name' => 'tampered']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('id 22 已被 ZZT 佔用，資料檔要放的是 PHP');

        (new SyncCurrenciesSeeder)->run();
    }

    #[Group('rules/currency-and-wallet-currency#R-3')]
    public function test_seeder_refuses_when_a_code_lives_at_a_different_id(): void
    {
        // 同一個 code 若已在別的 id（例如先前被 AUTO_INCREMENT 分到 60），照灌會讓兩處對同一幣別的 id 不同。
        Currency::query()->whereKey(CurrencyEnum::PHP->value)->delete();
        Currency::query()->insert([
            'id' => 60,
            'code' => 'PHP',
            'name' => 'Philippine Peso',
            'rate' => '1.00000',
            'status' => 1,
            'admin' => 1,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PHP 已存在於 id 60，資料檔要放的是 id 22');

        $this->seed(CurrencySeeder::class);
    }

    #[Group('rules/currency-and-wallet-currency#R-2')]
    #[Group('rules/currency-and-wallet-currency#R-3')]
    public function test_guard_matches_codes_case_insensitively_like_the_unique_key(): void
    {
        // code 的唯一鍵依 collation 不分大小寫：主檔若是 php（小寫）在 id 60，insertOrIgnore 會被唯一鍵
        // 靜默擋下、守衛卻用大小寫敏感比對就會漏判，所以反查也要不分大小寫。
        Currency::query()->whereKey(CurrencyEnum::PHP->value)->delete();
        Currency::query()->insert([
            'id' => 60,
            'code' => 'php',
            'name' => 'Philippine Peso',
            'rate' => '1.00000',
            'status' => 1,
            'admin' => 1,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PHP 已存在於 id 60，資料檔要放的是 id 22');

        $this->seed(CurrencySeeder::class);
    }
}
