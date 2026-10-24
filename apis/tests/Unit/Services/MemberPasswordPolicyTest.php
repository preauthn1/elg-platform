<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\ApiException;
use App\Services\MemberPasswordPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 會員設定新密碼的規則：三選一、符號範圍、半形限制，以及舊版勾選設定的轉換。
 *
 * php artisan test tests/Unit/Services/MemberPasswordPolicyTest.php
 */
class MemberPasswordPolicyTest extends TestCase
{
    private const array LETTER_NUMBER_REQUIRED_NO_SYMBOL = [
        'min_length' => 6,
        'max_length' => 12,
        'rules' => ['alphabet' => 'required', 'number' => 'required', 'symbol' => 'forbidden'],
    ];

    /**
     * @return array<string, array{array<string, mixed>, string, int|null}>
     */
    public static function passwordCases(): array
    {
        $letterOnlyRequired = ['min_length' => 6, 'max_length' => 12, 'rules' => ['alphabet' => 'required', 'number' => 'optional', 'symbol' => 'forbidden']];
        $symbolRequired = ['min_length' => 6, 'max_length' => 12, 'rules' => ['alphabet' => 'required', 'number' => 'optional', 'symbol' => 'required']];

        return [
            '英數必須包含：abc123 通過' => [self::LETTER_NUMBER_REQUIRED_NO_SYMBOL, 'abc123', null],
            '英數必須包含：abcdef 缺數字' => [self::LETTER_NUMBER_REQUIRED_NO_SYMBOL, 'abcdef', 2056],
            '大寫算英文字母：Abc123 通過' => [self::LETTER_NUMBER_REQUIRED_NO_SYMBOL, 'Abc123', null],
            '數字可包含：abcdef 通過' => [$letterOnlyRequired, 'abcdef', null],
            '數字可包含：abc123 通過' => [$letterOnlyRequired, 'abc123', null],
            '英文必須包含：123456 缺英文' => [$letterOnlyRequired, '123456', 2056],
            '符號不可包含：abc12! 擋下' => [$letterOnlyRequired, 'abc12!', 2062],
            '符號不可包含：範圍外符號也算不可包含' => [$letterOnlyRequired, 'abc12~', 2062],
            '數字不可包含：abc123 擋下' => [['rules' => ['alphabet' => 'required', 'number' => 'forbidden', 'symbol' => 'optional']], 'abc123', 2062],
            '符號必須包含：abcde! 通過' => [$symbolRequired, 'abcde!', null],
            '符號必須包含：abcdef 缺符號' => [$symbolRequired, 'abcdef', 2056],
            '符號範圍外：abcde~ 擋下' => [$symbolRequired, 'abcde~', 2061],
            '空白不在符號範圍' => [$symbolRequired, 'abc de!', 2061],
            '全形英數：擋下' => [self::LETTER_NUMBER_REQUIRED_NO_SYMBOL, 'ａｂｃ１２３', 2060],
            '中文：擋下' => [self::LETTER_NUMBER_REQUIRED_NO_SYMBOL, 'abc123密碼', 2060],
            '太短' => [self::LETTER_NUMBER_REQUIRED_NO_SYMBOL, 'ab12', 2054],
            '太長' => [self::LETTER_NUMBER_REQUIRED_NO_SYMBOL, 'abc1234567890', 2054],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    #[DataProvider('passwordCases')]
    public function test_assert(array $config, string $password, ?int $expectedCode): void
    {
        $this->assertSame($expectedCode, $this->failCode(MemberPasswordPolicy::fromExtraConfig($config), $password));
    }

    public function test_error_message_names_the_failed_type(): void
    {
        $policy = MemberPasswordPolicy::fromExtraConfig(self::LETTER_NUMBER_REQUIRED_NO_SYMBOL);

        $this->assertSame(
            __('error_message.2056', ['rules' => __('error_message.password_char_type.number')]),
            $this->failMessage($policy, 'abcdef'),
        );
        $this->assertSame(
            __('error_message.2062', ['rules' => __('error_message.password_char_type.symbol')]),
            $this->failMessage($policy, 'abc12!'),
        );
    }

    public function test_legacy_checkbox_config_maps_checked_to_required_and_unchecked_to_optional(): void
    {
        $policy = MemberPasswordPolicy::fromExtraConfig([
            'min_length' => 6,
            'max_length' => 12,
            'rules' => ['alphabet' => true, 'number' => false, 'symbol' => false, 'uppercase' => false],
        ]);

        $this->assertSame(
            ['alphabet' => 'required', 'number' => 'optional', 'symbol' => 'optional'],
            $policy->toExtraConfig()['rules'],
        );
        $this->assertNull($this->failCode($policy, 'abc12!'), '沒勾符號＝可包含，範圍內符號照常放行');
    }

    public function test_legacy_uppercase_checked_becomes_alphabet_required(): void
    {
        $policy = MemberPasswordPolicy::fromExtraConfig([
            'rules' => ['alphabet' => false, 'number' => true, 'symbol' => false, 'uppercase' => true],
        ]);

        $this->assertSame('required', $policy->toExtraConfig()['rules']['alphabet']);
        $this->assertSame(2056, $this->failCode($policy, '123456'));
        $this->assertNull($this->failCode($policy, 'abc123'), '大寫併入英文字母，小寫也算');
    }

    public function test_presentation_config_drops_uppercase_and_carries_allowed_symbols(): void
    {
        $config = MemberPasswordPolicy::fromExtraConfig(['rules' => ['uppercase' => true]])->toExtraConfig();

        $this->assertSame(['alphabet', 'number', 'symbol'], array_keys($config['rules']));
        $this->assertSame('!@#$%^&*-_+=?', $config['allowed_symbols']);
    }

    public function test_legacy_policy_only_limits_length(): void
    {
        $policy = MemberPasswordPolicy::legacy();

        $this->assertNull($this->failCode($policy, '123456'));
        $this->assertNull($this->failCode($policy, 'abcdef'));
        $this->assertSame(2054, $this->failCode($policy, '12345'));
        $this->assertSame(2054, $this->failCode($policy, '1234567890123'));
    }

    private function failCode(MemberPasswordPolicy $policy, string $password): ?int
    {
        try {
            $policy->assert($password);

            return null;
        } catch (ApiException $e) {
            return $e->getCode();
        }
    }

    private function failMessage(MemberPasswordPolicy $policy, string $password): ?string
    {
        try {
            $policy->assert($password);

            return null;
        } catch (ApiException $e) {
            return $e->getMessage();
        }
    }
}
