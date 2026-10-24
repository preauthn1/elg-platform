<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Exceptions\ApiException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\Captcha\VerifyCaptchaRequest;
use App\Http\Requests\Frontend\DomainRequest;
use App\Services\Captcha\SliderCaptchaService;
use Dedoc\Scramble\Attributes\Group;

#[Group('Captcha (滑塊驗證)')]
class CaptchaController extends Controller
{
    public function __construct(
        protected SliderCaptchaService $captchaService,
    ) {}

    /**
     * 出題（滑塊驗證）
     *
     * 回傳已挖缺口的背景圖與拼圖塊；缺口座標只存後端，回應不含答案。
     */
    public function create(DomainRequest $request)
    {
        return ApiResponse::success($this->captchaService->generate());
    }

    /**
     * 判定（滑塊驗證）
     *
     * 通過回傳一次性 captcha_token，帶入發送驗證碼 API；不論成敗該題即失效。
     *
     * @throws ApiException
     */
    public function verify(VerifyCaptchaRequest $request)
    {
        $input = $request->validated();

        return ApiResponse::success($this->captchaService->verify($input['captcha_id'], (int) $input['x']));
    }
}
