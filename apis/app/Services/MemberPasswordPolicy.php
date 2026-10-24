<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Member\PasswordCharRequirementEnum;
use App\Enums\Member\PasswordCharTypeEnum;
use App\Exceptions\ApiException;

/**
 * 會員設定新密碼的規則：註冊、忘記密碼、修改密碼的新密碼一律用這一份檢查。
 * 規則來源是代理註冊欄位設定 password 的 extra_config；登入密碼與修改密碼的舊密碼只做比對，禁止套用。
 */
final readonly class MemberPasswordPolicy
{
    /** 允許的符號；固定這幾個，不開放後台自訂 */
    public const string ALLOWED_SYMBOLS = '!@#$%^&*-_+=?';

    /** 代理沒有註冊欄位設定時的字數，與舊版接口寫死的 between:6,12 一致 */
    private const int LEGACY_MIN_LENGTH = 6;

    private const int LEGACY_MAX_LENGTH = 12;

    /**
     * @param  array<string, PasswordCharRequirementEnum>  $rules  PasswordCharTypeEnum value => 要求
     */
    private function __construct(
        public ?int $minLength,
        public ?int $maxLength,
        public array $rules,
    ) {}

    /**
     * @param  array<string, mixed>|null  $extraConfig  password 欄位的 extra_config（新舊格式皆可）
     */
    public static function fromExtraConfig(?array $extraConfig): self
    {
        $min = $extraConfig['min_length'] ?? null;
        $max = $extraConfig['max_length'] ?? null;
        $rulesConfig = is_array($extraConfig['rules'] ?? null) ? $extraConfig['rules'] : [];

        $rules = [];
        foreach (PasswordCharTypeEnum::cases() as $type) {
            $rules[$type->value] = PasswordCharRequirementEnum::fromConfig($rulesConfig[$type->value] ?? null);
        }

        // 舊版「大寫字母」已併入英文字母：有勾大寫等同英文字母必須包含
        if (($rulesConfig['uppercase'] ?? false) === true) {
            $rules[PasswordCharTypeEnum::ALPHABET->value] = PasswordCharRequirementEnum::REQUIRED;
        }

        return new self(
            minLength: $min === null || $min === '' ? null : (int) $min,
            maxLength: $max === null || $max === '' ? null : (int) $max,
            rules: $rules,
        );
    }

    /**
     * 代理沒有註冊欄位設定時的規則：只限字數，類型都可包含（半形與符號範圍仍檢查）。
     */
    public static function legacy(): self
    {
        return self::fromExtraConfig([
            'min_length' => self::LEGACY_MIN_LENGTH,
            'max_length' => self::LEGACY_MAX_LENGTH,
        ]);
    }

    /**
     * 給前端渲染提示用的正規化設定：rules 一律轉成三選一字串、移除大寫字母，並帶允許的符號。
     *
     * @return array{min_length:int|null, max_length:int|null, rules:array<string,string>, allowed_symbols:string}
     */
    public function toExtraConfig(): array
    {
        return [
            'min_length' => $this->minLength,
            'max_length' => $this->maxLength,
            'rules' => array_map(fn (PasswordCharRequirementEnum $r) => $r->value, $this->rules),
            'allowed_symbols' => self::ALLOWED_SYMBOLS,
        ];
    }

    /**
     * 依序檢查：半形 → 字數 → 符號範圍 → 不可包含 → 必須包含；第一條沒過就拋，錯誤訊息指出是哪一條。
     *
     * @throws ApiException
     */
    public function assert(string $password): void
    {
        // 只收半形可見字元與空白；全形字、中文、emoji 都在此擋下
        if (preg_match('/[^\x20-\x7E]/', $password)) {
            throw new ApiException(__('error_message.2060'), 2060);
        }

        $this->assertLength($password);

        $symbolForbidden = $this->rules[PasswordCharTypeEnum::SYMBOL->value] === PasswordCharRequirementEnum::FORBIDDEN;
        if (!$symbolForbidden && preg_match('/[^A-Za-z0-9' . preg_quote(self::ALLOWED_SYMBOLS, '/') . ']/', $password)) {
            throw new ApiException(__('error_message.2061', ['symbols' => self::ALLOWED_SYMBOLS]), 2061);
        }

        $forbidden = $this->labelsWhere(PasswordCharRequirementEnum::FORBIDDEN, $password, matched: true);
        if ($forbidden !== []) {
            throw new ApiException(__('error_message.2062', ['rules' => implode('、', $forbidden)]), 2062);
        }

        $missing = $this->labelsWhere(PasswordCharRequirementEnum::REQUIRED, $password, matched: false);
        if ($missing !== []) {
            throw new ApiException(__('error_message.2056', ['rules' => implode('、', $missing)]), 2056);
        }
    }

    /**
     * @throws ApiException
     */
    private function assertLength(string $password): void
    {
        $len = mb_strlen($password);
        if (($this->minLength !== null && $len < $this->minLength) || ($this->maxLength !== null && $len > $this->maxLength)) {
            throw new ApiException(
                __('error_message.2054', [
                    'field' => __('validation.attributes.password'),
                    'min' => $this->minLength ?? 0,
                    'max' => $this->maxLength ?? 0,
                ]),
                2054,
            );
        }
    }

    /**
     * 取出要求為 $requirement、且「密碼含該類型」與 $matched 相符的類型名稱。
     *
     * @return array<int, string>
     */
    private function labelsWhere(PasswordCharRequirementEnum $requirement, string $password, bool $matched): array
    {
        $labels = [];
        foreach (PasswordCharTypeEnum::cases() as $type) {
            if ($this->rules[$type->value] === $requirement && (bool) preg_match($type->pattern(), $password) === $matched) {
                $labels[] = $type->label();
            }
        }

        return $labels;
    }
}
