<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Models\Member;
use App\Services\RealnameValidationService;
use Tests\TestCase;

/**
 * RealnameValidationService 測試
 *
 * php artisan test tests/Unit/Services/RealnameValidationServiceTest.php
 */
class RealnameValidationServiceTest extends TestCase
{
    public function test_event_reward_skips_when_setting_disabled(): void
    {
        $member = Member::factory()->make(['realname_validate' => 0]);
        $settings = [AgentCustomizedSettingKeyEnum::EVENT_REWARD_REALNAME_VALIDATION => false];

        $this->assertTrue(RealnameValidationService::isEventRewardRealnameValidated($settings, $member));
    }

    public function test_event_reward_requires_validation_when_enabled(): void
    {
        $off = Member::factory()->make(['realname_validate' => 0]);
        $on = Member::factory()->make(['realname_validate' => 1]);
        $settings = [AgentCustomizedSettingKeyEnum::EVENT_REWARD_REALNAME_VALIDATION => true];

        $this->assertFalse(RealnameValidationService::isEventRewardRealnameValidated($settings, $off));
        $this->assertTrue(RealnameValidationService::isEventRewardRealnameValidated($settings, $on));
    }

    public function test_transfer_withdraw_skips_when_setting_disabled(): void
    {
        $member = Member::factory()->make(['realname_validate' => 0]);
        $settings = [AgentCustomizedSettingKeyEnum::TRANSFER_WITHDRAW_REALNAME_REQUIRED => false];

        $this->assertTrue(RealnameValidationService::isWithdrawRealnameValidated($settings, $member));
    }

    public function test_transfer_withdraw_requires_validation_when_enabled(): void
    {
        $off = Member::factory()->make(['realname_validate' => 0]);
        $on = Member::factory()->make(['realname_validate' => 1]);
        $settings = [AgentCustomizedSettingKeyEnum::TRANSFER_WITHDRAW_REALNAME_REQUIRED => true];

        $this->assertFalse(RealnameValidationService::isWithdrawRealnameValidated($settings, $off));
        $this->assertTrue(RealnameValidationService::isWithdrawRealnameValidated($settings, $on));
    }
}
