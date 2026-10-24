<?php

declare(strict_types=1);

namespace App\Services\EventV2;

use App\Enums\EventV2\EventV2ValidateCodeEnum;
use App\Models\EventV2RejectJoin;
use App\Models\Member;

/**
 * @mixin EventV2ValidateCodeEnum
 *
 * @method bool isSuccess()
 * @method bool isFailed()
 * @method bool isBlockFailed()
 */
class EventV2ValidateResult
{
    private ?EventV2RejectJoin $eventV2RejectJoin = null;

    private array $translateReplace = [];

    public function __construct(
        public EventV2ValidateCodeEnum $code
    ) {}

    public function __call($name, $arguments)
    {
        return $this->code->{$name}(...$arguments);
    }

    public static function success(): self
    {
        return new self(EventV2ValidateCodeEnum::SUCCESS);
    }

    public static function fail(EventV2ValidateCodeEnum $code): self
    {
        return new self($code);
    }

    public function setTranslateReplace(array $translateReplace): self
    {
        $this->translateReplace = $translateReplace;

        return $this;
    }

    public function translate(?string $locale = null): string
    {
        return $this->code->translate($locale, $this->translateReplace);
    }

    public function createRecord(int $eventV2DetailId, Member $member): void
    {
        $this->code->createRecord($eventV2DetailId, $member, $this->translateReplace);
    }

    public function setEventV2RejectJoin(EventV2RejectJoin $eventV2RejectJoin): self
    {
        $this->eventV2RejectJoin = $eventV2RejectJoin;

        return $this;
    }

    public function getEventV2RejectJoin(): ?EventV2RejectJoin
    {
        return $this->eventV2RejectJoin;
    }
}
