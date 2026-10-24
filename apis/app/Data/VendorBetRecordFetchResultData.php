<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

/**
 * 單一遊戲商全窗口拉注單彙總結果 DTO（--direct / --dry-run）；
 * error 非 null 表示該商執行失敗（不影響其他商）
 */
class VendorBetRecordFetchResultData extends Data
{
    public function __construct(
        public int $vendorId,
        public string $vendorName,

        /** 三方回傳的原始注單筆數（全窗口合計） */
        public int $fetched = 0,

        /** 實際新增進 {tname}_bet_records 的筆數（全窗口合計） */
        public int $inserted = 0,

        /** 更新模式下，金額異動而被更新的既有列筆數（全窗口合計） */
        public int $updated = 0,

        /** 已處理的時間片數 */
        public int $chunks = 0,

        /** 中止時的剩餘窗口起點（rate limit 預算用罄，或後台按下「中斷」） */
        public ?string $pendingFrom = null,

        /** pendingFrom 的成因是後台「中斷」而非 rate limit：兩者的後續處置不同，輸出須分開講 */
        public bool $cancelled = false,

        public ?string $error = null,

        /**
         * 乾跑清單：將新增的注單（唯一鍵 + 金額欄位），最多 BetRecordFetchService::PREVIEW_LIST_LIMIT 筆
         *
         * @var list<array{key: string, amounts: array<string, string>}>
         */
        public array $previewNew = [],

        /**
         * 乾跑清單：金額有異動的既有列（唯一鍵 + 各欄位 from/to），最多 BetRecordFetchService::PREVIEW_LIST_LIMIT 筆
         *
         * @var list<array{key: string, changes: array<string, array{from: string, to: string}>}>
         */
        public array $previewChanged = [],

        /** 反向對帳：派彩時間落在窗口內、但整個窗口三方都沒回的既有列筆數（乾跑提示，不影響寫入） */
        public int $missing = 0,

        /**
         * 反向對帳清單（唯一鍵 + 金額欄位），最多 BetRecordFetchService::PREVIEW_LIST_LIMIT 筆
         *
         * @var list<array{key: string, amounts: array<string, string>}>
         */
        public array $previewMissing = [],
    ) {}
}
