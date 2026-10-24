<?php

declare(strict_types=1);

namespace App\Enums;

enum SmsGatewayEnum: string
{
    case CODE_SMS_GET = 'sg';  // sms-get
    case CODE_SMS_WORKS = 'sw';  // sms-works
    case CODE_INFO_BIP = 'ib';  // Info Bip
    case CODE_1_CODE = 'oc';  // 1code
    case CODE_CHUANX_SMS = 'cx';  // chuanxsms
    case CODE_ICT = 'ict';  // ICT
    case CODE_BULK360 = 'bulk';  // Bulk360 / SMS360
    case CODE_BUKA = 'buka';  // buka
    case CODE_FONGAME = 'fg'; // Fongame
    case CODE_FRS = 'frs'; // FRS
    case CODE_COSMS = 'cosms'; // COSMS
    case CODE_N88 = 'n88'; // N88
    case CODE_LAAFFIC = 'laaffic'; // Laaffic
    case CODE_GROWIX = 'growix'; // growix
    case CODE_GROWIX_V2 = 'growixv2'; // growix v2
    case CODE_GROWIX_V2_WA = 'growixv2wa'; // growix v2 WhatsApp OTP
    case CODE_LOCAL = 'local';
    case CODE_TRF = 'trf'; // TraffiLink
    case CODE_TOPYING = 'topying'; // Topying @see http://example.invalid:1688/target/tickets/-/issues/2991
    case CODE_WHATSAPP = 'wa'; // WhatsApp
    case CODE_WUYO = 'wuyo'; // Wuyo

    /**
     * 是否為 WhatsApp 管道的簡訊商。新增 WhatsApp 商時只需在此登記，避免各處以字面值比對 code。
     */
    public function isWhatsAppChannel(): bool
    {
        return match ($this) {
            self::CODE_WHATSAPP, self::CODE_GROWIX_V2_WA => true,
            default => false,
        };
    }
}
