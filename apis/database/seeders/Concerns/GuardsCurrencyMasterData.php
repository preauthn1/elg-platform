<?php

declare(strict_types=1);

namespace Database\Seeders\Concerns;

use App\Models\Currency;
use RuntimeException;

/**
 * 幣別主檔寫入前的一致性守衛。
 *
 * database/data/currencies.php 的 id 是明寫進去的（不靠 AUTO_INCREMENT），
 * 六站 id→code 一致是多幣別錢包依賴的事實。兩支 seeder 原本都會靜默吞掉不一致：
 * - CurrencySeeder 的 insertOrIgnore 遇到 id 已被別的 code 佔用，該筆被略過、零錯誤，
 *   CurrencyEnum 從此指向別人；
 * - SyncCurrenciesSeeder 走 AbstractSyncSeeder 的批次 insert，同批任一筆撞主鍵就整批回滾，
 *   而 run() 的 catch 只回傳計數、不重拋，app:upgrade 會印「更新 N 筆」後以退出碼 0 收場。
 *
 * 因此寫入之前逐筆比對：資料檔的 id 若已被不同 code 佔用、或 code 已存在於不同 id，
 * 一律拋例外讓執行當場失敗，由人決定怎麼處理主檔那幾列
 * （2026-09-07 實例：Dev 的 id 22 是後台手建的測試幣別 TW，資料檔要放的是 PHP）。
 */
trait GuardsCurrencyMasterData
{
    /**
     * @param  array<int, array<string, mixed>>  $rows  currencies.php 的內容
     *
     * @throws RuntimeException 任一筆與主檔現況衝突
     */
    protected function assertCurrencyMasterDataConsistent(array $rows): void
    {
        /** @var array<int, string> $codeById */
        $codeById = Currency::query()->pluck('code', 'id')->all();

        // code 的唯一鍵依 collation 不分大小寫，這裡的反查也不分大小寫，否則 myr 對 MYR 會漏判。
        $idByUpperCode = [];

        foreach ($codeById as $id => $code) {
            $idByUpperCode[mb_strtoupper($code)] = $id;
        }

        $conflicts = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $code = (string) $row['code'];

            $occupant = $codeById[$id] ?? null;
            $existingId = $idByUpperCode[mb_strtoupper($code)] ?? null;

            if ($occupant !== null && $occupant !== $code) {
                $conflicts[] = sprintf('id %d 已被 %s 佔用，資料檔要放的是 %s', $id, $occupant, $code);
            } elseif ($existingId !== null && $existingId !== $id) {
                $conflicts[] = sprintf('%s 已存在於 id %d，資料檔要放的是 id %d', $code, $existingId, $id);
            }
        }

        if ($conflicts !== []) {
            throw new RuntimeException(
                "currencies 主檔與 database/data/currencies.php 不一致，拒絕寫入：\n  "
                . implode("\n  ", $conflicts)
                . "\n請先處理主檔那幾列（不可改資料檔的 id 去迎合單一環境），再重跑。",
            );
        }
    }
}
