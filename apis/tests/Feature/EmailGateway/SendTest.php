<?php

declare(strict_types=1);

namespace Tests\Feature\EmailGateway;

use App\Enums\Agent\AgentGroupTypeEnum;
use App\Enums\EmailSendStatusEnum;
use App\Enums\StatusEnum;
use App\Models\Agent;
use App\Models\AgentGroup;
use App\Models\EmailGateway;
use App\Models\EmailSendRecord;
use App\Models\Member;
use App\Services\AgentService;
use App\Services\EmailGatewayService;
use App\Services\EmailTextService;
use App\Services\Mail\MailerFactory;
use App\Services\MemberAccessTokenService;
use App\Services\MemberFailCountService;
use App\Services\MemberService;
use App\Services\ValidateToken\AbstractValidationCodeService;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\Message;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * 電子郵件提供商挑選與寄送紀錄
 *
 * php artisan test tests/Feature/EmailGateway/SendTest.php
 */
class SendTest extends TestCase
{
    private Agent $agent;

    private AgentGroup $group;

    protected function setUp(): void
    {
        parent::setUp();

        $this->group = $this->createEmailGroup();
        $this->agent = $this->createAgentAndDomain(['email_gateway_group_id' => $this->group->id]);
    }

    // -------------------------------------------------------
    // Helpers
    // -------------------------------------------------------

    private function createEmailGroup(array $overrides = []): AgentGroup
    {
        return AgentGroup::create(array_merge([
            'agent_group_type' => AgentGroupTypeEnum::EMAIL_GATEWAY->value,
            'organization_id' => 1,
            'name' => 'email-group-' . Str::random(6),
            'memo' => '',
            'is_default' => 0,
        ], $overrides));
    }

    /**
     * 建立另一個代理（agent_domains.domain 為唯一鍵，需先換網域）
     */
    private function createOtherAgent(array $data = []): Agent
    {
        $this->domain = bin2hex(random_bytes(8)) . '.test.local';

        return $this->createAgentAndDomain($data);
    }

    private function createGateway(array $overrides = []): EmailGateway
    {
        return EmailGateway::create(array_merge([
            'agent_group_id' => $this->group->id,
            'email' => 'noreply' . Str::random(6) . '@example.invalid',
            'password' => 'secret',
            'host' => 'example.invalid',
            'port' => 587,
            'scheme' => 'smtp',
            'from_name' => '',
            'sort' => 0,
            'status' => StatusEnum::ENABLE->value,
            'admin' => 0,
        ], $overrides));
    }

    /**
     * 綁定 MailerFactory 替身
     */
    private function fakeMailerFactory(?RuntimeException $throw = null): MockInterface
    {
        $mailer = Mockery::mock(Mailer::class);

        if ($throw === null) {
            $mailer->shouldReceive('raw')->andReturn(null);
        } else {
            $mailer->shouldReceive('raw')->andThrow($throw);
        }

        return $this->mock(MailerFactory::class, function (MockInterface $factory) use ($mailer) {
            $factory->shouldReceive('fromConfig')->andReturn($mailer);
            $factory->shouldReceive('default')->andReturn($mailer);
        });
    }

    private function service(): EmailGatewayService
    {
        return app(EmailGatewayService::class);
    }

    // -------------------------------------------------------
    // sendMail 接線（Agent 決定組別、Member 為選填）
    // -------------------------------------------------------

    /**
     * 取得可呼叫 protected sendMail() 的探針
     */
    private function sendMailProbe(): object
    {
        return new class(app(AgentService::class), app(MemberService::class), app(MemberAccessTokenService::class), app(EmailGatewayService::class), app(EmailTextService::class), app(MemberFailCountService::class)) extends AbstractValidationCodeService
        {
            public function call(string $email, string $text = '', ?Agent $agent = null, ?Member $member = null): bool
            {
                return $this->sendMail($email, $text, $agent, $member);
            }

            public function callValidate(string $email, string $token, ?Member $member = null): void
            {
                $this->sendValidateMail($email, $token, $member);
            }
        };
    }

    /**
     * 註冊情境：只有會員(含一頁式註冊的假會員)時，代理應由 member->agent 帶出。
     */
    public function test_send_validate_mail_fills_agent_from_member(): void
    {
        // Arrange
        $gateway = $this->createGateway();
        $this->fakeMailerFactory();
        $member = $this->createMember($this->agent);

        // Act
        $this->sendMailProbe()->callValidate('user@example.invalid', '123456', $member);

        // Assert
        $record = EmailSendRecord::latest('id')->first();
        $this->assertSame($gateway->id, $record->email_gateway_id);
        $this->assertSame($this->agent->id, $record->agent_id);
        $this->assertSame($member->id, $record->member_id);
    }

    /**
     * 傳入代理時應使用該代理所屬組別的提供商，Member 不傳也能寄。
     */
    public function test_send_mail_uses_agent_group_without_member(): void
    {
        // Arrange
        $gateway = $this->createGateway();
        $this->fakeMailerFactory();

        // Act — 只給 agent，不給 member
        $this->sendMailProbe()->call('user@example.invalid', 'The PIN is 123456', $this->agent);

        // Assert
        $record = EmailSendRecord::where('receiver', 'user@example.invalid')->first();
        $this->assertNotNull($record);
        $this->assertSame($gateway->id, $record->email_gateway_id);
        $this->assertSame($this->agent->id, $record->agent_id);
        // 沒有會員時 member_id 留 0
        $this->assertSame(0, $record->member_id);
    }

    /**
     * 有給 Member 時應記進紀錄，組別仍由 agent 決定。
     */
    public function test_send_mail_records_member_when_given(): void
    {
        // Arrange
        $gateway = $this->createGateway();
        $member = $this->createMember($this->agent);
        $this->fakeMailerFactory();

        // Act
        $this->sendMailProbe()->call('user@example.invalid', 'The PIN is 123456', $this->agent, $member);

        // Assert
        $record = EmailSendRecord::where('receiver', 'user@example.invalid')->first();
        $this->assertNotNull($record);
        $this->assertSame($gateway->id, $record->email_gateway_id);
        $this->assertSame($member->id, $record->member_id);
    }

    /**
     * agent 為 null 時仍應寄出，退回系統預設 mailer。
     */
    public function test_send_mail_falls_back_when_agent_is_null(): void
    {
        // Arrange
        $this->createGateway();
        $factory = $this->fakeMailerFactory();

        // Act
        $this->sendMailProbe()->call('user@example.invalid', 'The PIN is 123456', null);

        // Assert
        $factory->shouldHaveReceived('default');
        $factory->shouldNotHaveReceived('fromConfig');

        $record = EmailSendRecord::where('receiver', 'user@example.invalid')->first();
        $this->assertNotNull($record);
        $this->assertSame(0, $record->email_gateway_id);
        $this->assertSame(0, $record->agent_id);
        $this->assertSame(EmailSendStatusEnum::SUCCESS->value, $record->status);
    }

    /**
     * 只給 $email / $text（舊呼叫方式）仍應正常寄出。
     */
    public function test_send_mail_still_works_with_legacy_argument_list(): void
    {
        // Arrange
        $this->createGateway();
        $factory = $this->fakeMailerFactory();

        // Act — 舊簽章的呼叫方式
        $this->sendMailProbe()->call('user@example.invalid', 'The PIN is 123456');

        // Assert — 走系統預設 mailer，等同接上提供商之前的行為
        $factory->shouldHaveReceived('default');
        $factory->shouldNotHaveReceived('fromConfig');

        $record = EmailSendRecord::where('receiver', 'user@example.invalid')->first();
        $this->assertNotNull($record);
        $this->assertSame(0, $record->email_gateway_id);
        $this->assertSame(0, $record->agent_id);
        $this->assertSame(0, $record->member_id);
        $this->assertSame(EmailSendStatusEnum::SUCCESS->value, $record->status);
    }

    /**
     * 代理未指派組別時退回系統預設 mailer。
     */
    public function test_send_mail_falls_back_when_agent_has_no_group(): void
    {
        // Arrange
        $unassigned = $this->createOtherAgent(['email_gateway_group_id' => null]);
        $this->createGateway();
        $factory = $this->fakeMailerFactory();

        // Act
        $this->sendMailProbe()->call('user@example.invalid', 'The PIN is 123456', $unassigned);

        // Assert
        $factory->shouldHaveReceived('default');
        $factory->shouldNotHaveReceived('fromConfig');
        $this->assertSame(
            0,
            EmailSendRecord::where('receiver', 'user@example.invalid')->value('email_gateway_id'),
        );
    }

    // -------------------------------------------------------
    // 提供商挑選
    // -------------------------------------------------------

    /**
     * 同組別多筆啟用時，應依 sort 由小到大挑選。
     */
    public function test_picks_enabled_gateway_with_smallest_sort(): void
    {
        // Arrange
        $this->createGateway(['sort' => 5]);
        $first = $this->createGateway(['sort' => 1]);

        // Act
        $picked = $this->service()->getEnabledGateway($this->group->id);

        // Assert
        $this->assertSame($first->id, $picked?->id);
    }

    /**
     * sort 相同時取較早建立（id 較小）者。
     */
    public function test_picks_earliest_gateway_when_sort_ties(): void
    {
        // Arrange
        $earlier = $this->createGateway(['sort' => 0]);
        $this->createGateway(['sort' => 0]);

        // Act & Assert
        $this->assertSame($earlier->id, $this->service()->getEnabledGateway($this->group->id)?->id);
    }

    /**
     * 停用的提供商不應被挑中。
     */
    public function test_ignores_disabled_gateway(): void
    {
        // Arrange
        $this->createGateway(['status' => StatusEnum::DISABLE->value]);

        // Act & Assert
        $this->assertNull($this->service()->getEnabledGateway($this->group->id));
    }

    /**
     * 提供商依組別隔離，不應取到別的組別的設定。
     */
    public function test_does_not_pick_other_groups_gateway(): void
    {
        // Arrange
        $otherGroup = $this->createEmailGroup();
        $this->createGateway(['agent_group_id' => $otherGroup->id]);

        // Act & Assert
        $this->assertNull($this->service()->getEnabledGateway($this->group->id));
    }

    /**
     * group_id 0 必須視為「未指派」而非有效查詢條件。
     */
    public function test_group_id_zero_never_matches_any_gateway(): void
    {
        // Arrange — 直接塞一筆 agent_group_id = 0 的設定
        $this->createGateway(['agent_group_id' => 0]);

        // Act & Assert
        $this->assertNull($this->service()->getEnabledGateway(0));
        $this->assertNull($this->service()->getEnabledGateway(-1));
    }

    // -------------------------------------------------------
    // mailer 設定
    // -------------------------------------------------------

    /**
     * mailer 設定須用 scheme、密碼為解密後原文，並帶上連線參數。
     */
    public function test_mailer_config_uses_scheme_and_decrypted_password(): void
    {
        // Arrange
        $gateway = $this->createGateway([
            'email' => 'user@example.invalid',
            'password' => 'plain-secret',
            'host' => 'smtp.mail.test',
            'port' => 465,
            'scheme' => 'smtps',
        ]);

        // Act
        $config = $this->service()->buildMailerConfig($gateway);

        // Assert
        $this->assertSame('smtp', $config['transport']);
        $this->assertSame('smtp.mail.test', $config['host']);
        $this->assertSame(465, $config['port']);
        $this->assertSame('user@example.invalid', $config['username']);
        $this->assertSame('plain-secret', $config['password']);
        $this->assertSame('smtps', $config['scheme']);
        $this->assertArrayNotHasKey('encryption', $config);
        // 逾時須有上限
        $this->assertIsInt($config['timeout']);
        $this->assertArrayHasKey('local_domain', $config);
    }

    // -------------------------------------------------------
    // 郵件內容（未設定提供商時必須與線上完全一致）
    // -------------------------------------------------------

    /**
     * 未設定提供商時的寄件位址／主旨／收件人。
     */
    public function test_message_matches_legacy_behaviour_when_no_gateway(): void
    {
        // Arrange
        $message = $this->captureSentMessage();

        // Act — 組別未指派任何提供商
        $this->service()->send(
            agentGroupId: $this->group->id,
            receiver: 'user@example.invalid',
            subject: 'Verification Code',
            content: 'The PIN is 123456',
        );

        // Assert — 與 origin/master 的 sendMail() 逐項相同
        $email = $message();
        $this->assertNotNull($email);
        $this->assertSame('user@example.invalid', $email->getFrom()[0]->getAddress());
        $this->assertSame((string) config('mail.from.name'), $email->getFrom()[0]->getName());
        $this->assertSame('Verification Code', $email->getSubject());
        $this->assertSame('user@example.invalid', $email->getTo()[0]->getAddress());
    }

    /**
     * 已設定提供商時，寄件位址改為該提供商的信箱與顯示名稱。
     */
    public function test_message_uses_gateway_sender_when_gateway_exists(): void
    {
        // Arrange
        $this->createGateway(['email' => 'user@example.invalid', 'from_name' => 'Brand']);
        $message = $this->captureSentMessage();

        // Act
        $this->service()->send(
            agentGroupId: $this->group->id,
            receiver: 'user@example.invalid',
            subject: 'Verification Code',
            content: 'The PIN is 123456',
        );

        // Assert
        $email = $message();
        $this->assertNotNull($email);
        $this->assertSame('user@example.invalid', $email->getFrom()[0]->getAddress());
        $this->assertSame('Brand', $email->getFrom()[0]->getName());
    }

    /**
     * 綁定會執行 raw() callback 的 mailer 替身
     *
     * @return callable(): ?Email
     */
    private function captureSentMessage(): callable
    {
        $captured = null;

        $mailer = Mockery::mock(Mailer::class);
        $mailer->shouldReceive('raw')->andReturnUsing(function ($content, $callback) use (&$captured) {
            $message = new Message(new Email);
            $callback($message);
            $captured = $message->getSymfonyMessage();

            return null;
        });

        $this->mock(MailerFactory::class, function (MockInterface $factory) use ($mailer) {
            $factory->shouldReceive('fromConfig')->andReturn($mailer);
            $factory->shouldReceive('default')->andReturn($mailer);
        });

        // 必須以 reference 捕獲，arrow function 是 by-value
        return function () use (&$captured): ?Email {
            return $captured;
        };
    }

    // -------------------------------------------------------
    // 寄送紀錄
    // -------------------------------------------------------

    /**
     * 成功寄送應寫下一筆狀態為成功的紀錄，並記上使用的提供商與代理。
     */
    public function test_successful_send_writes_success_record(): void
    {
        // Arrange
        $gateway = $this->createGateway();
        $this->fakeMailerFactory();

        // Act
        $this->service()->send(
            agentGroupId: $this->group->id,
            receiver: 'user@example.invalid',
            subject: 'Verification Code',
            content: 'The PIN is 123456',
            agentId: $this->agent->id,
            memberId: 99,
        );

        // Assert
        $record = EmailSendRecord::where('receiver', 'user@example.invalid')->first();
        $this->assertNotNull($record);
        $this->assertSame(EmailSendStatusEnum::SUCCESS->value, $record->status);
        $this->assertSame($gateway->id, $record->email_gateway_id);
        $this->assertSame($this->agent->id, $record->agent_id);
        $this->assertSame(99, $record->member_id);
        $this->assertNull($record->response_message);
    }

    /**
     * 寄送失敗時例外原樣往上拋，並把失敗原因寫進紀錄。
     */
    public function test_failed_send_records_failure_and_rethrows(): void
    {
        // Arrange
        $this->createGateway();
        $this->fakeMailerFactory(new RuntimeException('smtp unreachable'));

        // Act
        $thrown = null;
        try {
            $this->service()->send(
                agentGroupId: $this->group->id,
                receiver: 'user@example.invalid',
                subject: 'Verification Code',
                content: 'The PIN is 123456',
            );
        } catch (RuntimeException $e) {
            $thrown = $e;
        }

        // Assert
        $this->assertNotNull($thrown);
        $this->assertSame('smtp unreachable', $thrown->getMessage());

        $record = EmailSendRecord::where('receiver', 'user@example.invalid')->first();
        $this->assertNotNull($record);
        $this->assertSame(EmailSendStatusEnum::FAILED->value, $record->status);
        $this->assertSame('smtp unreachable', $record->response_message);
    }

    /**
     * 代理未指派組別時退回系統預設 mailer，紀錄的 email_gateway_id 為 0。
     */
    public function test_falls_back_to_default_mailer_when_group_unassigned(): void
    {
        // Arrange
        $factory = $this->fakeMailerFactory();

        // Act — agentGroupId 0 = 未指派
        $this->service()->send(
            agentGroupId: 0,
            receiver: 'user@example.invalid',
            subject: 'Verification Code',
            content: 'The PIN is 123456',
        );

        // Assert
        $factory->shouldHaveReceived('default');
        $factory->shouldNotHaveReceived('fromConfig');

        $record = EmailSendRecord::where('receiver', 'user@example.invalid')->first();
        $this->assertNotNull($record);
        $this->assertSame(0, $record->email_gateway_id);
        $this->assertSame(EmailSendStatusEnum::SUCCESS->value, $record->status);
    }
}
