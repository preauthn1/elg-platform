<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * queue_job_runs.status：單次執行的生命週期狀態
 *
 * 派發當下即建列（PENDING），job 收尾 update 同列為 SUCCESS／FAILED——
 * 一列的生命週期就是一次執行的生命週期，派發了沒下文（worker 沒吃到、
 * job 蒸發）會以殘留的 PENDING 列現形。
 *
 * ⚠️ 判缺（isCompleted／missingPairs）只認 SUCCESS：PENDING／FAILED 列
 * 不代表該格資料已落地，契約為「無成功列＝缺資料」。
 */
enum QueueJobRunStatusEnum: int
{
    /** 已派發、尚未執行完成 */
    case PENDING = 0;

    /** 執行成功（含執行後判定略過——job 有跑完、決策有落地） */
    case SUCCESS = 1;

    /** 執行失敗（例外上拋、timeout、worker 異常） */
    case FAILED = 2;

    public function label(): string
    {
        return match ($this) {
            self::PENDING => '未處理',
            self::SUCCESS => '成功',
            self::FAILED => '失敗',
        };
    }

    /** Filament badge 顏色 */
    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'gray',
            self::SUCCESS => 'success',
            self::FAILED => 'danger',
        };
    }
}
