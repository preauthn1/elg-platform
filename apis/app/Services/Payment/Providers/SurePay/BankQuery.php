<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\SurePay;

use App\Exceptions\ApiException;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use Illuminate\Support\Facades\Http;

/**
 * 銀行帳號查詢（api.md §8，POST /v1/bankquery）：查商戶合約下所有通道碼（iban）、
 * 銀行短代碼、代收／代付別與三方端真實限額。
 *
 * ! 唯讀查詢，不建單不動金額。回應含 accname／bankaccno（PII），呼叫端入 log／DTO
 * ! 前必過遮罩。
 * ! 不沿用 SurePayTrait::sendRequest()——該共用鏈沒有 Bearer header 也沒有 ->throw()。
 * ! 憑證採 SCREAMING_SNAKE（API_URL＋API_KEY，與代收代付模組一致；Balance 的
 * ! camelCase 是孤例不跟），且只檢核這兩欄——共用 validateConfig 會多要 CONTRACT_NAME
 * ! 等本查詢用不到的欄位，製造假性失敗。
 * ! 真實回應的外層結構與錯誤格式規格未載明，首次串接以實測為準並回填 integration 文件。
 */
class BankQuery extends AbstractMethod
{
    use SurePayTrait;

    public function __construct(
        protected AgentCashFlow $agentCashFlow
    ) {
        $this->setInfo($agentCashFlow->info);
    }

    /**
     * @return list<array<string, mixed>> 三方回傳的通道列
     */
    public function handle(): array
    {
        $apiUrl = (string) ($this->info['API_URL'] ?? '');
        $apiKey = [REDACTED] ($this->info['API_KEY'] ?? '');

        if ($apiUrl === '' || $apiKey === '') {
            throw new ApiException('SurePay bank query requires API_URL and API_KEY');
        }

        // maskKeys 必帶：logAction 在回應返回當下就把 body 寫進 log，等 DTO 層才遮已經太晚。
        // maskSensitive 會依 MaskTrait 的部分遮罩 profile 呈現（帳號前 3 後 4、戶名頭尾字）
        $result = Http::logAction($this->getDriverName() . '_bankQuery', maskKeys: ['bankaccno', 'accname'])
            ->withToken($apiKey)
            ->asJson()
            ->post(rtrim($apiUrl, '/') . '/v1/bankquery')
            ->throw()
            ->json();

        // fail-closed：規格未載明錯誤格式，任何非「通道列清單」的形狀（含關聯陣列的
        // 錯誤 envelope）一律拋出，不得靜默映射成空快照讓呼叫端誤判「商戶零通道」
        if (!is_array($result) || !array_is_list($result)) {
            throw new ApiException('SurePay bank query returned unexpected response shape');
        }

        foreach ($result as $row) {
            if (!is_array($row)) {
                throw new ApiException('SurePay bank query returned unexpected row shape');
            }
        }

        return $result;
    }
}
