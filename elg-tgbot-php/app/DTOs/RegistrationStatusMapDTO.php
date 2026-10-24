<?php

namespace App\DTOs;

use App\Enums\RegistrationStatusEnum;
use App\Enums\ReplyMessageStatusEnum;

class RegistrationStatusMapDTO
{
    public function __construct(
        public readonly RegistrationStatusEnum $before,
        public readonly RegistrationStatusEnum $after,
        public readonly ?ReplyMessageStatusEnum $replyMessageStatus = null
    ) {}

    public static function start(): self
    {
        return new self(
            before            : RegistrationStatusEnum::START,
            after             : RegistrationStatusEnum::WAIT_PHONE_NUMBER,
            replyMessageStatus: ReplyMessageStatusEnum::REGISTER_WAIT_PHONE_NUMBER
        );
    }

    public static function waitPhoneNumer(): self
    {
        return new self(
            before: RegistrationStatusEnum::WAIT_PHONE_NUMBER,
            after: RegistrationStatusEnum::WAIT_WHETHER_ENTER_AGENT_CODE
        );
    }

    public static function waitWantEnterAgentCode(): self
    {
        return new self(
            before: RegistrationStatusEnum::WAIT_WHETHER_ENTER_AGENT_CODE,
            after: RegistrationStatusEnum::WAIT_AGENT_INVITATION_CODE,
            replyMessageStatus: ReplyMessageStatusEnum::REGISTER_WAIT_AGENT_INVITATION_CODE
        );
    }

    public static function waitDismissEnterAgentCode(): self
    {
        return new self(
            before: RegistrationStatusEnum::WAIT_WHETHER_ENTER_AGENT_CODE,
            after: RegistrationStatusEnum::DONE,
        );
    }

    public static function waitAgentCode(): self
    {
        return new self(
            before: RegistrationStatusEnum::WAIT_AGENT_INVITATION_CODE,
            after: RegistrationStatusEnum::DONE,
        );
    }
}
