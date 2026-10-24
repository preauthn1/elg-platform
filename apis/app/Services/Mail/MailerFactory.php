<?php

declare(strict_types=1);

namespace App\Services\Mail;

use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Mail;

/**
 * 建立 mailer 實例的薄封裝
 */
class MailerFactory
{
    /**
     * 依傳入設定建立當次專用 mailer
     *
     * @param  array<string, mixed>  $config
     */
    public function fromConfig(array $config): Mailer
    {
        return Mail::build($config);
    }

    /**
     * 系統預設 mailer
     */
    public function default(): Mailer
    {
        return Mail::mailer();
    }
}
