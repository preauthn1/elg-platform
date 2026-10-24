<?php

declare(strict_types=1);

namespace App\Services\Payment\Traits;

trait DriverTrait
{
    private ?string $cachedDriverName = null;

    protected function getDriverName(): string
    {
        if ($this->cachedDriverName === null) {
            $this->cachedDriverName = $this->driverLabelOverride()
                ?? $this->resolveDriverNameFromPath();
        }

        return $this->cachedDriverName;
    }

    /**
     * 商戶顯示名覆寫點（預設不覆寫，沿用 Provider 目錄名）
     *
     * ! 一份 Provider 實作供多組商戶共用時（如 SpeedPay 共用 PayMate 實作），目錄名無法區分
     * ! 商戶，會讓 log／例外訊息／Http::logAction／代付查表灰度閘門全部標成另一家，事故時
     * ! 第一手告警指向不存在的商戶。共用實作的 provider 覆寫本方法回傳實際商戶名即可。
     */
    protected function driverLabelOverride(): ?string
    {
        return null;
    }

    /**
     * 預設行為：由 Provider 目錄名推得 driver 名
     */
    private function resolveDriverNameFromPath(): string
    {
        $reflection = new \ReflectionClass(static::class);

        return basename(dirname($reflection->getFileName()));
    }
}
