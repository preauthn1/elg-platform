<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EmailTextTypeEnum;
use App\Models\EmailGatewayGroupDetail;
use App\Models\EmailText;
use Illuminate\Support\Facades\DB;

/**
 * 驗證碼郵件樣板與電子郵件設置組別的群組設定（工單4286）
 *
 * 取樣板順序：該語系 → 全語系（language_id=0）→ 查無由呼叫端拋 EmailTemplateNotFoundException 中斷。
 * 代理沒綁電子郵件設置組別（email_gateway_group_id 為 0）時不查表，直接用內建預設文字，
 * 行為與改動前一致——正式站目前所有代理都未綁組別，不能因為沒有樣板就寄不出信。
 */
class EmailTextService
{
    /** 改動前寫死在程式裡的主旨，也是 migration 補預設列用的值 */
    public const string DEFAULT_SUBJECT = 'Verification Code';

    /** 改動前寫死在程式裡的內文（已換成佔位符） */
    public const string DEFAULT_CONTENT = 'The PIN for {email} is: 【{code}】';

    /** 樣板只支援這兩個佔位符，其他 {xxx} 原樣輸出 */
    public const array PLACEHOLDERS = ['{code}', '{email}'];

    /** email_texts.language_id=0 代表全語系預設列 */
    public const int ALL_LANGUAGE_ID = 0;

    /**
     * 為組別補齊 8 個類型的全語系預設列；已存在者不動（靠 uk_email_texts_group_type_language 擋重複）。
     *
     * 組別是在舊系統（API_Agent）建立的，這裡沒有建立時的 hook，改成首次載入／首次寄信時補。
     */
    public function ensureDefaults(int $groupId, int $admin = 0): void
    {
        if ($groupId <= 0) {
            return;
        }

        $now = now();
        $rows = array_map(fn (EmailTextTypeEnum $type): array => [
            'email_gateway_group_id' => $groupId,
            'type_id' => $type->value,
            'language_id' => self::ALL_LANGUAGE_ID,
            'subject' => self::DEFAULT_SUBJECT,
            'content' => self::DEFAULT_CONTENT,
            'created_at' => $now,
            'updated_at' => $now,
            'admin' => $admin,
        ], EmailTextTypeEnum::cases());

        DB::table('email_texts')->insertOrIgnore($rows);
    }

    /**
     * 組別是否已有任何樣板列（區分「從未初始化」與「運營刪掉某一列」）
     */
    public function hasAnyText(int $groupId): bool
    {
        return EmailText::where('email_gateway_group_id', $groupId)->exists();
    }

    /**
     * 取寄信用的主旨與內文（未替換佔位符）
     *
     * - 沒綁組別（$groupId <= 0）→ 內建預設
     * - 組別從未初始化 → 先補預設列
     * - 該語系 → 全語系 → 都沒有回 null，由呼叫端就地拋例外
     *
     * @return array{subject: string, content: string}|null
     */
    public function resolve(int $groupId, EmailTextTypeEnum $type, int $languageId): ?array
    {
        if ($groupId <= 0) {
            return ['subject' => self::DEFAULT_SUBJECT, 'content' => self::DEFAULT_CONTENT];
        }

        if (!$this->hasAnyText($groupId)) {
            $this->ensureDefaults($groupId);
        }

        $text = EmailText::where('email_gateway_group_id', $groupId)
            ->where('type_id', $type->value)
            ->whereIn('language_id', array_unique([$languageId, self::ALL_LANGUAGE_ID]))
            // 指定語系排前面，全語系(0)墊底（language_id = 0 為真值 1，ASC 排後面）
            ->orderByRaw('language_id = ? ASC', [self::ALL_LANGUAGE_ID])
            ->first();

        if (!$text) {
            return null;
        }

        return ['subject' => $text->subject, 'content' => $text->content];
    }

    /**
     * 替換佔位符；只換 {code} 與 {email}，其他 {xxx} 與任何 HTML 標籤原樣保留（純文字寄送）
     */
    public function render(string $template, string $code, string $email): string
    {
        return strtr($template, [
            '{code}' => $code,
            '{email}' => $email,
        ]);
    }

    /**
     * 電子郵件設置組別的驗證碼有效時間（分）與有效時間內最大發送次數
     *
     * 組別沒有設定時直接吃 config('custom.mail.*')（.env MAIL_TOKEN_EXPIRED / MAIL_MAX_SEND），不回退簡訊組別。
     *
     * @return array{0: int, 1: int}
     */
    public function getTokenExpiredAndMaxSend(?int $groupId): array
    {
        $default = [
            (int) config('custom.mail.token_expired'),
            (int) config('custom.mail.max_send'),
        ];

        if (!$groupId || $groupId <= 0) {
            return $default;
        }

        $detail = EmailGatewayGroupDetail::where('agent_group_id', $groupId)->first();
        if (!$detail) {
            return $default;
        }

        $setting = $detail->setting;

        return [
            (int) ($setting['token_expired'] ?? $default[0]),
            (int) ($setting['max_send'] ?? $default[1]),
        ];
    }
}
