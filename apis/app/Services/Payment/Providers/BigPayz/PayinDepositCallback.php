<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\BigPayz;

use App\Exceptions\ApiException;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositCallbackResponseData;
use Illuminate\Http\Request;

/**
 * BigPayz Payin V2 callback only: ItemID, transaction, status, signature2, created_at.
 * signature2 = hash_hmac(sha256, transaction + status + Currency + Amount + created_at, API Key).
 * status: 1=Success/Accepted, 2=Rejected, 3=Approved.
 */
class PayinDepositCallback extends AbstractMethod
{
    use BigPayzTrait;

    protected string|array $notifySuccessText = 'Operation Success';

    protected string|array $notifyFailText = 'Operation Failed';

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    private const REQUIRED_KEYS = ['ItemID', 'transaction', 'status', 'Amount', 'Currency', 'signature2', 'created_at'];

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(Request $request): DepositCallbackResponseData
    {
        $input = $request->all();
        $missing = array_filter(self::REQUIRED_KEYS, fn (string $key) => !array_key_exists($key, $input) || $input[$key] === '' || $input[$key] === null);
        if (count($missing) > 0) {
            throw new ApiException(sprintf('%s Payin deposit callback missing required parameters: %s', $this->getDriverName(), implode(', ', $missing)));
        }

        $config = $this->getConfig();
        if (!$this->verifyPayinCallbackSignatureV2Doc($input, $config)) {
            throw new ApiException(sprintf('%s Payin deposit callback signature verification failed', $this->getDriverName()));
        }

        $orderNo = (string) $input['ItemID'];
        $status = (int) $input['status'];
        $thirdPartyOrderNo = (string) $input['transaction'];

        return $this->processDepositCallback($input, $orderNo, $status, $thirdPartyOrderNo, 'Payin 代收回調');
    }
}
