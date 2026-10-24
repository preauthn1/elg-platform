<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\FPay;

use App\Exceptions\ApiException;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use Illuminate\Support\Facades\Http;

/**
 * 代付銀行清單查詢（api.md「銀行列表查詢」，POST /wallet/withdraw_bank_list）：
 * 查這個商戶帳號在指定幣別下，FPay 目前接受哪些代付銀行，以及每家銀行的 bank_id。
 *
 * 用途：代付送出的 bank_id 取自 available_banks 的 BankCode_Third，而 FPay 文件載明 bank_id 必須以本查詢為準、
 * 不可寫死。代號對不上時三方只回「bank id invalid」，要靠本查詢才看得出是哪幾家對不上
 * （target/op_tickets#3174：TPower 帳號 2026-08-21 起代付全被退回，同期 Yes2Win 帳號正常）。
 *
 * ! 唯讀查詢，不建單、不動金額。
 * ! 依 api.md 與官方文件，只帶 username（＝MERCHANT_NO）與 currency，不需要 /merchant/auth 取得的 auth；
 * ! 因此只檢核這兩項所需的欄位，不多要 APP_KEY——多要欄位只會製造假性失敗。
 * ! 不沿用 FPayTrait::sendRequest()：它的回傳型別宣告為 array，三方回非 JSON 時會以 TypeError 中斷，
 * ! 排查者拿不到可讀的錯誤訊息；本類別自行驗證回應形狀並拋 ApiException。
 * ! fail-closed：status 不是 true、data 不是清單、清單裡有不是物件的列，一律拋出，
 * ! 不得靜默當成「這個帳號零家銀行」，否則排查者會誤判 FPay 關閉了該帳號的代付。
 * ! 真實回應尚未實測（2026-09-15 撰寫時），首次實打以實際結果為準，有落差回填 integration 文件。
 *
 * @knowledge rules/payout-bank-code-mapping
 */
class WithdrawBankList extends AbstractMethod
{
    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    /**
     * @return list<array<string, mixed>> 三方回傳的銀行列（currency／bank_name／id）
     */
    public function handle(): array
    {
        $apiUrl = (string) ($this->info['API_URL'] ?? '');
        $merchantNo = (string) ($this->info['MERCHANT_NO'] ?? '');
        $currencyCode = (string) ($this->agentCashFlow->currency?->code ?? '');

        if ($apiUrl === '' || $merchantNo === '' || $currencyCode === '') {
            throw new ApiException('FPay withdraw bank list requires API_URL, MERCHANT_NO and currency');
        }

        $result = Http::logAction($this->getDriverName() . '_withdrawBankList')
            ->asForm()
            ->post(rtrim($apiUrl, '/') . '/wallet/withdraw_bank_list', [
                'username' => $merchantNo,
                'currency' => $currencyCode,
            ])
            ->throw()
            ->json();

        if (!is_array($result) || ($result['status'] ?? null) !== true) {
            $message = is_array($result) && is_scalar($result['message'] ?? null)
                ? (string) $result['message']
                : 'unexpected response shape';

            throw new ApiException('FPay withdraw bank list rejected: ' . $message);
        }

        $rows = $result['data'] ?? null;

        if (!is_array($rows) || !array_is_list($rows)) {
            throw new ApiException('FPay withdraw bank list returned unexpected data shape');
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new ApiException('FPay withdraw bank list returned unexpected row shape');
            }
        }

        return $rows;
    }
}
