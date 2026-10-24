<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * queue_job_runs.job_key：紀錄所屬的 queue job 識別鍵
 *
 * 用穩定的邏輯鍵、不入庫 class 名——class 會改名搬家（FQCN 入庫會讓
 * 歷史紀錄一夕失聯），邏輯鍵不隨重構漂移。新 job 接入時在此加 case
 * （值上限 50 字元，對應 queue_job_runs.job_key varchar(50)），
 * 並自行定義 work_key 與 bucket_time 的語意（見 QueueJobRun）。
 */
enum QueueJobKeyEnum: string
{
    /** SBR 整點彙總（SummarizeBetRecordHour；work_key = vendor_id、bucket_time = UTC 整點） */
    case SUMMARY_BET_RECORD = 'summary_bet_record';

    /**
     * 更新遊戲清單（SyncVendorGameList；work_key = vendor_id、bucket_time = 執行當下 UTC 整點）
     *
     * 「job 執行紀錄」語意：一列 = 一次 job 執行。略過（遊戲商停用／維護中／driver 未實作
     * HasGameList）同樣成功收尾＋output 載明原因；任一商戶同步失敗上拋則由
     * failed hook 把列標為失敗。bucket_time 取執行當下整點以
     * 對齊 hourlyAt(12) 排程；同一小時內重跑各自成列（run_uuid 逐次不同）。
     */
    case UPDATE_GAME_LIST = 'update_game_list';

    /**
     * 補充遊戲帳號池（ReplenishVendorAccountPool；work_key = vendor_id、
     * bucket_time = 派發當下 UTC 5 分鐘格，對齊 everyFiveMinutes 排程）
     *
     * 「job 執行紀錄」語意：一列 = 一次 job 執行。略過（遊戲商停用／維護中／driver 未實作
     * SupportsAccountCreation）同樣成功收尾＋output 載明原因；水位足夠不需補建也是成功收尾。
     * 例外上拋由 failed hook 把列標為失敗；同格重跑各自成列（run_uuid 逐次不同）。
     */
    case CREATE_VENDOR_ACCOUNT = 'create_vendor_account';

    /**
     * 拉取注單（FetchVendorBetRecord；work_key = vendor_id、bucket_time = 本片起點 UTC）
     *
     * 「job 執行紀錄」語意：一列 = 一次 job 執行。自我續鏈的每一棒各自一列；rate limit
     * 預算用罄而未推進窗口的那次執行同樣留列，delay 重排的下一棒（相同 bucket_time）
     * 另留一列，靠 run_uuid 區分。片長 = driver 的 betRecordApiWindowMinutes()，
     * 各家不同且不對齊整點。
     */
    case FETCH_BET_RECORD = 'fetch_bet_record';

    /**
     * 後台系統操作的 artisan 執行（RunAdminOperationJob）與 audit 型稽核。
     *
     * 語意 B（執行紀錄）。work_key = admin_operation_logs.id（一列操作紀錄對一列執行），
     * bucket_time = 派發當下整點；output 承載 Artisan::output() 或稽核輸出。
     * 不可作判缺依據。
     */
    case ADMIN_OPERATION = 'admin_operation';

    /** GGR 結算（GgrSettleJob；work_key = plan_id、bucket_time = period_start 00:00:00 GMT+8 業務日） */
    case GGR_SETTLEMENT = 'ggr_settlement';
}
