<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Concerns\GuardsCurrencyMasterData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CurrencySeeder extends Seeder
{
    use GuardsCurrencyMasterData;

    /**
     * 幣別種子資料的唯一來源是 database/data/currencies.php，
     * 本 seeder 只負責把它灌進去，不再自帶一份清單。
     *
     * 2026-09-07 多幣別錢包第二期 2.1 收斂：本檔原自帶 18 筆（停在 AUD，缺 BDT／TON／INR），
     * 與 SyncCurrenciesSeeder 讀的資料檔不同步——改了資料檔沒改這裡，新環境重建會拿到舊基線；
     * 日後要處理任何幣別 id 時，只動資料庫那列也會被這支建回來。
     *
     * 寫入前先過 GuardsCurrencyMasterData：資料檔的 id 是明寫的，insertOrIgnore 遇到
     * id 已被別的 code 佔用會靜默略過那一筆、零錯誤，CurrencyEnum 從此指向別人；守衛把它變成當場失敗。
     *
     * 沿用 insertOrIgnore 而非 upsert：測試環境的 DatabaseTransactions 搭配
     * InnoDB next-key lock 會造成 Lock wait timeout（原註解保留的理由）。
     * 用 + 聯集而非 array_merge：資料檔每筆已有 rate，聯集保留左側既有鍵、只補缺的 status 與 admin。
     */
    public function run(): void
    {
        $rows = require database_path('data/currencies.php');

        $this->assertCurrencyMasterDataConsistent($rows);

        $currencies = array_map(
            static fn (array $currency): array => $currency + [
                'status' => 1,
                'admin' => 1,
            ],
            $rows,
        );

        DB::table('currencies')->insertOrIgnore($currencies);
    }
}
