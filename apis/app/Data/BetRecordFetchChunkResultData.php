<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

/**
 * 拉取注單結果 DTO（單一時間片）
 *
 * 記錄單片拉取的統計與剩餘窗口起點，供 FetchVendorBetRecord Job 派發接續任務。
 */
class BetRecordFetchChunkResultData extends Data
{
    public function __construct(
        /** 三方回傳的原始注單筆數 */
        public int $fetched = 0,

        /** 實際新增進 {tname}_bet_records 的筆數 */
        public int $inserted = 0,

        /** 更新模式下，金額異動而被更新的既有列筆數 */
        public int $updated = 0,

        /** 剩餘窗口起點（UTC，null = 窗口已拉完）：片完成 = 下一片起點、預算用罄 = 當前片重試 */
        public ?string $pendingFrom = null,

        /** rate limit 預算釋放所需秒數；0 = 片已完成，接續任務可立即派發 */
        public int $retryAfterSeconds = 0,
    ) {}
}
