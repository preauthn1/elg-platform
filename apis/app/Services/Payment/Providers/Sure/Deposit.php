<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\Sure;

use App\Enums\DepositResponseType;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;

class Deposit extends AbstractMethod
{
    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    // ! $agentCashFlow->info 應該是這樣的格式
    // {
    //   "APIUrl": "https://example.invalid",
    //   "ApiKey": "{API_KEY}",
    //   "MerchantID": "Surepay88",
    //   "ContractName": "Surepay88",
    //   "ReturnUrl": "https://example.invalid/",
    //   "CallBackApiKey": "{CALLBACK_API_KEY}",
    //   "Currency": "MYR"
    // }

    public function handle(DepositRequestData $request): DepositResponseData
    {
        $order = $request->order;

        $params = $this->buildDepositParams($request);
        $params['token'] = $this->generateToken($params);

        $url = trim($this->info['APIUrl'], '/');
        $html = $this->buildAutoSubmitForm($url, $params);

        return DepositResponseData::from([
            'type' => DepositResponseType::HTML_FORM,
            'html' => $html,
            'orderNo' => $order->order_no,
            'providerOrderNo' => '',
        ]);
    }

    /**
     * 構建代收請求參數
     */
    private function buildDepositParams(DepositRequestData $request): array
    {
        $member = $request->member;
        $order = $request->order;
        $callbackUrl = $request->callbackUrl;
        $returnUrl = $request->returnUrl;
        $clientIP = $request->ip;

        return [
            'merchant' => $this->info['MerchantID'],
            'contractname' => $this->info['ContractName'],
            'refid' => $order->order_no,
            'amount' => $order->amount,
            'customer' => $member->username,
            'currency' => $this->info['Currency'],
            'clientip' => $clientIP,
            'failed_return_url' => $returnUrl,
            'return_url' => $returnUrl,
            'post_url' => $callbackUrl,
        ];
    }

    private function generateToken(array $params): string
    {
        $string = $params['merchant'] .
            $params['amount'] .
            $params['refid'] .
            $params['customer'] .
            $this->info['ApiKey'] .
            $params['currency'] .
            $params['clientip'];

        // lad($string);

        return md5($string);
    }

    private function buildAutoSubmitForm(string $url, array $params): string
    {
        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Redirecting...</title></head><body>';
        $html .= '<form id="autoForm" method="post" action="' . $url . '">';
        foreach ($params as $key => $val) {
            $html .= '<input type="hidden" name="' . $key . '" value="' . $val . '" />';
        }
        $html .= '</form>';
        $html .= '<script>document.getElementById("autoForm").submit();</script>';
        $html .= '</body></html>';

        return $html;
    }
}
