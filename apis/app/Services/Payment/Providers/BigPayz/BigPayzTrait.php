<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\BigPayz;

use App\Exceptions\DepositOrderException;
use App\Facades\BotNotify;
use App\Services\Payment\Data\DepositCallbackResponseData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * BigPayz API: Auth = HTTP Basic (API key as username, no password).
 * Hashes: hash_hmac('sha256', $string, $apiKey) → lowercase hex.
 *
 * Config is loaded via BigPayzConfig::fromInfo($this->info).
 *
 * @see https://example.invalid/apidocv1.asp
 */
trait BigPayzTrait
{
    protected function getConfig(): BigPayzConfig
    {
        return BigPayzConfig::fromInfo($this->info, $this->getDriverName());
    }

    /** Payin callback success response (JSON). */
    protected function successResponse(): array
    {
        return [
            'error_code' => 0,
            'message' => $this->notifySuccessText,
        ];
    }

    /** Payin/Payout callback fail response (JSON). */
    protected function failResponse(): array
    {
        return [
            'error_code' => 1,
            'message' => $this->notifyFailText,
        ];
    }

    /** Hash for DuitNow Start: merchant_code + ref_id + player_username + player_ip + currency_code + amount + client_url (API doc). */
    protected function generateDuitNowHash(
        string $merchantCode,
        string $refId,
        string $playerUsername,
        string $playerIp,
        string $currencyCode,
        string $amount,
        string $clientUrl,
        string $apiKey
    ): string {
        $string = $merchantCode . $refId . $playerUsername . $playerIp . $currencyCode . $amount . $clientUrl;

        return hash_hmac('sha256', $string, $apiKey);
    }

    /** Normalize redirect_to: BigPayz may return "redirectlink=https://..." → use only the URL. */
    protected function normalizeRedirectUrl(string $redirectTo): string
    {
        $prefix = 'redirectlink=';
        if (str_starts_with($redirectTo, $prefix)) {
            return substr($redirectTo, strlen($prefix));
        }

        return $redirectTo;
    }

    /** Hash for Payin Start: MerchantCode + ItemID + Currency + Amount (API doc). */
    protected function generatePayinHash(
        string $merchantCode,
        string $itemId,
        string $currency,
        string $amount,
        string $apiKey
    ): string {
        $string = $merchantCode . $itemId . $currency . $amount;

        return hash_hmac('sha256', $string, $apiKey);
    }

    /**
     * Determine callback type by payload: ItemID → Payin V2, RefId → DuitNow.
     *
     * @return 'duitnow'|'payin_v2'|null
     */
    protected function detectPayinCallbackFormat(array $input): ?string
    {
        if (array_key_exists('ItemID', $input)) {
            return 'payin_v2';
        }
        if (array_key_exists('RefId', $input)) {
            return 'duitnow';
        }

        return null;
    }

    /** @deprecated Use detectPayinCallbackFormat() === 'duitnow' */
    protected function isPayinCallbackRealFormat(array $input): bool
    {
        return $this->detectPayinCallbackFormat($input) === 'duitnow';
    }

    /**
     * Verify Payin V2 callback signature (doc format).
     * API doc: signature2 = hash_hmac(sha256, 'transaction_id + status code + Currency + Amount + created_at', API Key).
     */
    protected function verifyPayinCallbackSignatureV2Doc(array $input, BigPayzConfig $config): bool
    {
        $received = $input['signature2'];
        $string = (string) $input['transaction']
            . (string) $input['status']
            . (string) $input['Currency']
            . (string) $input['Amount']
            . (string) $input['created_at'];
        $expected = hash_hmac('sha256', $string, $config->apiKey);

        return hash_equals($expected, $received);
    }

    /**
     * Verify Payin callback signature (real API format, DuitNow-style).
     * Real callback: Signature = hash_hmac(sha256, InvoiceNo + ReceiverBank + ReceiverAccount + Currency + Amount + PaymentDate, API Key).
     */
    protected function verifyPayinCallbackSignatureReal(array $input, BigPayzConfig $config): bool
    {
        $received = $input['Signature'];
        $string = (string) $input['InvoiceNo']
            . (string) $input['ReceiverBank']
            . (string) $input['ReceiverAccount']
            . (string) $input['Currency']
            . (string) $input['Amount']
            . (string) $input['PaymentDate'];
        $expected = hash_hmac('sha256', $string, $config->apiKey);

        return hash_equals($expected, $received);
    }

    /**
     * Shared deposit callback processing: find order, check status, update success/failed.
     * Used by PayinDepositCallback and DuitNowDepositCallback.
     */
    protected function processDepositCallback(array $input, string $orderNo, int $status, string $thirdPartyOrderNo, string $logLabel = '代收回調'): DepositCallbackResponseData
    {
        try {
            DB::beginTransaction();

            $order = $this->findAndLockDepositOrder($orderNo);

            try {
                $this->checkDepositOrderStatus($order);
            } catch (DepositOrderException $e) {
                DB::rollBack();

                return DepositCallbackResponseData::from([
                    'response' => $e->getCode() === 200 ? $this->successResponse() : $this->failResponse(),
                    'order' => $order,
                    'errorMessage' => $e->getMessage(),
                    'httpStatusCode' => 200,
                ]);
            }

            $statusEnum = BigPayzPayinCallbackStatusCodeEnum::tryFrom($status);
            if ($statusEnum !== null && $statusEnum->isSuccess()) {
                $this->updateDepositOrderSuccess(
                    order: $order,
                    thirdPartyOrderNo: $thirdPartyOrderNo,
                    callbackData: $input,
                    amount: (string) $input['Amount']
                );
            } else {
                $this->updateDepositOrderFailed(
                    order: $order,
                    thirdPartyOrderNo: $thirdPartyOrderNo,
                    callbackData: $input
                );
            }

            DB::commit();

            return DepositCallbackResponseData::from([
                'response' => $this->successResponse(),
                'order' => $order,
                'httpStatusCode' => 200,
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error(sprintf('%s %s處理異常', $this->getDriverName(), $logLabel), [
                'order_no' => $orderNo,
                'error' => $e->getMessage(),
            ]);
            BotNotify::channel('error')->send(
                sprintf('%s deposit callback error: %s', $this->getDriverName(), $e->getMessage())
            );
            throw $e;
        }
    }

    /** Hash for Payout Start: merchant_code + ref_id + player_username + player_ip + currency_code + amount + bank_code + beneficiary_account + beneficiary_name (API doc). */
    protected function generatePayoutHash(
        string $merchantCode,
        string $refId,
        string $playerUsername,
        string $playerIp,
        string $currencyCode,
        string $amount,
        string $bankCode,
        string $beneficiaryAccount,
        string $beneficiaryName,
        string $apiKey
    ): string {
        $string = $merchantCode . $refId . $playerUsername . $playerIp . $currencyCode
            . $amount . $bankCode . $beneficiaryAccount . $beneficiaryName;

        return hash_hmac('sha256', $string, $apiKey);
    }

    /** Verify Payout callback: Signature = HMAC-SHA256(InvoiceNo + Status + Currency + Amount + PaymentDate, API Key). */
    protected function verifyPayoutCallbackSignature(array $input, BigPayzConfig $config): bool
    {
        $received = $input['Signature'];
        $invoiceNo = (string) $input['InvoiceNo'];
        $status = (string) $input['Status'];
        $currency = (string) $input['Currency'];
        $amount = (string) $input['Amount'];
        $paymentDate = (string) $input['PaymentDate'];

        $string = $invoiceNo . $status . $currency . $amount . $paymentDate;
        $expected = hash_hmac('sha256', $string, $config->apiKey);

        return hash_equals($expected, $received);
    }

    /** Send JSON request with HTTP Basic Auth (API key as username). Config init already ensures all API URLs are set. */
    protected function sendJsonRequest(string $path, array $params, string $apiKey, string $context = 'payin'): array
    {
        $config = $this->getConfig();
        $url = $config->getBaseUrlByContext($context) . $path;
        $action = $this->getDriverName() . '_sendJsonRequest';

        $response = Http::logAction($action)
            ->asJson()
            ->withBasicAuth($apiKey, '')
            ->post($url, $params);

        return $response->throw()->json();

    }
}
