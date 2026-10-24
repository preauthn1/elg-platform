<?php

declare(strict_types=1);

namespace App\Vendors\MegawinSW\Datas;

use Spatie\LaravelData\Data;

class MegaWinSWVendorInfoDetailData extends Data
{
    public function __construct(
        public string $SITE_URL,
        public string $SITE_ID,
        public string $MW_PUBLIC_KEY,
        public string $EC_PUBLIC_KEY,
        public string $EC_PRIVATE_KEY,
        public string $MW_CODE,
        public string $MERCHANT_ID,
        public string $FIRM_ID,
        public string $CURRENCY,
        public string $IS_TEST,
    ) {
        $this->MW_PUBLIC_KEY = $this->addPEMFormatPublic($MW_PUBLIC_KEY);
        $this->EC_PUBLIC_KEY = $this->addPEMFormatPublic($EC_PUBLIC_KEY);
        $this->EC_PRIVATE_KEY = $this->addPEMFormatPrivate($EC_PRIVATE_KEY);
    }

    private function addPEMFormatPublic($key)
    {
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split($key, 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private function addPEMFormatPrivate($key)
    {
        return "[PRIVATE_KEY_BLOCK_REDACTED]\n";
    }
}
