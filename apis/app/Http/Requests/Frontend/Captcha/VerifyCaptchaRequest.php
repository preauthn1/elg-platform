<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\Captcha;

use App\Http\Requests\AbstractRequest;

class VerifyCaptchaRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * 域名
             *
             * @example example.invalid
             */
            'domain' => 'required|string',
            /**
             * 出題時取得的 captcha_id
             *
             * @example 9f2c1a7e-1c3d-4f5a-8b7c-2d1e3f4a5b6c
             */
            'captcha_id' => 'required|string|max:64',
            /**
             * 拼圖塊左緣的邏輯座標（以 bg_width 為基準）
             *
             * @example 191
             */
            'x' => 'required|integer|between:0,1000',
        ];
    }
}
