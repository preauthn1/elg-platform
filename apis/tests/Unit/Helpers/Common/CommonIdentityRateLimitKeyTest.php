<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers\Common;

use App\Helpers\Common;
use Tests\TestCase;

/**
 * Common::identityRateLimitKey：限流用的帳號／聯絡方式 key（工單 4540）。
 *
 * members.username 的定序是 utf8mb4_unicode_ci，`KÉRÖRO`、全形 `ｋｅｒｏｒｏ` 查得到 `keroro`；
 * key 若照原字串分，這些寫法各自一把額度，等於換個寫法就換到新額度。
 *
 * php artisan test tests/Unit/Helpers/Common/CommonIdentityRateLimitKeyTest.php
 */
class CommonIdentityRateLimitKeyTest extends TestCase
{
    public function test_accented_fullwidth_and_plain_variants_share_one_key(): void
    {
        $plain = Common::identityRateLimitKey('keroro');

        $this->assertSame('keroro', $plain);
        $this->assertSame($plain, Common::identityRateLimitKey('KÉRÖRO'));
        $this->assertSame($plain, Common::identityRateLimitKey('ｋｅｒｏｒｏ'));
        $this->assertSame($plain, Common::identityRateLimitKey('  Keroro '));
    }

    public function test_different_identities_get_different_keys(): void
    {
        $this->assertNotSame(Common::identityRateLimitKey('keroro'), Common::identityRateLimitKey('giroro'));
    }

    public function test_numbers_and_email_are_kept(): void
    {
        $this->assertSame('0912345678', Common::identityRateLimitKey('0912345678'));
        $this->assertSame('912345678', Common::identityRateLimitKey(912345678));
        $this->assertSame('user@example.invalid', Common::identityRateLimitKey('user@example.invalid'));
    }

    public function test_non_scalar_input_is_treated_as_empty(): void
    {
        $this->assertSame('', Common::identityRateLimitKey(['keroro']));
        $this->assertSame('', Common::identityRateLimitKey(null));
    }
}
