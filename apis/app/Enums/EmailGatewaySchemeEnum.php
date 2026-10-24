<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * SMTP 連線協定
 *
 * @see \Illuminate\Mail\MailManager::createSmtpTransport()
 */
enum EmailGatewaySchemeEnum: string
{
    /** STARTTLS(587) */
    case SMTP = 'smtp';
    /** SSL(465) */
    case SMTPS = 'smtps';
}
