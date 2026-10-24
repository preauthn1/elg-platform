<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\BillionPay;

use App\Enums\General\HttpCode;
use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Http;

trait BillionPayTrait
{
    protected string $signField = 'signature';

    protected ?string $token;

    const TYPE_BANK_TRANSFER = 'bank-transfer';

    const TYPE_QR_CODE = 'qr_code';

    const TYPE_UEN = 'uen';

    /**
     * @throws ApiException
     */
    protected function login(): void
    {
        $loginUrl = $this->info['LOGIN_URL'] ?? null;
        $username = $this->info['USERNAME'] ?? null;
        $password = [REDACTED]'PASSWORD'] ?? null;

        if (empty($loginUrl) || empty($username) || empty($password)) {
            throw new ApiException('Invalid BillionPay Login URL or Username or Password');
        }

        $response = $this->sendRequest($loginUrl, [
            'username' => $username,
            'password' => $password,
        ]);

        $this->token = [REDACTED]'bearer_token'] ?? null;

        if (empty($this->token)) {
            throw new ApiException($this->getDriverName() . ' Login failed!! ');
        }
    }

    /**
     * 發送請求
     */
    protected function sendRequest(string $url, array $params, array $headers = []): array
    {
        $action = $this->getDriverName() . '_sendRequest';

        if (!$this->isLoggingIn($url)) {
            $headers += ['Authorization' => 'Bearer ' . $this->token];
        }

        $result = Http::logAction($action)
            ->asForm()
            ->withHeaders($headers)
            ->post($url, $params)
            ->throw()
            ->json();

        return $result;
    }

    /**
     * 生成簽名
     *
     * 簽名規則：
     * 1. 排除 signature 欄位
     * 2. 排除值為 null、''、0、'0' 的參數
     * 3. 對參數進行 ASCII 升序排序
     * 4. json_encode 後末尾拼接 secret key
     * 5. MD5 加密（小寫）*
     *
     * @param  array  $params  請求參數
     * @param  string  $secret  密鑰
     * @return string 簽名字串
     */
    protected function generateSign(array $params, string $secret): string
    {
        // 排除 sign 欄位
        unset($params[$this->signField]);

        // 第二步：排除參數值為 0、空或 null 的
        $filtered = array_filter($params, fn ($value) => !in_array($value, [null, '', 0, '0'], true));

        // 第一步：對請求參數進行 ASCII 升序排序
        ksort($filtered);

        // 第三步：對參數進行 json_encode
        $signString = str_replace('//', '\\/\\/', json_encode($filtered) . $secret);
        // 調試信息（生產環境可移除）
        //        Log::debug('BillionPay Sign String  => ' . $signString);

        // 第四步：MD5 加密
        return md5($signString);
    }

    /**
     * 驗證簽名
     */
    protected function checkSign(array $params, string $secret): bool
    {
        $sign = $params[$this->signField] ?? '';

        if (empty($secret)) {
            throw new ApiException('Secret key not found in info', HttpCode::HTTP_INTERNAL_SERVER_ERROR->value);
        }

        $expected = $this->generateSign($params, $secret);

        return $expected === $sign;
    }

    protected function isLoggingIn(string $url): bool
    {
        return $url === $this->info['LOGIN_URL'];
    }
}
