<?php

declare(strict_types=1);

namespace Tests\Unit\Enums\BotNotify;

use App\Enums\BotNotify\AlertCategory;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AlertCategoryTest extends TestCase
{
    #[Test]
    public function fixed_categories_expose_stable_slug_and_chinese_label(): void
    {
        $this->assertSame('http_timeout', AlertCategory::HTTP_TIMEOUT->value);
        $this->assertSame('HTTP 連線超時', AlertCategory::HTTP_TIMEOUT->label());

        $this->assertSame('SQL 查詢錯誤', AlertCategory::SQL_ERROR->label());
        $this->assertSame('緩存鎖超時', AlertCategory::LOCK_TIMEOUT->label());
        $this->assertSame('參數錯誤', AlertCategory::INVALID_ARGUMENT->label());
        $this->assertSame('sms_gateway', AlertCategory::SMS_GATEWAY->value);
        $this->assertSame('sms發送失敗', AlertCategory::SMS_GATEWAY->label());
        $this->assertSame('Throwable', AlertCategory::THROWABLE->label());
    }

    #[Test]
    public function http_error_label_resolves_error_type_by_status(): void
    {
        $this->assertSame('HTTP 請求錯誤 - 服務不可用 (HTTP 502)', AlertCategory::HTTP_ERROR->label(502));
        $this->assertSame('HTTP 請求錯誤 - 服務不可用 (HTTP 504)', AlertCategory::HTTP_ERROR->label(504));
        $this->assertSame('HTTP 請求錯誤 - 速率限制 (HTTP 429)', AlertCategory::HTTP_ERROR->label(429));
        $this->assertSame('HTTP 請求錯誤 - 認證失敗 (HTTP 401)', AlertCategory::HTTP_ERROR->label(401));
        $this->assertSame('HTTP 請求錯誤 - 端點不存在 (HTTP 404)', AlertCategory::HTTP_ERROR->label(404));
    }
}
