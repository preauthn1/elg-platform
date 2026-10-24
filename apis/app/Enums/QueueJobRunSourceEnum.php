<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * queue_job_runs.source：完成標記的觸發來源
 *
 * SBR 回看補洞（SummarizeBetRecordHour）不認 MANUAL 的完成標記：手動補跑可能在
 * 該小時結束前或遲到注單補進前執行，若算數，整點排程會跳過該格、停在手動當下的
 * 結果。每日稽核則不分來源，手動補跑照樣算補上。
 */
enum QueueJobRunSourceEnum: string
{
    /** 整點排程觸發的本輪 bucket */
    case SCHEDULE = 'schedule';

    /** 回看視窗發現缺漏後補洞 */
    case BACKFILL = 'backfill';

    /** CLI／後台手動補跑 */
    case MANUAL = 'manual';

    /** 部署預塞：僅由上線預塞程序寫入，程式端不得產生（勿用於 markCompleted） */
    case PRESEED = 'preseed';
}
