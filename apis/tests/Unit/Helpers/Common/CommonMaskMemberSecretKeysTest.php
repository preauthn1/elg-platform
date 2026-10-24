<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers\Common;

use App\Helpers\Common;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 會員端請求紀錄的密碼／驗證碼欄位遮罩（工單 4540）。
 *
 * RequestLogger 對會員端請求套 Common::maskData()，原本清單只有 password／payment_password，
 * 重設密碼的新密碼、確認密碼與前端多送的驗證碼欄位都是原文進 log。
 * - 密碼類一律全遮（不論長度）：尾 4 碼對除錯沒有價值、對猜密碼有價值（2026-10-21 使用者裁定）。
 * - 驗證碼與一次性 token 走秘密類規則（長度 12 以上露尾 4，短值全遮）。
 * - 這些規則只在 maskData；external 三方回調用的 maskCredentials 維持原樣。
 *
 * php artisan test tests/Unit/Helpers/Common/CommonMaskMemberSecretKeysTest.php
 */
class CommonMaskMemberSecretKeysTest extends TestCase
{
    private const string LONG_PASSWORD = [REDACTED]';

    #[DataProvider('passwordKeyProvider')]
    public function test_mask_data_fully_masks_password_key_regardless_of_length(string $key): void
    {
        $masked = Common::maskData([$key => self::LONG_PASSWORD, 'params' => [$key => self::LONG_PASSWORD]]);

        $this->assertSame('***', $masked[$key]);
        $this->assertSame('***', $masked['params'][$key]);
        // 不露尾碼：原文的任何一段都不能出現
        $this->assertStringNotContainsString('7890', json_encode($masked));
        $this->assertSame('***', Common::maskData(self::LONG_PASSWORD, $key), 'single value with rule key');
    }

    #[DataProvider('nonPasswordSecretKeyProvider')]
    public function test_mask_data_partially_masks_code_and_token_key(string $key): void
    {
        // 秘密類遮罩：長度 >= 12 只露尾 4，短值全遮
        $masked = Common::maskData([$key => 'MySecretPass1234', 'short' => 'x']);
        $this->assertSame('************1234', $masked[$key]);

        $this->assertSame('***', Common::maskData([$key => '123456'])[$key]);
    }

    #[DataProvider('memberSecretKeyProvider')]
    public function test_mask_data_masks_member_secret_key_when_nested(string $key): void
    {
        $masked = Common::maskData(['params' => ['inner' => [$key => '08064494449']]]);

        $this->assertSame('***', $masked['params']['inner'][$key]);
    }

    /**
     * 會員端才有的鍵不進 maskCredentials：external 三方回調的紀錄範圍保持最小。
     */
    #[DataProvider('memberOnlyKeyProvider')]
    public function test_mask_credentials_leaves_member_only_key_untouched(string $key): void
    {
        $this->assertSame('123456', Common::maskCredentials([$key => '123456'])[$key]);
    }

    /**
     * 回歸：external 的 maskCredentials 對 password／payment_password 仍是露尾 4 碼，不受密碼類全遮影響。
     */
    #[DataProvider('externalPasswordKeyProvider')]
    public function test_mask_credentials_still_keeps_last_four_of_password(string $key): void
    {
        $this->assertSame(
            str_repeat('*', 16) . '7890',
            Common::maskCredentials([$key => self::LONG_PASSWORD])[$key],
        );
    }

    /**
     * 敏感鍵的值是陣列時整個遮掉，不遞迴進去：遞迴只認鍵名，`new_password: ['原文']` 的內層鍵是 0，會原文留下。
     */
    #[DataProvider('memberSecretKeyProvider')]
    public function test_mask_data_masks_whole_array_value_of_member_secret_key(string $key): void
    {
        $masked = Common::maskData([
            $key => ['plaintext-secret-value'],
            'params' => [$key => ['nested' => 'plaintext-secret-value']],
        ]);

        $this->assertSame('***', $masked[$key]);
        $this->assertSame('***', $masked['params'][$key]);
        $this->assertStringNotContainsString('plaintext-secret-value', json_encode($masked));
    }

    /**
     * 非敏感鍵的陣列照舊遞迴，裡面的敏感鍵照遮、其餘原樣。
     */
    public function test_mask_data_still_recurses_into_non_sensitive_arrays(): void
    {
        $masked = Common::maskData(['params' => ['username' => 'keroro', 'new_password' => '08064494449']]);

        $this->assertSame(['username' => 'keroro', 'new_password' => '***'], $masked['params']);
    }

    public function test_mask_data_keeps_non_sensitive_keys(): void
    {
        $data = [
            'domain' => 'example.test',
            'username' => 'keroro',
            'type' => 2,
            'verify_by' => 1,
        ];

        $this->assertSame($data, Common::maskData($data));
    }

    /**
     * 密碼類（含確認密碼、支付密碼）：maskData 一律全遮。
     */
    public static function passwordKeyProvider(): array
    {
        return [
            ...self::externalPasswordKeyProvider(),
            ...self::memberOnlyPasswordKeys(),
        ];
    }

    /**
     * 同時在 external 憑證清單裡的密碼鍵：maskCredentials 維持露尾 4 碼。
     */
    public static function externalPasswordKeyProvider(): array
    {
        return [
            'password' => ['password'],
            'payment_password' => ['payment_password'],
        ];
    }

    /**
     * 驗證碼與一次性 token：秘密類規則。
     */
    public static function nonPasswordSecretKeyProvider(): array
    {
        return [
            'verifyCode' => ['verifyCode'],
            'verifycode' => ['verifycode'],
            'VerifyCode' => ['VerifyCode'],
            'verify_code' => ['verify_code'],
            'validate_code' => ['validate_code'],
            'mobile_token' => ['mobile_token'],
            'email_token' => ['email_token'],
            'guest_validate_token' => ['guest_validate_token'],
            'captcha_token' => ['captcha_token'],
        ];
    }

    /**
     * 只在會員端遮的鍵（external 的 maskCredentials 不認）。
     */
    public static function memberOnlyKeyProvider(): array
    {
        return [
            ...self::memberOnlyPasswordKeys(),
            ...self::nonPasswordSecretKeyProvider(),
        ];
    }

    public static function memberSecretKeyProvider(): array
    {
        return [
            ...self::passwordKeyProvider(),
            ...self::nonPasswordSecretKeyProvider(),
        ];
    }

    private static function memberOnlyPasswordKeys(): array
    {
        return [
            'new_password' => ['new_password'],
            'new_password_confirmation' => ['new_password_confirmation'],
            'password_confirmation' => ['password_confirmation'],
            'confirm_password' => ['confirm_password'],
            'old_password' => ['old_password'],
            'current_payment_password' => ['current_payment_password'],
            'new_payment_password' => ['new_payment_password'],
            'new_payment_password_confirmation' => ['new_payment_password_confirmation'],
            'payment_password_confirmation' => ['payment_password_confirmation'],
        ];
    }
}
