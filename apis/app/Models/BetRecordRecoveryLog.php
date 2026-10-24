<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BetRecordRecoveryTypeEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * 注單人工補帳/撤回事件記錄（event-log 模式，每個操作 = 新 row）。
 *
 * type 區分 recover / revert，revert 透過 parent_log_id 指向被撤回的 recover。
 * MySQL UNIQUE(parent_log_id) 允許多 NULL，所以：
 *   - 同一注單可多次補帳（多筆 recover with parent_log_id=NULL）
 *   - 每筆 recover 最多被撤回一次（unique 強制）
 *
 * @property int $id
 * @property int $vendor_id 遊戲商系統編號
 * @property int $bet_record_id 來源注單表 id（依 vendor_id 對應 XXX_bet_records）
 * @property string $bet_id 注單 BetId（冗余便於跨 vendor 追查）
 * @property int $member_id 會員系統編號
 * @property BetRecordRecoveryTypeEnum $type 事件類型：recover=補帳、revert=撤回補帳
 * @property Carbon $performed_at 執行時間
 * @property string $performed_by 執行者：command 名稱 / 客服姓名
 * @property string $reason 執行原因（必填）
 * @property string|null $source 資料來源：CSV 檔名:row、客服票號等
 * @property array<array-key, mixed> $before 操作前快照
 * @property array<array-key, mixed> $after 操作後快照
 * @property numeric $balance_diff 玩家錢包淨變動：recover=正、revert=負
 * @property int|null $parent_log_id 被撤回的 recover log id；type=revert 時填，type=recover 時為 NULL；MySQL UNIQUE 允許多 NULL，故同一注單可多次補帳，但每筆 recover 最多一次撤回
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BetRecordRecoveryLog|null $recoverOf
 * @property-read BetRecordRecoveryLog|null $revert
 *
 * @method static Builder<static>|BetRecordRecoveryLog newModelQuery()
 * @method static Builder<static>|BetRecordRecoveryLog newQuery()
 * @method static Builder<static>|BetRecordRecoveryLog query()
 *
 * @mixin \Eloquent
 */
class BetRecordRecoveryLog extends Model
{
    protected $table = 'bet_record_recovery_logs';

    protected $guarded = ['id'];

    protected $casts = [
        'type' => BetRecordRecoveryTypeEnum::class,
        'before' => 'array',
        'after' => 'array',
        'performed_at' => 'datetime',
    ];

    /**
     * Recover 對應的 revert 事件（若有）。
     */
    public function revert(): HasOne
    {
        return $this->hasOne(self::class, 'parent_log_id')
            ->where('type', BetRecordRecoveryTypeEnum::REVERT->value);
    }

    /**
     * Revert 對應的 recover 事件。
     */
    public function recoverOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_log_id');
    }

    /**
     * 取某筆注單的最新事件。
     */
    public static function latestFor(int $vendorId, int $betRecordId): ?self
    {
        return static::query()
            ->where('vendor_id', $vendorId)
            ->where('bet_record_id', $betRecordId)
            ->latest('performed_at')
            ->first();
    }

    /**
     * 推導當前狀態：是否處於「已補帳生效中」。
     * 規則：最新事件為 recover 即視為生效中。
     */
    public static function isCurrentlyRecovered(int $vendorId, int $betRecordId): bool
    {
        return static::latestFor($vendorId, $betRecordId)?->type === BetRecordRecoveryTypeEnum::RECOVER;
    }

    /**
     * 推薦下一步操作（給 command 使用，但兩個 command 各自獨立，這只是輔助查詢）。
     */
    public static function suggestedNextAction(int $vendorId, int $betRecordId): BetRecordRecoveryTypeEnum
    {
        $latest = static::latestFor($vendorId, $betRecordId);
        if (!$latest) {
            return BetRecordRecoveryTypeEnum::RECOVER;
        }

        return $latest->type === BetRecordRecoveryTypeEnum::RECOVER
            ? BetRecordRecoveryTypeEnum::REVERT
            : BetRecordRecoveryTypeEnum::RECOVER;
    }

    /**
     * 此 recover 是否已被撤回。
     */
    public function isReverted(): bool
    {
        if ($this->type !== BetRecordRecoveryTypeEnum::RECOVER) {
            return false;
        }

        return static::query()
            ->where('parent_log_id', $this->id)
            ->where('type', BetRecordRecoveryTypeEnum::REVERT->value)
            ->exists();
    }
}
