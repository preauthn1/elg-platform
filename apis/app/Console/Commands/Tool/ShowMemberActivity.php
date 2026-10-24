<?php

declare(strict_types=1);

namespace App\Console\Commands\Tool;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Spatie\Activitylog\Models\Activity;

/**
 * php artisan member:activity
 * php artisan member:activity --limit=500
 * php artisan member:activity 48897
 * php artisan member:activity 48897 2025-11-21
 * php artisan member:activity --log-name="member-login"
 * php artisan member:activity --log-name="member-balance-change"
 * php artisan member:activity --uuid="019aa602-9e02-732f-913d-7849a17a362b"
 */
final class ShowMemberActivity extends Command
{
    // make member_id optional; add --log-name and --recent options
    protected $signature = 'member:activity {member_id?} {date? : yyyy-mm-dd，預設今天} {--limit=100} {--log-name=} {--uuid=}';

    protected $description = '顯示指定會員在某天的活動日誌，若省略 member_id 則列出最近紀錄';

    public function handle(): int
    {
        $memberId = $this->argument('member_id');
        // if member_id is provided, ensure it's a positive integer
        if (!empty($memberId) && !is_numeric($memberId)) {
            $this->error('member_id 必須為整數');

            return 1;
        }

        if (!empty($memberId)) {
            $memberId = (int) $memberId;
        }

        $logName = $this->option('log-name');
        $uuid = $this->option('uuid');

        // 查詢日期 (預設今天)
        $day = null;
        $start = null;
        $end = null;
        $dateArg = $this->argument('date');
        if (!empty($dateArg)) {
            try {
                $day = Carbon::createFromFormat('Y-m-d', $dateArg);
                $start = $day->copy()->startOfDay();
                $end = $day->copy()->endOfDay();
            } catch (\Exception $e) {
                $this->error('日期格式錯誤，請使用 Y-m-d，例如 2025-11-21');

                return 1;
            }
        }

        $limit = (int) $this->option('limit');

        $activities = Activity::query()
            ->when($memberId, fn ($q) => $q->where('causer_id', $memberId))
            ->when($logName, fn ($q) => $q->where('log_name', $logName))
            ->when($uuid, fn ($q) => $q->where('batch_uuid', $uuid))
            ->when($day, fn ($q) => $q->whereBetween('created_at', [$start, $end]))
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        if ($activities->isEmpty()) {
            $this->info('查無資料');

            return 0;
        }

        $rows = $activities->map(function (Activity $a) {
            return [
                $a->created_at->toDateTimeString(),
                $a->log_name,
                $a->description,
                $a->batch_uuid ?? '',
            ];
        })->toArray();

        $this->newLine();
        $this->info('共 ' . count($rows) . ' 筆');
        $this->table(['時間', '事件', '描述', 'TID'], $rows);
        $this->info('共 ' . count($rows) . ' 筆');
        $this->newLine();

        return 0;
    }

    protected function normalizeProperties(Activity $activity): array
    {
        $props = $activity->properties ?? [];

        if (is_object($props) && method_exists($props, 'toArray')) {
            return $props->toArray();
        }

        if (is_string($props)) {
            $decoded = json_decode($props, true);

            return is_array($decoded) ? $decoded : [];
        }

        if (is_array($props)) {
            return $props;
        }

        return [];
    }
}
