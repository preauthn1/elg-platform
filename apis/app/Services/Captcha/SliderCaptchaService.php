<?php

declare(strict_types=1);

namespace App\Services\Captcha;

use App\CacheKeys\CacheKeys;
use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\General\CacheTtl;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Agent;
use App\Services\AgentService;
use GdImage;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * 滑塊拼圖驗證（4188）
 *
 * 缺口位置只存在後端 Redis，API 回應一律不含答案；
 * 同一 captcha_id 不論成敗都只能作答一次，captcha_token 也是用一次即失效。
 */
class SliderCaptchaService
{
    /** 背景圖邏輯尺寸（px），前端依此換算縮放 */
    private const int BG_WIDTH = 300;

    private const int BG_HEIGHT = 160;

    /** 拼圖塊邊長（px） */
    private const int PIECE_SIZE = 62;

    /** 判定容許誤差（px）：行動裝置觸控較粗，太嚴會讓真人一直失敗 */
    private const int TOLERANCE = 8;

    /** 同 IP 驗證失敗上限與統計視窗（秒） */
    private const int FAIL_LIMIT = 10;

    private const int FAIL_WINDOW = 600;

    public function __construct(
        protected AgentService $agentService,
    ) {}

    /**
     * 出題：回傳背景圖（已挖缺口）與拼圖塊，答案 x 只寫進 Redis
     *
     * @return array<string, int|string>
     */
    public function generate(): array
    {
        $background = $this->makeBackground();

        // 缺口避開左側起點與右側邊界，否則玩家不用滑就中
        $answerX = random_int(self::PIECE_SIZE + 20, self::BG_WIDTH - self::PIECE_SIZE - 10);
        $pieceY = random_int(10, self::BG_HEIGHT - self::PIECE_SIZE - 10);

        $piece = $this->cutPiece($background, $answerX, $pieceY);
        $this->punchHole($background, $answerX, $pieceY);

        $captchaId = Str::uuid()->toString();
        Redis::setex(CacheKeys::sliderCaptchaAnswer($captchaId), CacheTtl::JIFFY->value, (string) $answerX);

        return [
            'captcha_id' => $captchaId,
            'bg_image' => $this->toDataUri($background, 'jpeg'),
            'piece_image' => $this->toDataUri($piece, 'png'),
            'bg_width' => self::BG_WIDTH,
            'bg_height' => self::BG_HEIGHT,
            'piece_width' => self::PIECE_SIZE,
            'piece_y' => $pieceY,
            'expires_in' => CacheTtl::JIFFY->value,
        ];
    }

    /**
     * 判定：命中回傳一次性 captcha_token
     *
     * @return array<string, int|string>
     *
     * @throws ApiException
     */
    public function verify(string $captchaId, int $x): array
    {
        $ip = Common::getIp();
        $this->assertNotBlocked($ip);

        $key = CacheKeys::sliderCaptchaAnswer($captchaId);
        $answer = Redis::get($key);
        // 一題只能答一次：不論成敗立刻銷毀，否則同一題可以無限次試 x
        Redis::del($key);

        if (!is_numeric($answer) || abs((int) $answer - $x) > self::TOLERANCE) {
            $this->increaseFailCount($ip);

            throw new ApiException(__('error_message.2080'), 2080);
        }

        $token = [REDACTED];
        Redis::setex(CacheKeys::sliderCaptchaToken($token), CacheTtl::DEFAULT->value, '1');

        return [
            'captcha_token' => $token,
            'expires_in' => CacheTtl::DEFAULT->value,
        ];
    }

    /**
     * 發送驗證碼前的閘門（4188）：代理未開啟開關就不檢查，開啟則必須帶有效的一次性 token
     *
     * @throws ApiException
     */
    public function assertVerified(Agent $agent, ?string $token): void
    {
        $enabled = (int) $this->agentService->getSpecificAgentSetting(
            $agent,
            AgentCustomizedSettingKeyEnum::SEND_CODE_CAPTCHA_ENABLED,
        ) === 1;

        if ($enabled && !$this->consumeToken($token)) {
            throw new ApiException(__('error_message.2081'), 2081);
        }
    }

    /**
     * 用掉 captcha_token；del 回傳筆數即「是否真的存在」，天生一次性且無競態
     */
    public function consumeToken(?string $token): bool
    {
        if (blank($token)) {
            return false;
        }

        return (int) Redis::del(CacheKeys::sliderCaptchaToken($token)) > 0;
    }

    /**
     * @throws ApiException
     */
    private function assertNotBlocked(string $ip): void
    {
        if ((int) Redis::get(CacheKeys::sliderCaptchaFailCount($ip)) >= self::FAIL_LIMIT) {
            throw new ApiException(__('error_message.2037', ['values' => self::FAIL_LIMIT]), 2037);
        }
    }

    private function increaseFailCount(string $ip): void
    {
        $key = CacheKeys::sliderCaptchaFailCount($ip);
        if ((int) Redis::incr($key) === 1) {
            Redis::expire($key, self::FAIL_WINDOW);
        }
    }

    /**
     * 隨機生成背景，不用固定圖庫（省素材維護，也沒有「同一張圖被學走」的風險）
     */
    private function makeBackground(): GdImage
    {
        $background = imagecreatetruecolor(self::BG_WIDTH, self::BG_HEIGHT);
        imagefilledrectangle(
            $background,
            0,
            0,
            self::BG_WIDTH,
            self::BG_HEIGHT,
            imagecolorallocate($background, random_int(60, 160), random_int(60, 160), random_int(60, 160)),
        );

        for ($i = 0; $i < 12; $i++) {
            imagefilledellipse(
                $background,
                random_int(0, self::BG_WIDTH),
                random_int(0, self::BG_HEIGHT),
                random_int(30, 120),
                random_int(30, 120),
                imagecolorallocate($background, random_int(0, 255), random_int(0, 255), random_int(0, 255)),
            );
        }

        return $background;
    }

    /**
     * 從背景挖圖前先裁出拼圖塊
     *
     * ponytail: 方形塊，不做拼圖凸耳。凸耳只影響美觀，答案不在 response 裡，擋 bot 的是判定端；要更像市面樣式再補遮罩。
     */
    private function cutPiece(GdImage $background, int $x, int $y): GdImage
    {
        $piece = imagecreatetruecolor(self::PIECE_SIZE, self::PIECE_SIZE);
        imagecopy($piece, $background, 0, 0, $x, $y, self::PIECE_SIZE, self::PIECE_SIZE);
        imagerectangle($piece, 0, 0, self::PIECE_SIZE - 1, self::PIECE_SIZE - 1, imagecolorallocate($piece, 255, 255, 255));

        return $piece;
    }

    private function punchHole(GdImage $background, int $x, int $y): void
    {
        $right = $x + self::PIECE_SIZE - 1;
        $bottom = $y + self::PIECE_SIZE - 1;

        imagefilledrectangle($background, $x, $y, $right, $bottom, imagecolorallocate($background, 40, 40, 40));
        imagerectangle($background, $x, $y, $right, $bottom, imagecolorallocate($background, 255, 255, 255));
    }

    private function toDataUri(GdImage $image, string $format): string
    {
        ob_start();
        $format === 'jpeg' ? imagejpeg($image, null, 80) : imagepng($image);
        $binary = (string) ob_get_clean();

        return "data:image/{$format};base64," . base64_encode($binary);
    }
}
