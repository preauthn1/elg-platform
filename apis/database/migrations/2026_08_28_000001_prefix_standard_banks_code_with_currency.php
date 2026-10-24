<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * standard_banks.code 機械補回幣別前綴（MAYBANK → MYR_MAYBANK）。
 *
 * 配碼規則定案：code 一律 `{幣別}_{名稱}` 且含幣別前綴——前綴與 currency_code 互為
 * 校驗，一條查詢即可揪出掛錯幣別的列。config 側（database/data/standard_bank_codes.php
 * 與 app/Console/Commands/CashFlow/Data/banks/{CUR}.php）本就存前綴版，過去由
 * SyncStandardBanksSeeder::stripPrefix() 剝除後落表；本 migration 把 DB 既有列補上
 * 前綴，seeder 同版停止剝除，upsert 鍵 (code, currency_id) 於是兩側一致、不殘留舊列。
 *
 * ⚠️ 與 seeder 改版必須同版部署：舊 seeder 遇到已補前綴的列會因 upsert 鍵對不上而
 * 重新插入剝前綴列（重複資料）。
 *
 * 冪等：僅處理尚未帶自身幣別前綴的列（幣別以 currencies.code 為權威，並順手回填
 * currency_code 冗餘欄）。前置檢查：補前綴後會撞既有列者中止並列出 id，交人工處理。
 * banks.standard_bank_id 以 id 參照、不受 code 改值影響；bank:sync-mappings tier1 的
 * `sb.code = b.code` join 自此斷開（banks.code 是通道碼、不同命名空間，六站實測命中
 * 近乎為零，由 tier3 name/alias 承接）。
 */
return new class extends Migration
{
    public function up(): void
    {
        $collisions = DB::table('standard_banks as a')
            ->join('currencies as c', 'c.id', '=', 'a.currency_id')
            ->join('standard_banks as b', fn ($j) => $j
                ->on('b.currency_id', '=', 'a.currency_id')
                ->whereRaw("b.code = CONCAT(c.code, '_', a.code)"))
            ->whereRaw("a.code NOT LIKE CONCAT(c.code, '\\_%')")
            ->pluck('a.id');

        if ($collisions->isNotEmpty()) {
            throw new RuntimeException(
                'standard_banks 補前綴會與既有列撞唯一鍵，請先人工處理 id: ' . $collisions->implode(', '),
            );
        }

        DB::update(
            'UPDATE standard_banks sb'
            . ' JOIN currencies c ON c.id = sb.currency_id'
            . " SET sb.code = CONCAT(c.code, '_', sb.code), sb.currency_code = c.code"
            . " WHERE sb.code NOT LIKE CONCAT(c.code, '\\_%')",
        );

        // 生效斷言：currency_id 對不到 currencies 的列不會被上面 UPDATE 觸及，fail loud
        // 而非留下前綴不一致的暗雷（前綴不變式是 seeder upsert 與互校驗查詢的前提）。
        $violating = DB::table('standard_banks as sb')
            ->join('currencies as c', 'c.id', '=', 'sb.currency_id')
            ->whereRaw("sb.code NOT LIKE CONCAT(c.code, '\\_%')")
            ->count();
        $orphans = DB::table('standard_banks as sb')
            ->leftJoin('currencies as c', 'c.id', '=', 'sb.currency_id')
            ->whereNull('c.id')
            ->pluck('sb.id');

        if ($violating > 0 || $orphans->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'standard_banks 補前綴後仍有 %d 列不符前綴不變式；currency_id 孤兒列 id: %s',
                $violating,
                $orphans->isEmpty() ? '無' : $orphans->implode(', '),
            ));
        }

        DB::statement(
            "ALTER TABLE standard_banks MODIFY code varchar(64) NOT NULL COMMENT '標準銀行代碼（{幣別}_{名稱} 全大寫，含幣別前綴，與 currency_code 互為校驗）'",
        );
    }

    public function down(): void
    {
        DB::update(
            'UPDATE standard_banks sb'
            . ' JOIN currencies c ON c.id = sb.currency_id'
            . ' SET sb.code = SUBSTRING(sb.code, CHAR_LENGTH(c.code) + 2)'
            . " WHERE sb.code LIKE CONCAT(c.code, '\\_%')",
        );

        DB::statement(
            "ALTER TABLE standard_banks MODIFY code varchar(64) NOT NULL COMMENT '標準銀行代碼（全大寫，不含幣別前綴）'",
        );
    }
};
