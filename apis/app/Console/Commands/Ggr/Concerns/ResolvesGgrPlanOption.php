<?php

declare(strict_types=1);

namespace App\Console\Commands\Ggr\Concerns;

use App\Models\GgrPlan;

/**
 * `--plan` 選項解析（ggr:settle／ggr:payout 共用）。
 *
 * 裸 `(int)` 轉型會把 `--plan=abc`、`--plan=` 靜默變成 0，查無列卻以成功作收——
 * 操作者會誤以為「該方案沒有待處理資料」。這裡改為驗數字且方案必須存在，
 * 不合法即讓命令以非 0 結束（後台操作紀錄才會如實標為失敗）。
 */
trait ResolvesGgrPlanOption
{
    /** @return int|null|false 方案 id；未指定回 null；不合法回 false（呼叫端應回傳 FAILURE） */
    protected function resolveGgrPlanOption(): int|false|null
    {
        $raw = $this->option('plan');

        if ($raw === null) {
            return null;
        }

        if (!is_string($raw) || !ctype_digit($raw) || (int) $raw <= 0) {
            $this->error(sprintf('--plan 需為正整數方案 id，收到「%s」', is_string($raw) ? $raw : gettype($raw)));

            return false;
        }

        $planId = (int) $raw;

        if (!GgrPlan::query()->whereKey($planId)->exists()) {
            $this->error(sprintf('方案 %d 不存在', $planId));

            return false;
        }

        return $planId;
    }
}
