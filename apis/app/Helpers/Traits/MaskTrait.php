<?php

declare(strict_types=1);

namespace App\Helpers\Traits;

use Closure;
use Illuminate\Support\Str;

trait MaskTrait
{
    /**
     * 遮罩總政策（RB 2026-08-25 裁決）：**一律部分遮罩、頭尾可見，保留日誌判讀能力**；
     * 只有「值太短、露頭尾等同全洩」時由長度門檻退回全遮 '***'——不再有無條件全遮的鍵。
     * 憑證／PII 鍵依性質分三個 profile（帳號類／姓名類／秘密類），maskData 與
     * maskCredentials 共用同一組規則，避免兩處 drift。新增鍵時選 profile，禁止另立全遮。
     *
     * 密碼類例外（2026-10-21 使用者裁定，工單 4540）：密碼（含確認密碼、支付密碼）在會員端請求紀錄
     * （{@see maskData}）一律全遮，不論長度——尾 4 碼對除錯沒有價值、對猜密碼有價值。鍵清單見
     * {@see PASSWORD_KEYS}。external 三方回調用的 {@see maskCredentials} 不受影響，password／payment_password
     * 在那邊仍走秘密類規則。
     */

    /**
     * 帳號／卡號類：前 3 後 4 可見（{@see maskPartial}；卡號露前 6 後 4 是 PCI 慣例，
     * 此處收斂為更保守的前 3 後 4）。長度 ≤ 7 時前後重疊等同全洩，退回全遮。
     *
     * @var array<int, string>
     */
    private const array ACCOUNT_NUMBER_KEYS = [
        'card_number', 'bank_account', 'DestinationClientAccountNumber',
        // SurePay /v1/bankquery 回應原始鍵名（精確鍵名比對，不會被 bank_account 命中，須逐字列入）
        'bankaccno',
    ];

    /**
     * 姓名／戶名類：頭尾字可見（{@see maskName}，mb-safe——戶名常為中文，byte 級 substr 會切壞）。
     *
     * @var array<int, string>
     */
    private const array NAME_KEYS = [
        'card_holder_name', 'bank_user_name', 'bank_holder_name',
        'DestinationClientAccountHolderName',
        // SurePay /v1/bankquery 回應原始鍵名
        'accname',
    ];

    /**
     * 秘密類（PIN / CVV / 密碼 / API 憑證）：只露尾 4 供對單（{@see maskSecret}）。
     * 長度 < 12 時露任何字元都損失過多熵值（CVV 3 碼、PIN 4-6 碼尤甚），退回全遮。
     *
     * @var array<int, string>
     */
    private const array SECRET_KEYS = [
        'pin', 'card_cvv', 'card_exp_mn', 'card_exp_yy', 'api_key',
        'secret', 'password', 'payment_password', '__lc_cid', '__lc_cst',
        // 單一錢包回調的三方靜態憑證（target/op_tickets#3076）：Rcb988 與 Imperium 拿 `key` 比對
        // CLIENT_SECRET／hall key、AFBSW 收下 `key` 但未用於驗證，WE 用 `appSecret`。
        // `key` 是通用字，但比對是精確鍵名（見 walkMask），只命中鍵名恰為 `key` 的欄位，
        // 不影響 api_key／game_key 等；誤遮的代價是 log 少一個值，漏遮的代價是憑證外洩。
        'key', 'appSecret',
    ];

    /**
     * 會員端請求才有的驗證碼與一次性 token 欄位（工單 4540；密碼類另見 {@see PASSWORD_KEYS}）：套秘密類遮罩，只加進 {@see maskData}（RequestLogger
     * 對會員端請求用的那組），刻意不進 {@see credentialRules}——後者也給 external 三方回調的
     * {@see maskCredentials} 用，範圍保持最小。比對是精確鍵名、區分大小寫，前端實際送出的寫法要逐字列入。
     *
     * @var array<int, string>
     */
    private const array MEMBER_SECRET_KEYS = [
        // 前端多送的驗證碼欄位（token 已有自己的遮罩規則）
        'verifyCode', 'verifycode', 'VerifyCode', 'verify_code', 'validate_code',
        // 一次性憑證：手機／信箱驗證通過後換到的 token、找回密碼的訪客 token、滑塊驗證通過的 token
        'mobile_token', 'email_token', 'guest_validate_token', 'captcha_token',
    ];

    /**
     * 密碼類（檔頭「密碼類例外」）：{@see maskData} 一律全遮 '***'，不論長度。
     * password／payment_password 也在 {@see SECRET_KEYS}，maskRules 內以本組蓋掉；
     * {@see credentialRules}（external 的 maskCredentials）不受影響，仍走秘密類規則。
     *
     * @var array<int, string>
     */
    private const array PASSWORD_KEYS = [
        'password', 'payment_password',
        // 重設／變更密碼的新密碼與確認欄位（註冊 v2、變更支付密碼等表單，工單 4540）
        'new_password', 'new_password_confirmation', 'password_confirmation', 'confirm_password', 'old_password',
        'current_payment_password', 'new_payment_password', 'new_payment_password_confirmation',
        'payment_password_confirmation',
    ];

    /**
     * 一律遮罩的 HTTP header：這類 header 的值本身就是憑證（進站是會員／代理 bearer token，
     * 出站是三方 API token），與呼叫端有沒有指定遮罩鍵無關，故無條件遮罩（fail-safe）。
     * 以小寫比對——HTTP header 名稱大小寫不敏感，且來源兩樣：Symfony HeaderBag 轉小寫、
     * Guzzle PSR-7 保留原大小寫，精確比對任一邊都會漏。
     *
     * 三方簽名類 header（digest / X-Sign / wtoken / mwCode…）刻意不列入——
     * 與 {@see maskCredentials} 保留 `token` 欄位同理，遮掉會讓「從 log 重算簽名」失準。
     *
     * @var array<int, string>
     */
    private const array SENSITIVE_HEADERS = [
        'authorization', 'proxy-authorization', 'cookie', 'set-cookie',
        'x-api-key', 'x-auth-token', 'x-access-token', 'api-key',
    ];

    /**
     * 值形如 `Bearer xxx` 的 header：遮值但保留認證 scheme，排查時才分得出
     * 「帶錯 scheme」與「憑證錯誤」。其餘敏感 header（如 Cookie 是 `k=v; k=v` 格式）
     * 一律全遮，保留前段等同沒遮。
     *
     * @var array<int, string>
     */
    private const array SCHEME_HEADERS = ['authorization', 'proxy-authorization'];

    /**
     * 遊戲商設定 JSON（`example.invalid`、`vendor_infos.info`）裡的憑證鍵（{@see maskVendorInfo}，
     * 精確鍵名比對、區分大小寫）：套秘密類遮罩。
     *
     * 清單依七站兩張表的實際鍵名逐一盤點；各家自取鍵名、大小寫混用，同義鍵要逐字列入。
     * 刻意不列：識別碼（CLIENT_ID／ACCOUNT_ID／MERCHANT_ID／OPERATOR_ID…）、帳號名（USERNAME／API_USER…）、
     * 網址、公鑰（PUBLIC_KEY／EC_PUBLIC_KEY／MW_PUBLIC_KEY）、檔案路徑（*_FILE／CERT_PATH）。
     * 新接的遊戲商帶來新的憑證鍵時必須補進這裡，否則 Olympus 編輯頁與操作紀錄會顯示原文。
     *
     * @var array<int, string>
     */
    private const array VENDOR_INFO_SECRET_KEYS = [
        // 密碼
        'PASS', 'PASSWORD', 'password', 'API_PASS', 'API_PASSWORD', 'AGENT_PASSWORD', 'CLIENT_AGENT_PASS',
        'FTP_PASSWORD', 'H5_PASSWORD', 'HAOLONG_PASSWORD', 'backend_password',
        // 金鑰／密鑰
        'KEY', 'key', 'API_KEY', 'APP_KEY', 'appKey', 'ADMIN_KEY', 'AES_KEY', 'DES_KEY', 'DESKEY', 'MD5_KEY', 'MD5KEY',
        'MT_KEY', 'KIOSK_KEY', 'ENTITY_KEY', 'SIGNATURE_KEY', 'ENCRYPTKEY', 'CLIENT_API_KEY', 'CLIENT_H5_ENCRYPT_KEY',
        'CLIENT_H5_MD5_KEY', 'CLIENT_H5_SECRET_KEY', 'H5_ENCRYPTKEY', 'H5_MD5ENCRYPTKEY', 'H5_SECRETKEY',
        'PRIVATE_KEY', 'EC_PRIVATE_KEY', 'SECURITY_KEY', 'Security_key', 'SECURITY_CODE', 'cryptKey', 'hallKey', 'authKey',
        'SALT', 'SIGNATURE', 'AUTH_CODE', 'API_AUTHCODE',
        // AES 固定 IV 與金鑰成對使用，單獨外流也會降低加密強度
        'IV', 'iv', 'API_IV', 'API_PCH',
        // secret
        'SECRET', 'SECRETKEY', 'SECRET_KEY', 'SecretKey', 'API_SECRET', 'API_SECRETKEY', 'API_SECRET_KEY', 'APP_SECRET',
        'appSecret', 'CLIENT_SECRET', 'clientSecret', 'accessKeySecret', 'JWT_SECRET',
        // token
        'TOKEN', 'token', 'API_TOKEN', 'api_token', 'ACCESS_TOKEN', 'API_ACCESS_TOKEN', 'CLIENT_ACCESS_TOKEN', 'CS_TOKEN',
        'EXTERNAL_TOKEN', 'OPERATOR_TOKEN', 'operatorToken', 'PRIVATE_TOKEN', 'PUBLIC_TOKEN', 'LICENSEE_TOKEN',
    ];

    /**
     * maskData / maskCredentials 規則表快取：RequestLogger 對所有進站請求高頻呼叫且遞迴，
     * closure 無狀態可跨呼叫重用，避免每層遞迴重建整組規則。
     *
     * @var array<string, Closure(string): string>|null
     */
    private static ?array $maskRules = null;

    /** @var array<string, Closure(string): string>|null */
    private static ?array $credentialRules = null;

    /**
     * 過濾敏感資訊
     *
     * 根據指定的規則過濾資料中的敏感資訊，例如密碼、Token、手機號碼等。
     *
     * @param  mixed  $data  要過濾的資料，可以是陣列或單一值
     * @param  string|null  $ruleKey  指定的過濾規則鍵，若為單一值時使用
     * @return mixed 過濾後的資料
     */
    public static function maskData(mixed $data, ?string $ruleKey = null): mixed
    {
        if (is_array($data)) {
            return self::walkMask($data, self::maskRules(), maskWholeArray: true);
        }

        // 單一值＋指定規則鍵：未知鍵 fail-closed 全遮罩，遮罩 helper 不可令主流程 log crash
        if ($ruleKey !== null && is_scalar($data)) {
            $rule = self::maskRules()[$ruleKey] ?? static fn (): string => '***';

            return $rule((string) $data);
        }

        return $data;
    }

    /**
     * 只遮硬 bearer 憑證（{@see CREDENTIAL_KEYS}：卡號 / PIN / 銀行帳號 / API 憑證等），全遮罩 '***'。
     *
     * 與 {@see maskData} 的差異：本方法**刻意不套用** token / mobile / email 等軟規則。
     * 用於 external 三方回調的請求／響應 log——這類回調的 `token` 欄位往往是三方簽名本體
     * （如 EpicPay / FPay / Sure / SurePay），遮掉會讓「從 log 重算簽名」失準；而 PIN／卡號等
     * 才是真正必須遮的 bearer 憑證。故此處精準只遮硬憑證，保留其餘欄位供除錯與驗簽。
     *
     * @param  mixed  $data  要過濾的資料，可為陣列或單一值
     * @return mixed 過濾後的資料
     */
    public static function maskCredentials(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }

        return self::walkMask($data, self::credentialRules());
    }

    /**
     * 遮罩遊戲商設定 JSON（`example.invalid`、`vendor_infos.info` 解碼後的陣列）裡的憑證值：{@see VENDOR_INFO_SECRET_KEYS} 的鍵
     * 套秘密類遮罩（只露尾 4，長度 < 12 全遮），其餘鍵原樣保留，巢狀照遮。
     *
     * 與 {@see maskCredentials} 分開：info 的鍵名是大寫加前綴（CLIENT_SECRET），
     * 硬憑證清單的小寫鍵一個都比不到；反過來把這組鍵併進硬憑證清單，也會擴大三方回調 log 的遮罩範圍。
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function maskVendorInfo(array $data): array
    {
        return self::walkMask($data, array_fill_keys(
            self::VENDOR_INFO_SECRET_KEYS,
            static fn (string $value): string => self::maskSecret($value),
        ));
    }

    /**
     * {@see maskVendorInfo} 的反向：編輯頁只送出遮罩後的值，儲存時把「送回來的值仍等於原值遮罩結果」
     * 的憑證還原成原值（＝沒改）；有填新值的照新值，原本沒有的鍵照送回的值。
     *
     * 必須在寫入前呼叫，否則遮罩字串（****1234）會蓋掉 DB 裡的真實憑證。
     *
     * @param  array<array-key, mixed>  $submitted  表單送回、已解碼的設定
     * @param  array<array-key, mixed>  $original  DB 現值（已解碼、未遮罩）
     * @return array<array-key, mixed>
     */
    public static function restoreMaskedVendorInfo(array $submitted, array $original): array
    {
        return self::restoreMaskedWalk($submitted, $original, self::maskVendorInfo($original));
    }

    /**
     * 還原後仍長得像遮罩字串（開頭 3 個以上 `*`、後面最多 4 字）的憑證鍵路徑。
     *
     * 呼叫端必須在寫入前擋下有回傳值的情況：送回的遮罩值對不上原值（只改了一部分、
     * 從別處複製遮罩值、兩人同時編輯而原值已換）時無法還原，照存會讓遮罩字串蓋掉真實憑證。
     *
     * @param  array<array-key, mixed>  $restored  {@see restoreMaskedVendorInfo} 的結果
     * @return list<string> 例：['CLIENT_SECRET', 'nested.MD5_KEY']
     */
    public static function unrestoredMaskedVendorInfoKeys(array $restored, string $prefix = ''): array
    {
        $keys = [];
        foreach ($restored as $key => $value) {
            $path = $prefix . $key;
            if (is_array($value)) {
                $keys = [...$keys, ...self::unrestoredMaskedVendorInfoKeys($value, $path . '.')];
            } elseif (in_array($key, self::VENDOR_INFO_SECRET_KEYS, true)
                && is_string($value) && preg_match('/^\*{3,}.{0,4}$/su', $value) === 1) {
                $keys[] = $path;
            }
        }

        return $keys;
    }

    /**
     * @param  array<array-key, mixed>  $submitted
     * @param  array<array-key, mixed>  $original
     * @param  array<array-key, mixed>  $masked
     * @return array<array-key, mixed>
     */
    private static function restoreMaskedWalk(array $submitted, array $original, array $masked): array
    {
        foreach ($submitted as $key => $value) {
            if (!array_key_exists($key, $original)) {
                continue;
            }

            if (is_array($value) && is_array($original[$key]) && is_array($masked[$key])) {
                $submitted[$key] = self::restoreMaskedWalk($value, $original[$key], $masked[$key]);
            } elseif ($masked[$key] !== $original[$key] && $value === $masked[$key]) {
                // 只還原「真的被遮過」的鍵：沒遮的鍵遮罩前後相同，送回什麼就是什麼
                $submitted[$key] = $original[$key];
            }
        }

        return $submitted;
    }

    /**
     * 遮罩 HTTP header 中的憑證值（{@see SENSITIVE_HEADERS}）。header 名稱一律保留——
     * 排查時需要知道「有沒有帶這個 header」，只是不該看到值。非 string 的值（誤傳的
     * 陣列、null）在命中敏感鍵時同樣全遮，遮罩 helper 對意外型別走 fail-safe。
     *
     * @param  array<string, mixed>  $headers  鍵為 header 名稱的一維陣列（已 flatten）
     * @return array<string, mixed>
     */
    public static function maskHeaders(array $headers): array
    {
        foreach ($headers as $name => $value) {
            $lower = strtolower((string) $name);
            if (!in_array($lower, self::SENSITIVE_HEADERS, true)) {
                continue;
            }

            if (!is_scalar($value)) {
                $headers[$name] = '***';

                continue;
            }

            // Authorization 類保留認證 scheme；憑證本體走秘密類部分遮罩（只露尾 4，短值全遮）
            $headers[$name] = in_array($lower, self::SCHEME_HEADERS, true)
                && preg_match('/^(\S+)\s+(\S.*)$/', (string) $value, $matches) === 1
                    ? $matches[1] . ' ' . self::maskSecret($matches[2])
                    : self::maskSecret((string) $value);
        }

        return $headers;
    }

    /**
     * 隱藏字串部分內容, 預設隱藏中間60%, 用*代替
     */
    public static function maskString(string $value): string
    {
        $length = strlen($value);
        $maskLength = floor($length * 0.6);
        $leftLength = floor(($length - $maskLength) / 2);
        $rightLength = ceil(($length - $maskLength) / 2);

        return substr($value, 0, (int) $leftLength)
            . str_repeat('*', (int) $maskLength)
            . substr($value, $length - (int) $rightLength);
    }

    /**
     * 活動中獎名單／排行榜的帳號遮蔽：中間兩字以 `**` 取代（例：abcdef → ab**ef）
     *
     * 與 {@see maskString} 的「遮中間 60%」不同，這是舊版 API_Frontend 沿用至今的規則，
     * 老虎機爭霸賽排行榜與積寶活動中獎名單共用；改動等同改動線上顯示，需經 PM 確認。
     */
    public static function maskEventUsername(string $username): string
    {
        $middle = (int) floor((strlen($username) - 2) / 2);

        return substr($username, 0, max($middle, 0)) . '**' . substr($username, $middle + 2);
    }

    /**
     * 刷分排行榜排行榜的帳號遮蔽：只保留頭尾各一字，中間全遮（例：abcdef → a****f）
     *
     * tpower-master 獨有規則，與 {@see maskEventUsername} 不同組活動、不共用。
     */
    public static function maskUsernameKeepEnds(string $username): string
    {
        $length = strlen($username);

        return substr($username, 0, 1) . str_repeat('*', max($length - 2, 0)) . substr($username, -1);
    }

    /**
     * 遮罩規則表：軟規則（image / token / mobile / email）＋會員端驗證碼／一次性 token（{@see MEMBER_SECRET_KEYS}）
     * ＋憑證／PII 三 profile＋密碼類全遮（{@see PASSWORD_KEYS}，覆蓋前面同名鍵）
     * （{@see credentialRules}）。政策見 trait 頂部——一律部分遮罩、長度門檻退回全遮。
     *
     * @return array<string, Closure(string): string>
     */
    private static function maskRules(): array
    {
        return self::$maskRules ??= [
            'image' => static fn (string $value): string => '*** 略 ***',
            'token' => static fn (string $value): string => str_repeat('*', 7) . substr($value, 5),
            'agent_token' => static fn (string $value): string => str_repeat('*', 7) . substr($value, 5),
            'access_token' => static fn (string $value): string => str_repeat('*', 7) . substr($value, 5),
            'mobile' => static fn (string $value): string => self::maskMobile($value),
            'email' => static fn (string $value): string => self::maskEmail($value),
            ...array_fill_keys(self::MEMBER_SECRET_KEYS, static fn (string $value): string => self::maskSecret($value)),
            ...self::credentialRules(),
            // 密碼類例外放最後，蓋掉 credentialRules 裡 password／payment_password 的秘密類規則
            ...array_fill_keys(self::PASSWORD_KEYS, static fn (string $value): string => '***'),
        ];
    }

    /**
     * 憑證／PII 三 profile 的規則表（帳號前 3 後 4／姓名頭尾字／秘密只露尾 4）。
     * maskData 與 maskCredentials 共用本表，避免兩處 drift。
     *
     * @return array<string, Closure(string): string>
     */
    private static function credentialRules(): array
    {
        return self::$credentialRules ??= [
            ...array_fill_keys(self::ACCOUNT_NUMBER_KEYS, static fn (string $value): string => self::maskPartial($value)),
            ...array_fill_keys(self::NAME_KEYS, static fn (string $value): string => self::maskName($value)),
            ...array_fill_keys(self::SECRET_KEYS, static fn (string $value): string => self::maskSecret($value)),
        ];
    }

    /**
     * 部分遮罩：頭尾可見、中間遮罩（mb-safe）。長度 ≤ head+tail 時前後段重疊等同全洩，
     * 退回全遮——「一律部分遮罩」政策下只允許經由長度門檻出現 '***'。
     */
    private static function maskPartial(string $value, int $head = 3, int $tail = 4): string
    {
        $length = mb_strlen($value);

        if ($length <= $head + $tail) {
            return '***';
        }

        return mb_substr($value, 0, $head) . str_repeat('*', $length - $head - $tail) . mb_substr($value, -$tail);
    }

    /**
     * 秘密類遮罩：只露尾 4 供對單；長度 < 12 時（CVV 3 碼、PIN 4-6 碼、短密碼）
     * 露任何字元都損失過多熵值，退回全遮。
     */
    private static function maskSecret(string $value): string
    {
        $length = mb_strlen($value);

        if ($length < 12) {
            return '***';
        }

        return str_repeat('*', $length - 4) . mb_substr($value, -4);
    }

    /**
     * 姓名／戶名遮罩：頭尾字可見、中間全遮（例：王小明 → 王*明、JOHN TAN → J******N）。
     * 必須用 mb_* 處理——{@see maskString} 等既有規則是 byte 級 substr，切中文會產生壞字。
     * 長度 ≤ 2 時尾字即洩漏大半，只留首字。
     */
    private static function maskName(string $value): string
    {
        $length = mb_strlen($value);

        if ($length <= 1) {
            return '***';
        }

        if ($length === 2) {
            return mb_substr($value, 0, 1) . '*';
        }

        return mb_substr($value, 0, 1) . str_repeat('*', $length - 2) . mb_substr($value, -1);
    }

    /**
     * 遞迴遍歷陣列、依鍵名套用遮罩規則。scalar 一律 cast 後遮罩——
     * JSON decode 後的數字卡號／帳號（int）同樣得遮，只遮 string 是 fail-open 缺口。
     *
     * $maskWholeArray（工單 4540，只有 {@see maskData} 開）：敏感鍵的值是陣列時整個遮成 '***'，不遞迴進去。
     * 遞迴只認鍵名，`new_password: ['原文']` 的內層鍵是 0，會原文留下。{@see maskCredentials} 與
     * {@see maskVendorInfo} 維持遞迴：前者給 external 三方回調，範圍不擴大；後者的結果要送回編輯頁，
     * 儲存時由 {@see restoreMaskedVendorInfo} 依原結構還原，整段換成字串會改變那條流程。
     *
     * @param  array<string, Closure(string): string>  $rules
     */
    private static function walkMask(array $data, array $rules, bool $maskWholeArray = false): array
    {
        foreach ($data as $key => $value) {
            if ($maskWholeArray && is_array($value) && isset($rules[$key])) {
                $data[$key] = '***';
            } elseif (is_array($value)) {
                $data[$key] = self::walkMask($value, $rules, $maskWholeArray);
            } elseif (isset($rules[$key]) && is_scalar($value)) {
                $data[$key] = $rules[$key]((string) $value);
            }
        }

        return $data;
    }

    /**
     * 前 3 碼＋後 4 碼可見（委派 {@see maskPartial}，單一實作）。
     * http 開頭視為誤放的 URL，維持原樣（歷史相容）。
     */
    private static function maskMobile(string $value): string
    {
        if (Str::startsWith($value, 'http')) {
            return $value;
        }

        return self::maskPartial($value);
    }

    /**
     * 首末字元可見；長度 ≤ 2 時首末即全部內容，改全遮罩。
     */
    private static function maskEmail(string $value): string
    {
        if (strlen($value) <= 2) {
            return '***';
        }

        return substr($value, 0, 1) . '*' . substr($value, -1);
    }
}
