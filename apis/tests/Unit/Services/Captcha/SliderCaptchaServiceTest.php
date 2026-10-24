<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Captcha;

use App\CacheKeys\CacheKeys;
use App\Exceptions\ApiException;
use App\Services\Captcha\SliderCaptchaService;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * 滑塊驗證（4188）
 *
 * php artisan test tests/Unit/Services/Captcha/SliderCaptchaServiceTest.php
 */
class SliderCaptchaServiceTest extends TestCase
{
    private function service(): SliderCaptchaService
    {
        return App::make(SliderCaptchaService::class);
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function newQuestion(): array
    {
        $question = $this->service()->generate();
        $captchaId = (string) $question['captcha_id'];

        // 答案只在 Redis，不能出現在回應裡
        $this->assertStringNotContainsString('answer', json_encode($question, JSON_THROW_ON_ERROR));

        return [$captchaId, (int) Redis::get(CacheKeys::sliderCaptchaAnswer($captchaId))];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Redis::del(CacheKeys::sliderCaptchaFailCount('0.0.0.0'));
    }

    public function test_容許誤差內判定通過並取得一次性_token(): void
    {
        [$captchaId, $answerX] = $this->newQuestion();

        $result = $this->service()->verify($captchaId, $answerX + 8);
        $token = [REDACTED] $result['captcha_token'];

        $this->assertTrue($this->service()->consumeToken($token));
        // token 用一次即失效
        $this->assertFalse($this->service()->consumeToken($token));
    }

    public function test_誤差過大判定失敗(): void
    {
        [$captchaId, $answerX] = $this->newQuestion();

        $this->expectException(ApiException::class);
        $this->service()->verify($captchaId, $answerX + 9);
    }

    public function test_同一題只能作答一次(): void
    {
        [$captchaId, $answerX] = $this->newQuestion();

        // 先錯一次，該題即銷毀，正確答案也不能再試
        try {
            $this->service()->verify($captchaId, $answerX + 50);
        } catch (ApiException) {
        }

        $this->expectException(ApiException::class);
        $this->service()->verify($captchaId, $answerX);
    }

    public function test_同_IP_失敗超過上限即封鎖(): void
    {
        for ($i = 0; $i < 10; $i++) {
            [$captchaId, $answerX] = $this->newQuestion();
            try {
                $this->service()->verify($captchaId, $answerX + 50);
            } catch (ApiException) {
            }
        }

        // 封鎖期間即使答對也不放行
        [$captchaId, $answerX] = $this->newQuestion();
        $this->expectExceptionCode(2037);
        $this->service()->verify($captchaId, $answerX);
    }

    public function test_空_token_一律不通過(): void
    {
        $this->assertFalse($this->service()->consumeToken(null));
        $this->assertFalse($this->service()->consumeToken(''));
        $this->assertFalse($this->service()->consumeToken('not-exists'));
    }
}
