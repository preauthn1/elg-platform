<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Enums\General\ContextKeyEnum;
use App\Enums\General\OrientationEnum;
use App\Http\Middleware\DetectDevice;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Context;
use Tests\TestCase;

/**
 * DetectDevice 解析 X-Orientation 並寫入 Context
 * （前端不是每個請求都會帶，未帶或無法辨識一律 UNKNOWN）
 *
 * php artisan test tests/Unit/Http/Middleware/DetectDeviceOrientationTest.php
 */
class DetectDeviceOrientationTest extends TestCase
{
    public function test_parses_landscape_and_portrait_header(): void
    {
        $this->assertSame(OrientationEnum::LANDSCAPE, $this->handleWithHeader('landscape'));
        $this->assertSame(OrientationEnum::PORTRAIT, $this->handleWithHeader('portrait'));
    }

    public function test_header_is_case_insensitive_and_trimmed(): void
    {
        $this->assertSame(OrientationEnum::LANDSCAPE, $this->handleWithHeader(' Landscape '));
        $this->assertSame(OrientationEnum::PORTRAIT, $this->handleWithHeader('PORTRAIT'));
    }

    public function test_missing_or_invalid_header_falls_back_to_unknown(): void
    {
        $this->assertSame(OrientationEnum::UNKNOWN, $this->handleWithHeader(null));
        $this->assertSame(OrientationEnum::UNKNOWN, $this->handleWithHeader(''));
        $this->assertSame(OrientationEnum::UNKNOWN, $this->handleWithHeader('sideways'));
    }

    private function handleWithHeader(?string $header): OrientationEnum
    {
        Context::flush();

        $request = Request::create('/game/get-url', 'POST');
        if ($header !== null) {
            $request->headers->set('X-Orientation', $header);
        }

        (new DetectDevice)->handle($request, fn () => new Response);

        return Context::getHidden(ContextKeyEnum::ORIENTATION->value);
    }
}
