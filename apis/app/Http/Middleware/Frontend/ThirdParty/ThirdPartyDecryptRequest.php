<?php

declare(strict_types=1);

namespace App\Http\Middleware\Frontend\ThirdParty;

use App\Enums\General\HttpCode;
use App\Exceptions\ApiException;
use App\Facades\AES;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Special middleware for third party requests
 */
class ThirdPartyDecryptRequest
{
    /**
     * @throws ApiException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $params = str_replace(' ', '+', $request->input('params'));
        $params = str_replace('\\', '', $params);

        // 1. 檢查Key是否正確
        if ($request->input('key') != md5($params . config('encrypt.third_party.md5'))) {
            throw new ApiException(
                message: 'Invalid key',
                code: HttpCode::HTTP_NOT_ACCEPTABLE->value
            );
        }

        // 2. 清空原始請求
        $request->replace([]);

        // 3. 解密結果覆寫請求
        $aes = AES::init(
            cipher: config('encrypt.third_party.cipher'),
            key: config('encrypt.third_party.key'),
            iv: config('encrypt.third_party.iv')
        );
        $param = json_decode($aes->decrypt($params), true);
        if (is_array($param)) {
            $request->merge($param);
        }

        return $next($request);
    }
}
