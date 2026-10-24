<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\VaderPay;

final class VaderPaySignature
{
    public function generateDepositHash(
        string $merchantCode,
        string $itemId,
        string $currency,
        string $amount,
        string $apiKey
    ): string {
        $payload = $merchantCode . $itemId . $currency . $amount;

        return hash_hmac('sha256', $payload, $apiKey);
    }

    public function generatePayoutHash(
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
        $payload = $merchantCode
            . $refId
            . $playerUsername
            . $playerIp
            . $currencyCode
            . $amount
            . $bankCode
            . $beneficiaryAccount
            . $beneficiaryName;

        return hash_hmac('sha256', $payload, $apiKey);
    }

    public function generateStatusHash(
        string $merchantCode,
        string $refId,
        string $apiKey
    ): string {
        $payload = $merchantCode . $refId;

        return hash_hmac('sha256', $payload, $apiKey);
    }

    public function generatePayinCallbackSignature(
        string $invoiceNo,
        string $receiverBank,
        string $receiverAccount,
        string $currency,
        string $amount,
        string $paymentDate,
        string $apiKey
    ): string {
        $payload = $invoiceNo
            . $receiverBank
            . $receiverAccount
            . $currency
            . $amount
            . $paymentDate;

        return hash_hmac('sha256', $payload, $apiKey);
    }

    public function generatePayinQrEwalletHash(
        string $merchantCode,
        string $refId,
        string $playerUsername,
        string $playerIp,
        string $currencyCode,
        string $amount,
        string $clientUrl,
        string $apiKey
    ): string {
        $payload = $merchantCode
            . $refId
            . $playerUsername
            . $playerIp
            . $currencyCode
            . $amount
            . $clientUrl;

        return hash_hmac('sha256', $payload, $apiKey);
    }

    public function generatePayoutCallbackSignature(
        string $invoiceNo,
        string $status,
        string $currency,
        string $amount,
        string $paymentDate,
        string $apiKey
    ): string {
        $payload = $invoiceNo
            . $status
            . $currency
            . $amount
            . $paymentDate;

        return hash_hmac('sha256', $payload, $apiKey);
    }
}
