<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers\Common;

use App\Helpers\Common;
use Tests\TestCase;

/**
 * Common::safeJsonDecode / safeJsonEncode（JsonTrait）測試
 *
 * php artisan test tests/Unit/Helpers/Common/CommonJsonTest.php
 */
class CommonJsonTest extends TestCase
{
    public function test_safe_json_decode_null_and_empty(): void
    {
        $this->assertSame([], Common::safeJsonDecode(null));
        $this->assertSame([], Common::safeJsonDecode(''));
    }

    public function test_safe_json_decode_valid_and_invalid(): void
    {
        $this->assertSame(['a' => 1], Common::safeJsonDecode('{"a":1}'));
        $this->assertSame([], Common::safeJsonDecode('not json'));
    }

    public function test_safe_json_encode_object(): void
    {
        $json = Common::safeJsonEncode(['x' => '中文', 'u' => 'https://a.test/b']);
        $this->assertStringContainsString('中文', $json);
        $this->assertStringContainsString('https://a.test/b', $json);
    }
}
