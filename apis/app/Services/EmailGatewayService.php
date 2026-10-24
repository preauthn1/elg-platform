<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EmailSendStatusEnum;
use App\Helpers\Common;
use App\Models\EmailGateway;
use App\Models\EmailSendRecord;
use App\Services\Mail\MailerFactory;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 電子郵件提供商發送
 */
class EmailGatewayService
{
    /** 未設定提供商時的寄件位址 */
    private const LEGACY_FROM_ADDRESS = 'user@example.invalid';

    public function __construct(
        private readonly MailerFactory $mailerFactory,
    ) {}

    /**
     * 取得組別下啟用中的提供商，依 sort 再 id 挑選
     *
     * 代理未指派組別時傳入 0，不可用來比對
     */
    public function getEnabledGateway(int $agentGroupId): ?EmailGateway
    {
        if ($agentGroupId <= 0) {
            return null;
        }

        return EmailGateway::query()
            ->enabled()
            ->where('agent_group_id', $agentGroupId)
            ->orderBy('sort')
            ->orderBy('id')
            ->first();
    }

    /**
     * 發送郵件並寫入寄送紀錄
     *
     * @throws Throwable 寄送失敗時原樣拋出
     */
    public function send(
        int $agentGroupId,
        string $receiver,
        string $subject,
        string $content,
        int $agentId = 0,
        int $memberId = 0,
    ): void {
        $gateway = $this->withoutBreakingSend(
            fn () => $this->getEnabledGateway($agentGroupId),
            'gateway lookup',
        );

        [$fromAddress, $fromName] = $this->resolveFrom($gateway);

        $record = $this->withoutBreakingSend(
            fn () => EmailSendRecord::create([
                'email_gateway_id' => $gateway->id ?? 0,
                'agent_id' => $agentId,
                'member_id' => $memberId,
                'sender' => $fromAddress,
                'receiver' => $receiver,
                'subject' => $subject,
                'message_content' => $content,
            ]),
            'record create',
        );

        try {
            $this->dispatchMail(
                gateway: $gateway,
                receiver: $receiver,
                subject: $subject,
                content: $content,
                fromAddress: $fromAddress,
                fromName: $fromName,
            );
        } catch (Throwable $e) {
            $this->withoutBreakingSend(
                fn () => $record?->update([
                    'status' => EmailSendStatusEnum::FAILED->value,
                    'response_message' => $e->getMessage(),
                ]),
                'record update',
            );

            Log::error('email send failed', [
                'email_send_record_id' => $record->id ?? 0,
                'email_gateway_id' => $gateway->id ?? 0,
                'agent_group_id' => $agentGroupId,
                'agent_id' => $agentId,
                'receiver' => Common::maskData($receiver, 'email'),
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            throw $e;
        }

        $this->withoutBreakingSend(
            fn () => $record?->update(['status' => EmailSendStatusEnum::SUCCESS->value]),
            'record update',
        );
    }

    /**
     * 組出提供商對應的 mailer 設定
     *
     * @return array<string, mixed>
     *
     * @see \Illuminate\Mail\MailManager::createSmtpTransport()
     */
    public function buildMailerConfig(EmailGateway $gateway): array
    {
        return [
            'transport' => 'smtp',
            'host' => $gateway->host,
            'port' => $gateway->port,
            'username' => $gateway->email,
            'password' => $gateway->password,
            // Laravel 13 只認 scheme，不吃 encryption
            'scheme' => $gateway->scheme,
            'local_domain' => config('mail.mailers.smtp.local_domain'),
            'timeout' => 10,
        ];
    }

    /**
     * 依提供商設定發送郵件，未設定則走系統預設 mailer
     *
     * @throws Throwable
     */
    private function dispatchMail(
        ?EmailGateway $gateway,
        string $receiver,
        string $subject,
        string $content,
        string $fromAddress,
        ?string $fromName,
    ): void {
        $mailer = $gateway === null
            ? $this->mailerFactory->default()
            : $this->mailerFactory->fromConfig($this->buildMailerConfig($gateway));

        $mailer->raw($content, function (Message $message) use ($receiver, $subject, $fromAddress, $fromName) {
            $message->subject($subject)
                ->to($receiver);

            if ($fromAddress !== '') {
                $message->from($fromAddress, $fromName);
            }
        });
    }

    /**
     * 取得寄件人位址與顯示名稱
     *
     * @return array{0: string, 1: ?string}
     */
    private function resolveFrom(?EmailGateway $gateway): array
    {
        $configName = config('mail.from.name');
        $fromName = is_string($configName) ? $configName : null;

        if ($gateway === null) {
            return [self::LEGACY_FROM_ADDRESS, $fromName];
        }

        return [$gateway->email, $gateway->from_name !== '' ? $gateway->from_name : $fromName];
    }

    /**
     * 執行寄送紀錄相關的 DB 操作，失敗只記 warning 不中斷寄信
     */
    private function withoutBreakingSend(callable $callback, string $action): mixed
    {
        return rescue(
            $callback,
            function (Throwable $e) use ($action) {
                Log::warning('email send ' . $action . ' failed', [
                    'error' => $e->getMessage(),
                ]);

                return null;
            },
            report: false,
        );
    }
}
