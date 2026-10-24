<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\BigPayz;

use App\Exceptions\ApiException;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositCallbackResponseData;
use Illuminate\Http\Request;

/**
 * BigPayz deposit callback router: detects payload (ItemID vs RefId) and delegates to PayinDepositCallback or DuitNowDepositCallback.
 * Handler is created only when needed (no init of both in constructor).
 */
class DepositCallback extends AbstractMethod
{
    use BigPayzTrait;

    public function __construct(protected AgentCashFlow $agentCashFlow) {}

    public function handle(Request $request): DepositCallbackResponseData
    {
        $input = $request->all();
        $format = $this->detectPayinCallbackFormat($input);
        if ($format === null) {
            throw new ApiException(sprintf(
                '%s deposit callback unknown format: expected ItemID (Payin) or RefId (DuitNow) in payload',
                $this->getDriverName()
            ));
        }

        return match ($format) {
            'duitnow' => (new DuitNowDepositCallback($this->agentCashFlow))->handle($request),
            'payin_v2' => (new PayinDepositCallback($this->agentCashFlow))->handle($request),
        };
    }
}
