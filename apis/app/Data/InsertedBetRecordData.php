<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

/**
 * 拉單時新增進 {tname}_bet_records 的單筆注單
 *
 * attributes 為 driver formatBetRecord 產出的注單欄位（各遊戲商注單表 schema 不同，
 * 保持動態陣列），uniqueKeyValue 為 betRecordUniqueKey 對應欄位值，
 * 供轉入 total_bet_records 時回查自增 bet_record_id。
 */
class InsertedBetRecordData extends Data
{
    public function __construct(
        /** 唯一鍵值（betRecordUniqueKey 欄位值） */
        public string $uniqueKeyValue,

        /** @var array<string, mixed> 注單欄位（欄位名 => 值） */
        public array $attributes,
    ) {}
}
