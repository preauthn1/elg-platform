/**
 * 會員註冊/登入欄位的 extra_config 結構定義（schema 驅動 UI 用）。
 * key 對齊 apis MemberFieldKeyEnum / API_Agent MemberFieldSettingConstant 的預設 extra_config。
 * 只有這裡列出的欄位有「進階設定」可展開；其餘欄位 extra_config 為 null、不顯示展開。
 */

export interface ConfigControl {
  /** extra_config 內的鍵名 */
  key: string;
  /** 標籤 i18n key */
  labelKey: string;
  /** number 型的上限（含）；未指定表示不限 */
  max?: number;
  /** select 選項值（i18n key 為 `${labelKey}_${opt}`） */
  options?: string[];
  /** rules 型的子鍵（i18n key 為 `cfg_rule_${rk}`），每個子鍵各自三選一（PASSWORD_RULE_OPTIONS） */
  ruleKeys?: string[];
  /** 說明 tooltip i18n key（可選） */
  tipKey?: string;
  type: 'boolean' | 'number' | 'rules' | 'select' | 'text';
}

/**
 * 玩家帳號／設定密碼的字數上限：members.username 為 varchar(32)，
 * 設定值超過欄位長度會讓玩家照設定填卻在註冊時寫入失敗，故上限訂 30 留餘裕。
 * 密碼存 bcrypt 雜湊、不受欄位長度限制，但比照同一把尺避免設出不合理的值。
 */
export const FIELD_LENGTH_MAX = 30;

/**
 * 手機號碼的字數上限：apis 端多支接口（SendCodeRequest、CheckCodeRequest、
 * RegisterRequest 等）對 mobile 寫死 `digits_between:1,20`，且量的是「國碼＋號碼」整串。
 * countries 表最長國碼為 3 位（886／852／880），故號碼本體上限訂 16
 * ——16＋3＝19，落在那道硬限制內仍留 1 位餘裕。
 */
export const MOBILE_LENGTH_MAX = 16;

/**
 * 玩家帳號關閉時，系統自動產生的帳號為「前綴-13 碼 uniqid」，前綴存在註冊頁玩家帳號那一列的
 * extra_config.username_prefix。規則對齊 apis MemberFieldSettingManageService：
 * 英數字 2～16 字、至少一個英文字母（16＋1＋13＝30，不超過 FIELD_LENGTH_MAX），存檔前轉小寫。
 */
export const USERNAME_PREFIX_KEY = 'username_prefix';
export const USERNAME_PREFIX_PATTERN = /^(?=.*[A-Z])[A-Z0-9]{2,16}$/i;
export const USERNAME_PREFIX_MAX = 16;

/**
 * 密碼字元類型的要求：必須包含／可包含／不可包含（i18n key 為 `cfg_rule_${opt}`）。
 * 值對齊 apis PasswordCharRequirementEnum。
 */
export const PASSWORD_RULE_OPTIONS = [
  'required',
  'optional',
  'forbidden',
] as const;
export type PasswordRuleOption = (typeof PASSWORD_RULE_OPTIONS)[number];

/**
 * 舊版勾選制存 boolean：有勾＝必須包含、沒勾＝可包含；無法辨識的值當可包含。
 * 判讀與 apis PasswordCharRequirementEnum::fromConfig 一致。
 */
function toPasswordRuleOption(value: unknown): PasswordRuleOption {
  if (typeof value === 'boolean') return value ? 'required' : 'optional';
  return PASSWORD_RULE_OPTIONS.includes(value as PasswordRuleOption)
    ? (value as PasswordRuleOption)
    : 'optional';
}

export const EXTRA_CONFIG_SCHEMA: Record<string, ConfigControl[]> = {
  mobile: [
    {
      key: 'min_length',
      labelKey: 'cfg_min_length',
      max: MOBILE_LENGTH_MAX,
      type: 'number',
    },
    {
      key: 'max_length',
      labelKey: 'cfg_max_length',
      max: MOBILE_LENGTH_MAX,
      type: 'number',
    },
  ],
  password: [
    {
      key: 'min_length',
      labelKey: 'cfg_min_length',
      max: FIELD_LENGTH_MAX,
      type: 'number',
    },
    {
      key: 'max_length',
      labelKey: 'cfg_max_length',
      max: FIELD_LENGTH_MAX,
      type: 'number',
    },
    {
      key: 'rules',
      labelKey: 'cfg_password_rules',
      ruleKeys: ['alphabet', 'number', 'symbol'],
      tipKey: 'cfg_password_rules_tip',
      type: 'rules',
    },
  ],
  username: [
    {
      key: 'min_length',
      labelKey: 'cfg_min_length',
      max: FIELD_LENGTH_MAX,
      type: 'number',
    },
    {
      key: 'max_length',
      labelKey: 'cfg_max_length',
      max: FIELD_LENGTH_MAX,
      type: 'number',
    },
    {
      key: USERNAME_PREFIX_KEY,
      labelKey: 'cfg_username_prefix',
      max: USERNAME_PREFIX_MAX,
      type: 'text',
    },
  ],
};

/**
 * 玩家帳號的進階設定依狀態二選一：開啟時顯示字數設定，關閉時只顯示帳號前綴。
 * 兩組值都留在 extra_config，切換不會洗掉另一組。
 */
export function visibleConfigControls(
  fieldKey: string,
  status: number,
): ConfigControl[] {
  const schema = EXTRA_CONFIG_SCHEMA[fieldKey] ?? [];
  if (fieldKey !== 'username') return schema;
  const usernameOff = Number(status) === 0;
  return schema.filter((c) => (c.key === USERNAME_PREFIX_KEY) === usernameOff);
}

/** 該欄位的字數上限（無設定回 undefined，表示不限） */
export function fieldLengthMax(fieldKey: string): number | undefined {
  return EXTRA_CONFIG_SCHEMA[fieldKey]?.find((c) => c.key === 'max_length')
    ?.max;
}

export function hasFieldConfig(fieldKey: string): boolean {
  return Boolean(EXTRA_CONFIG_SCHEMA[fieldKey]);
}

/**
 * 把某欄位的 extra_config 正規化成完整結構（供 v-model 綁定與送出）。
 * 有 schema 的欄位：以 schema 補齊缺鍵、型別歸位；無 schema 的欄位：維持 null。
 * 只輸出 schema 定義的 key：已下架選項（如 username 的 mobile_as_username / lowercase_username、
 * password rules 的 uppercase）
 * 殘留在 DB 的舊 key 不再原樣帶回、儲存時自然洗掉。
 */
export function normalizeExtraConfig(
  fieldKey: string,
  existing: null | Record<string, any> | undefined,
): null | Record<string, any> {
  const schema = EXTRA_CONFIG_SCHEMA[fieldKey];
  if (!schema) return null;

  const src = existing ?? {};
  const cfg: Record<string, any> = {};
  for (const ctrl of schema) {
    switch (ctrl.type) {
      case 'boolean': {
        cfg[ctrl.key] = Boolean(src[ctrl.key]);
        break;
      }
      case 'number': {
        cfg[ctrl.key] = src[ctrl.key] ?? null;
        break;
      }
      case 'rules': {
        const rulesSrc = src[ctrl.key] ?? {};
        const rules: Record<string, PasswordRuleOption> = {};
        for (const rk of ctrl.ruleKeys ?? []) {
          rules[rk] = toPasswordRuleOption(rulesSrc[rk]);
        }
        // 舊版「大寫字母」已併入英文字母：有勾大寫等同英文字母必須包含
        if (rulesSrc.uppercase === true && 'alphabet' in rules) {
          rules.alphabet = 'required';
        }
        cfg[ctrl.key] = rules;
        break;
      }
      case 'select': {
        cfg[ctrl.key] = src[ctrl.key] ?? ctrl.options?.[0] ?? null;
        break;
      }
      case 'text': {
        cfg[ctrl.key] = src[ctrl.key] ?? '';
        break;
      }
    }
  }
  return cfg;
}
