<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\CacheKeys\CacheKeys;
use App\Enums\General\CacheTtl;
use App\Enums\General\HttpCode;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\Frontend\GoogleAuth as GoogleAuthResources;
use App\Models\Member;
use App\Models\MemberAuthentication;
use App\Services\LoginService;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use PragmaRX\Google2FA\Google2FA;

#[Group('Google Auth (Google 2FA)')]
class GoogleAuthController extends Controller
{
    public function __construct(
        private LoginService $loginService,
    ) {}

    /**
     * 取得 Google 2FA 圖片
     *
     * OLD: /api/getGoogleAuthImage
     */
    public function getImage(Request $request)
    {
        $member = $this->getUserContext()->member;

        $key = CacheKeys::memberAuthGoogle($member->id);

        $data = Cache::remember($key, 10 * CacheTtl::ONE_MINUTE->value, function () use ($member) {

            $google2fa = new Google2FA;

            $auth = MemberAuthentication::where('member_id', $member->id)
                ->where('status', 1)
                ->first();

            if (is_object($auth) && $auth->secret != '') {
                $google_2fa_secret = [REDACTED];
                $google_2fa_image = $auth->image;
            } else {
                // 🔑 產生 secret（新版預設長度 32）
                $google_2fa_secret = [REDACTED];

                // 🔗 產生 otpauth URL
                $qrUrl = $google2fa->getQRCodeUrl(
                    $member->agent->organization->name,
                    $member->username,
                    $google_2fa_secret
                );

                // 🧩 產生 QRCode SVG
                $renderer = new ImageRenderer(
                    new RendererStyle(400),
                    new SvgImageBackEnd
                );

                $writer = new Writer($renderer);
                $google_2fa_image = $writer->writeString($qrUrl);

                // 👉 改成 100%
                $google_2fa_image = str_replace(
                    'width="400" height="400"',
                    'width="100%"',
                    $google_2fa_image
                );

                MemberAuthentication::create([
                    'member_id' => $member->id,
                    'type' => 1,
                    'secret' => $google_2fa_secret,
                    'image' => $google_2fa_image,
                    'status' => 1,
                    'admin' => 0,
                ]);
            }

            return [
                'image' => $google_2fa_image,
                'secret' => $google_2fa_secret,
            ];
        });

        return ApiResponse::success(
            GoogleAuthResources\GoogleAuthImageResource::make($data)
        );
    }

    #[HeaderParameter('Authorization', description: 'Token', required: false, type: 'string')]
    /**
     * 會員google驗證
     *
     * OLD: /api/member/googleAuth
     */
    public function auth(Request $request)
    {
        $username = $request->input('username');
        $secret = [REDACTED]'secret');

        $member = Member::where('username', $username)->first();

        // 帳號不存在時回與驗證失敗相同的錯誤，避免以 500／不同錯誤碼枚舉有效帳號
        if ($member === null) {
            return ApiResponse::error(__('error_message.9992'), HttpCode::HTTP_UNAUTHORIZED->value);
        }

        if ($this->loginService->auth2FA($member->id, $secret) !== true) {
            // 工單4146：累計錯誤次數，達代理設定的上限即鎖定帳號並登出
            if ($this->loginService->increaseGoogleAuthFailCount($member)) {
                return ApiResponse::error(__('error_message.90062'), HttpCode::HTTP_REDIRECT_LOGOUT->value);
            }

            return ApiResponse::error(__('error_message.9992'), HttpCode::HTTP_UNAUTHORIZED->value);
        }

        $this->loginService->resetGoogleAuthFailCount($member);

        return ApiResponse::success();
    }
}
