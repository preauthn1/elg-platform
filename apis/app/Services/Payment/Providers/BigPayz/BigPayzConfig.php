<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\BigPayz;

use App\Exceptions\ApiException;

/**
 * BigPayz config loaded from agent_cash_flow.info.
 *
 * Keys: PAYIN_API_URL, PAYOUT_API_URL, DUITNOW_API_URL, MERCHANT_CODE, API_KEY
 */
readonly class BigPayzConfig
{
    public function __construct(
        public string $payinApiUrl,
        public string $payoutApiUrl,
        public string $duitnowApiUrl,
        public string $merchantCode,
        public string $apiKey,
    ) {}

    /**
     * Load and validate config from agent_cash_flow.info.
     *
     * @throws ApiException when any of PAYIN_API_URL, PAYOUT_API_URL, DUITNOW_API_URL, MERCHANT_CODE or API_KEY is missing
     */
    public static function fromInfo(array $info, string $driverName): self
    {
        $payinApiUrl = rtrim((string) ($info['PAYIN_API_URL'] ?? ''), '/');
        $payoutApiUrl = rtrim((string) ($info['PAYOUT_API_URL'] ?? ''), '/');
        $duitnowApiUrl = rtrim((string) ($info['DUITNOW_API_URL'] ?? ''), '/');
        $merchantCode = trim((string) ($info['MERCHANT_CODE'] ?? ''));
        $apiKey = [REDACTED] ($info['API_KEY'] ?? '');

        if ($payinApiUrl === '') {
            throw new ApiException(sprintf('%s PAYIN_API_URL is not set in agent_cash_flow.info', $driverName));
        }
        if ($payoutApiUrl === '') {
            throw new ApiException(sprintf('%s PAYOUT_API_URL is not set in agent_cash_flow.info', $driverName));
        }
        if ($duitnowApiUrl === '') {
            throw new ApiException(sprintf('%s DUITNOW_API_URL is not set in agent_cash_flow.info', $driverName));
        }
        if ($merchantCode === '' || $apiKey === '') {
            throw new ApiException(sprintf('%s credentials (MERCHANT_CODE, API_KEY) are missing', $driverName));
        }

        return new self($payinApiUrl, $payoutApiUrl, $duitnowApiUrl, $merchantCode, $apiKey);
    }

    private const VALID_CONTEXTS = ['payin', 'payout', 'duitnow'];

    /**
     * @return string base URL for the given context (payin | payout | duitnow)
     *
     * @throws ApiException when context is not payin, payout or duitnow
     */
    public function getBaseUrlByContext(string $context): string
    {
        if (!in_array($context, self::VALID_CONTEXTS, true)) {
            throw new ApiException(sprintf('Invalid BigPayz context: %s (allowed: payin, payout, duitnow)', $context));
        }

        return match ($context) {
            'payin' => $this->payinApiUrl,
            'payout' => $this->payoutApiUrl,
            'duitnow' => $this->duitnowApiUrl,
        };
    }
}
