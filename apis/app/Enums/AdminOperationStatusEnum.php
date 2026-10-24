<?php

declare(strict_types=1);

namespace App\Enums;

enum AdminOperationStatusEnum: int
{
    /** 待執行 */
    case PENDING = 0;
    /** 執行中 */
    case RUNNING = 1;
    /** 成功 */
    case SUCCESS = 2;
    /** 失敗 */
    case FAILED = 3;

    public function label(): string
    {
        return match ($this) {
            self::PENDING => '待執行',
            self::RUNNING => '執行中',
            self::SUCCESS => '成功',
            self::FAILED => '失敗',
        };
    }

    /** Filament badge 顏色 */
    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'gray',
            self::RUNNING => 'warning',
            self::SUCCESS => 'success',
            self::FAILED => 'danger',
        };
    }
}
