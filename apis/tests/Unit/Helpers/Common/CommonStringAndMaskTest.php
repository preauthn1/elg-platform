<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers\Common;

use App\Helpers\Common;
use Tests\TestCase;

/**
 * Common 字串判斷與遮罩相關測試
 *
 * php artisan test tests/Unit/Helpers/Common/CommonStringAndMaskTest.php
 */
class CommonStringAndMaskTest extends TestCase
{
    public function test_is_null_or_empty_string(): void
    {
        $this->assertTrue(Common::isNullOrEmptyString(null));
        $this->assertTrue(Common::isNullOrEmptyString(''));
        $this->assertTrue(Common::isNullOrEmptyString('   '));
        $this->assertFalse(Common::isNullOrEmptyString('0'));
        $this->assertFalse(Common::isNullOrEmptyString('a'));
    }

    public function test_is_not_empty_string(): void
    {
        $this->assertFalse(Common::isNotEmptyString(null));
        $this->assertFalse(Common::isNotEmptyString(''));
        $this->assertTrue(Common::isNotEmptyString('0'));
        $this->assertTrue(Common::isNotEmptyString('text'));
    }

    public function test_mask_data_array_keys(): void
    {
        $data = [
            'password' => 'secret',
            'token' => 'abcdefghijklmnop',
            'mobile' => '0912345678',
            'email' => 'user@example.invalid',
            'image' => 'https://x.test/a.png',
            'other' => 'keep',
        ];
        $masked = Common::maskData($data);
        $this->assertSame('***', $masked['password'], '短密碼（<12）長度門檻退回全遮');
        $this->assertStringStartsWith('*******', $masked['token']);
        $this->assertSame('091***5678', $masked['mobile']);
        $this->assertSame('a*m', $masked['email']);
        $this->assertSame('*** 略 ***', $masked['image']);
        $this->assertSame('keep', $masked['other']);
    }

    public function test_mask_data_single_value_with_rule_key(): void
    {
        $this->assertSame('***', Common::maskData('x', 'password'));
        $this->assertSame('*** 略 ***', Common::maskData('url', 'image'));
    }

    public function test_mask_data_mobile_skips_http_prefix(): void
    {
        $url = 'http://example.invalid';
        $this->assertSame($url, Common::maskData($url, 'mobile'));
    }

    public function test_mask_string_masks_middle_portion(): void
    {
        $out = Common::maskString('1234567890');
        $this->assertStringContainsString('*', $out);
        $this->assertStringStartsWith('12', $out);
        $this->assertStringEndsWith('90', $out);
    }

    /**
     * maskData 新增金流憑證鍵：卡號/CVV/PIN/銀行帳號/持卡人/API 憑證一律全遮罩 '***'。
     */
    public function test_mask_data_masks_payment_credential_keys(): void
    {
        $masked = Common::maskData([
            'card_number' => '4111111111111111',
            'card_cvv' => '123',
            'card_holder_name' => 'John Doe',
            'pin' => '1234567890',
            'bank_account' => '0987654321',
            'bank_holder_name' => 'Jane Doe',
            'api_key' => 'sk_live_abcdef',
            'DestinationClientAccountNumber' => '55667788',
            'DestinationClientAccountHolderName' => 'Foo Bar',
            'amount' => '100.00',
        ]);

        // 政策（RB 2026-08-25）：一律部分遮罩保留判讀能力，長度不足以保護秘密時退回全遮
        $this->assertSame('411*********1111', $masked['card_number'], '卡號前 3 後 4 可見');
        $this->assertSame('***', $masked['card_cvv'], 'CVV 3 碼露任一字元即洩漏，門檻退回全遮');
        $this->assertSame('J******e', $masked['card_holder_name'], '持卡人頭尾字可見');
        $this->assertSame('***', $masked['pin'], 'PIN 10 碼未達秘密類門檻（12），全遮');
        $this->assertSame('098***4321', $masked['bank_account'], '銀行帳號前 3 後 4 可見');
        $this->assertSame('J******e', $masked['bank_holder_name'], '戶名頭尾字可見');
        $this->assertSame('**********cdef', $masked['api_key'], 'API 憑證只露尾 4');
        $this->assertSame('556*7788', $masked['DestinationClientAccountNumber'], '帳號前 3 後 4 可見');
        $this->assertSame('F*****r', $masked['DestinationClientAccountHolderName'], '持有人頭尾字可見');
        $this->assertSame('100.00', $masked['amount'], '非敏感鍵保留原值');
    }

    /**
     * maskData 巢狀遞迴：巢狀陣列中的敏感鍵也要被遮罩。
     */
    public function test_mask_data_masks_nested_keys_recursively(): void
    {
        $masked = Common::maskData([
            'order_no' => 'ORD123',
            'recipient' => [
                'bank_account' => '0987654321',
                'card_cvv' => '999',
                'name' => 'keep',
            ],
        ]);

        $this->assertSame('ORD123', $masked['order_no'], '頂層非敏感鍵保留');
        $this->assertSame('098***4321', $masked['recipient']['bank_account'], '巢狀敏感鍵應遞迴套部分遮罩');
        $this->assertSame('***', $masked['recipient']['card_cvv'], 'CVV 門檻退回全遮');
        $this->assertSame('keep', $masked['recipient']['name'], '巢狀非敏感鍵保留');
    }

    /**
     * maskCredentials 只遮硬 bearer 憑證（pin/卡號/銀行/API 憑證），全遮罩 '***'。
     * 供 external 三方回調記 log 使用：堵住 PIN/卡號明文外洩。
     */
    public function test_mask_credentials_masks_hard_bearer_credentials(): void
    {
        $masked = Common::maskCredentials([
            'card_number' => '4111111111111111',
            'card_cvv' => '123',
            'pin' => '4830185194585221',
            'bank_account' => '0987654321',
            'bank_user_name' => 'Jane Doe',
            'api_key' => 'sk_live_abcdef',
            'DestinationClientAccountNumber' => '55667788',
        ]);

        $this->assertSame('411*********1111', $masked['card_number'], '卡號前 3 後 4 可見');
        $this->assertSame('***', $masked['card_cvv'], 'CVV 門檻退回全遮');
        $this->assertSame('************5221', $masked['pin'], '16 碼 telco PIN 達秘密類門檻，只露尾 4');
        $this->assertSame('098***4321', $masked['bank_account'], '銀行帳號前 3 後 4 可見');
        $this->assertSame('J******e', $masked['bank_user_name'], '戶名頭尾字可見');
        $this->assertSame('**********cdef', $masked['api_key'], 'API 憑證只露尾 4');
        $this->assertSame('556*7788', $masked['DestinationClientAccountNumber'], '帳號前 3 後 4 可見');
    }

    /**
     * maskCredentials 刻意不碰 token / mobile / email 等軟欄位。
     * 關鍵：token 是 EpicPay/FPay/Sure/SurePay 回調的簽名欄位，遮了會弄花 log 的簽名除錯。
     */
    public function test_mask_credentials_preserves_token_and_non_credential_fields(): void
    {
        $masked = Common::maskCredentials([
            'token' => '5c80133d59b13ad283bdc8747e1a2974',
            'mobile' => '0912345678',
            'email' => 'user@example.invalid',
            'amount' => '30.00',
            'transaction_id' => '765F12688D8A841D8FA5',
            'merchant_ref' => '202606055400236846',
            'signature' => '5c80133d59b13ad283bdc8747e1a2974',
        ]);

        $this->assertSame('5c80133d59b13ad283bdc8747e1a2974', $masked['token'], 'token 為簽名欄位，必須原樣保留');
        $this->assertSame('0912345678', $masked['mobile'], 'mobile 不在硬憑證範圍，保留');
        $this->assertSame('user@example.invalid', $masked['email'], 'email 不在硬憑證範圍，保留');
        $this->assertSame('30.00', $masked['amount'], '非敏感鍵保留');
        $this->assertSame('765F12688D8A841D8FA5', $masked['transaction_id'], '簽名組成欄位保留');
        $this->assertSame('202606055400236846', $masked['merchant_ref'], '簽名組成欄位保留');
        $this->assertSame('5c80133d59b13ad283bdc8747e1a2974', $masked['signature'], '簽名欄位保留');
    }

    /**
     * maskCredentials 巢狀遞迴：巢狀陣列中的硬憑證也要被遮。
     */
    public function test_mask_credentials_recurses_nested(): void
    {
        $masked = Common::maskCredentials([
            'order_no' => 'ORD123',
            'data' => [
                'pin' => '4830185194585221',
                'token' => 'sig-stays',
            ],
        ]);

        $this->assertSame('ORD123', $masked['order_no'], '頂層非敏感鍵保留');
        $this->assertSame('************5221', $masked['data']['pin'], '巢狀 pin 應遞迴套秘密類遮罩');
        $this->assertSame('sig-stays', $masked['data']['token'], '巢狀 token 保留');
    }

    /**
     * 數字型（int）憑證值也必須遮罩：JSON decode 後的卡號／銀行帳號常是 int，
     * 只遮 string 會讓明文數字卡號隨 log 外洩（fail-open 缺口）。
     */
    public function test_mask_data_masks_non_string_credential_values(): void
    {
        $masked = Common::maskData([
            'card_number' => 4111111111111111,
            'bank_account' => 987654321,
            'pin' => 123456,
        ]);

        $this->assertSame('411*********1111', $masked['card_number'], 'int 卡號 cast 後套部分遮罩');
        $this->assertSame('987**4321', $masked['bank_account'], 'int 銀行帳號 cast 後套部分遮罩');
        $this->assertSame('***', $masked['pin'], 'int PIN 6 碼未達秘密類門檻，全遮');
    }

    public function test_mask_credentials_masks_non_string_credential_values(): void
    {
        $masked = Common::maskCredentials([
            'card_number' => 4111111111111111,
            'DestinationClientAccountNumber' => 55667788,
        ]);

        $this->assertSame('411*********1111', $masked['card_number'], 'int 卡號 cast 後套部分遮罩');
        $this->assertSame('556*7788', $masked['DestinationClientAccountNumber'], 'int 帳號 cast 後套部分遮罩');
    }

    public function test_mask_data_masks_non_string_single_value_with_rule_key(): void
    {
        $this->assertSame('***', Common::maskData(123456, 'password'), 'int 短密碼門檻退回全遮');
    }

    /**
     * 過短的 mobile：前 3 碼＋後 4 碼會重疊，遮完反而原文全洩——改為全遮罩。
     * email 同理：長度 ≤ 2 時首尾即是全部內容。
     */
    public function test_mask_data_fully_masks_short_mobile(): void
    {
        $this->assertSame('***', Common::maskData('1234567', 'mobile'), '7 碼以下前後重疊全洩，應全遮罩');
        $this->assertSame('***', Common::maskData('12345', 'mobile'));
    }

    public function test_mask_data_fully_masks_short_email(): void
    {
        $this->assertSame('***', Common::maskData('ab', 'email'), '首尾即全部內容，應全遮罩');
        $this->assertSame('***', Common::maskData('a', 'email'));
    }

    /**
     * 未知 ruleKey 不可 crash（遮罩 helper 掛掉會連帶弄掛主流程 log），
     * fail-closed 直接全遮罩。
     */
    public function test_mask_data_unknown_rule_key_falls_back_to_full_mask(): void
    {
        $this->assertSame('***', Common::maskData('whatever', 'not_a_rule'));
    }

    /**
     * maskHeaders：憑證類 header 一律遮罩（不依賴呼叫端指定遮罩鍵）。
     * Authorization 類保留認證 scheme 以利排查；Cookie 類全遮；header 名稱大小寫不敏感。
     * 三方簽名類 header（digest / X-Sign / wtoken）刻意不遮，保留「從 log 重算簽名」能力。
     */
    public function test_mask_headers_masks_credential_headers_regardless_of_case(): void
    {
        $masked = Common::maskHeaders([
            'Authorization' => 'Bearer [REDACTED]',
            'authorization-lower-check' => 'not-a-real-header',
            'Cookie' => 'session=abc123; token=[REDACTED]',
            'X-API-KEY' => 'ak_live_secret',
            'Content-Type' => 'application/json',
            'digest' => 'vendor-signature-value',
        ]);

        $this->assertSame('Bearer ************-123', $masked['Authorization'], 'Authorization 保留 scheme、憑證只露尾 4');
        $this->assertSame('************************z789', $masked['Cookie'], 'Cookie 整串當秘密、只露尾 4');
        $this->assertSame('**********cret', $masked['X-API-KEY'], 'header 名稱比對須大小寫不敏感');
        $this->assertSame('application/json', $masked['Content-Type'], '非憑證 header 保留原值');
        $this->assertSame('not-a-real-header', $masked['authorization-lower-check'], '僅完全相符的 header 名稱才遮');
        $this->assertSame('vendor-signature-value', $masked['digest'], '三方簽名 header 保留供重算簽名');
    }

    /**
     * 無認證 scheme（值不含空格）的憑證 header 走秘密類部分遮罩；
     * 非 scalar 值（型別意外）退回全遮，不可因 regex 未命中或型別意外而漏遮（fail-safe）。
     */
    public function test_mask_headers_masks_scheme_less_and_non_string_values(): void
    {
        $masked = Common::maskHeaders([
            'Authorization' => 'rawtokenwithoutscheme',
            'Cookie' => null,
        ]);

        $this->assertSame('*****************heme', $masked['Authorization'], '無 scheme 時整段當秘密、只露尾 4');
        $this->assertSame('***', $masked['Cookie'], '非 scalar 值命中敏感鍵時全遮（fail-safe）');
    }

    /**
     * SurePay /v1/bankquery 回應的銀行帳號／戶名（三方原始鍵名）須被 maskCredentials 命中，
     * 且採**部分遮罩**保留日誌判讀能力（RB 2026-08-25 裁決：全遮會讓對單失去依據）——
     * 帳號前 3 後 4 可見、戶名頭尾字可見（mb-safe，中文不可切壞）。
     * 走精確鍵名比對，不會被既有 bank_account / bank_user_name 覆蓋，屬獨立鍵須逐字列入。
     */
    public function test_mask_credentials_partially_masks_surepay_bankquery_pii_keys(): void
    {
        $masked = Common::maskCredentials([
            ['bankname' => 'MAYBANK', 'accname' => '王小明', 'bankaccno' => '512345678901', 'iban' => '10022198'],
            ['accname' => 'JOHN TAN', 'bankaccno' => '1234567'],
        ]);

        $this->assertSame('王*明', $masked[0]['accname'], '中文戶名頭尾可見、mb-safe 不產生壞字');
        $this->assertSame('512*****8901', $masked[0]['bankaccno'], '帳號前 3 後 4 可見');
        $this->assertSame('MAYBANK', $masked[0]['bankname'], '銀行短代碼非 PII 保留');
        $this->assertSame('10022198', $masked[0]['iban'], '通道碼是配置值非 PII，排查需要看得到');
        $this->assertSame('J******N', $masked[1]['accname'], '拉丁字母戶名同規則');
        $this->assertSame('***', $masked[1]['bankaccno'], '≤7 碼帳號前後重疊等同全洩，全遮');

        $this->assertSame(
            '王*明',
            Common::maskData(['accname' => '王小明'])['accname'],
            'maskData 與 maskCredentials 共用部分遮罩規則表，不可 drift'
        );
    }

    /**
     * flattenHeaders 是 header 落 log 的收口點（RequestLogger 與各單一錢包 Controller），
     * 攤平後必須帶憑證遮罩——進站會員／代理 bearer token 不得因個別呼叫點漏遮而明文落 log。
     */
    public function test_flatten_headers_masks_credentials_at_choke_point(): void
    {
        $flattened = Common::flattenHeaders([
            'authorization' => ['Bearer [REDACTED]'],
            'x-sign' => ['vendor-signature'],
            'content-type' => ['application/json'],
        ]);

        $this->assertSame('Bearer ****************oken', $flattened['authorization'], '進站 bearer token 須於收口點遮罩（保留 scheme 與尾 4）');
        $this->assertSame('vendor-signature', $flattened['x-sign'], '簽名 header 保留');
        $this->assertSame('application/json', $flattened['content-type'], '一般 header 照常攤平');
    }

    public function test_mask_vendor_info_masks_listed_secret_keys_only(): void
    {
        $masked = Common::maskVendorInfo([
            'AUTH_URL' => 'https://api.example.test',
            'CLIENT_ID' => 'client-id-visible',
            'MERCHANT_CODE' => 'M001',
            'MW_PUBLIC_KEY' => 'public-key-visible',
            'CLIENT_SECRET' => 'fake-secret-abcd1234',
            'SecretKey' => 'fake-secret-key-5678',
            'operatorToken' => 'fake-operator-token-0000',
            'API_PASSWORD' => 'short',
            'nested' => ['MD5_KEY' => 'fake-md5-key-xyz9'],
        ]);

        $this->assertSame('https://api.example.test', $masked['AUTH_URL'], '網址保留');
        $this->assertSame('client-id-visible', $masked['CLIENT_ID'], '識別碼不是憑證，保留');
        $this->assertSame('M001', $masked['MERCHANT_CODE']);
        $this->assertSame('public-key-visible', $masked['MW_PUBLIC_KEY'], '公鑰不遮');
        $this->assertSame('****************1234', $masked['CLIENT_SECRET'], '秘密類只露尾 4');
        $this->assertSame('****************5678', $masked['SecretKey'], '大小寫混用的同義鍵逐字列入');
        $this->assertSame('********************0000', $masked['operatorToken']);
        $this->assertSame('***', $masked['API_PASSWORD'], '長度 < 12 全遮');
        $this->assertSame('*************xyz9', $masked['nested']['MD5_KEY'], '巢狀照遮');
    }

    public function test_mask_vendor_info_is_case_sensitive(): void
    {
        $masked = Common::maskVendorInfo(['Client_Secret' => 'fake-secret-abcd1234']);

        $this->assertSame('fake-secret-abcd1234', $masked['Client_Secret'], '精確鍵名比對：未列入的大小寫變體不遮（新鍵名須補清單）');
    }

    public function test_restore_masked_vendor_info_keeps_unchanged_secrets_and_applies_new_values(): void
    {
        $original = [
            'AUTH_URL' => 'https://old.example.test',
            'CLIENT_SECRET' => 'fake-secret-abcd1234',
            'API_KEY' => 'fake-api-key-5678',
            'API_PASSWORD' => 'short',
            'nested' => ['MD5_KEY' => 'fake-md5-key-xyz9'],
        ];
        $submitted = Common::maskVendorInfo($original);
        $submitted['AUTH_URL'] = 'https://new.example.test';
        $submitted['API_KEY'] = 'fake-new-api-key-9999';
        $submitted['NEW_FIELD'] = 'added';

        $restored = Common::restoreMaskedVendorInfo($submitted, $original);

        $this->assertSame('https://new.example.test', $restored['AUTH_URL'], '非憑證照送回的值');
        $this->assertSame('fake-secret-abcd1234', $restored['CLIENT_SECRET'], '送回遮罩字串＝沒改，還原原值');
        $this->assertSame('short', $restored['API_PASSWORD'], '全遮（***）的短值也能還原');
        $this->assertSame('fake-md5-key-xyz9', $restored['nested']['MD5_KEY'], '巢狀照還原');
        $this->assertSame('fake-new-api-key-9999', $restored['API_KEY'], '有填新值就用新值');
        $this->assertSame('added', $restored['NEW_FIELD'], '新增的鍵照送回的值');
    }

    public function test_unrestored_masked_vendor_info_keys_reports_leftover_mask_strings(): void
    {
        $keys = Common::unrestoredMaskedVendorInfoKeys([
            'AUTH_URL' => '***not-a-secret-key',
            'CLIENT_SECRET' => '****************9999',
            'API_PASSWORD' => '***',
            'API_KEY' => 'fake-real-key-1234',
            'nested' => ['MD5_KEY' => '*****xyz9'],
        ]);

        $this->assertSame(['CLIENT_SECRET', 'API_PASSWORD', 'nested.MD5_KEY'], $keys, '只看憑證鍵；真實值與非憑證鍵不算');
    }
}
