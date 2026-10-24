<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Traits\GetContextTrait;
use App\Models\Member;
use Illuminate\Database\Eloquent\Model;

class ActivityLogService
{
    use GetContextTrait;

    /**
     * @param  Model  $subject  被操作對象
     * @param  string  $event  事件名稱（如 created/updated/deleted 或自訂）
     * @param  array  $data  支援 key:
     *                       - log_name: 覆寫 log\_name
     *                       - description: 覆寫描述（可含占位符）
     *                       - causer: 指定操作者(Model)，預設取 Auth::user()
     *                       - properties: 其他自訂屬性
     */
    public function record(Model $subject, string $event, array $data = []): void
    {
        $userData = $this->getUserContext();

        // 優先順序：$data['causer'] -> user context 的 member -> subject 本身（若為 Member） -> subject->member relation
        $causer = $data['causer'] ?? null;

        if (!$causer) {
            if ($userData && $userData->member) {
                $causer = $userData->member;
            }

            if (!$causer && $subject instanceof Member) {
                $causer = $subject;
            } else {
                try {
                    // safe access to possible relation/property
                    $causer = $subject->member ?? null;
                } catch (\Throwable) { // @phpstan-ignore catch.neverThrown (防禦性捕捉，subject 型別不固定)
                    $causer = null;
                }
            }
        }

        $logName = $data['log_name'] ?? class_basename($subject);
        $description = $data['description'] ?? $event;
        $properties = $data['properties'] ?? $data; // 兼容直接塞進 data 的做法

        $logger = activity($logName)
            ->performedOn($subject)
            ->event($event);

        if ($causer instanceof Model) {
            $logger->causedBy($causer);
        }

        if (isset($data['properties'])) {
            $logger->withProperties($properties);
        }

        $logger->log($description);
    }
}
