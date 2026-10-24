<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\BigPayz;

use App\Exceptions\ApiException;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositCallbackResponseData;
use Illuminate\Http\Request;

/**
 * BigPayz DuitNow callback only: RefId, InvoiceNo, Signature, ReceiverBank, ReceiverAccount, PaymentDate.
 * Signature = hash_hmac(sha256, InvoiceNo + ReceiverBank + ReceiverAccount + Currency + Amount + PaymentDate, API Key).
 * status: 1=Success/Accepted, 2=Rejected, 3=Approved.
 */
class DuitNowDepositCallback extends AbstractMethod
{
    use BigPayzTrait;

    protected string|array $notifySuccessText = 'Operation Success';

    protected string|array $notifyFailText = 'Operation Failed';

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    private const REQUIRED_KEYS = ['RefId', 'Status', 'Amount', 'Currency', 'Signature', 'PaymentDate', 'InvoiceNo', 'ReceiverBank', 'ReceiverAccount'];

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(Request $request): DepositCallbackResponseData
    {
        $input = $request->all();
        $missing = array_filter(self::REQUIRED_KEYS, fn (string $key) => !array_key_exists($key, $input) || $input[$key] === '' || $input[$key] === null);
        if ($missing !== []) {
            throw new ApiException(sprintf('%s DuitNow deposit callback missing required parameters: %s', $this->getDriverName(), implode(', ', $missing)));
        }

        $config = $this->getConfig();
        if (!$this->verifyPayinCallbackSignatureReal($input, $config)) {
            throw new ApiException(sprintf('%s DuitNow deposit callback signature verification failed', $this->getDriverName()));
        }

        $orderNo = (string) $input['RefId'];
        $status = (int) $input['Status'];
        $thirdPartyOrderNo = (string) $input['InvoiceNo'];

        return $this->processDepositCallback($input, $orderNo, $status, $thirdPartyOrderNo, 'DuitNow 代收回調');
    }
}
