import { requestClient } from '#/api/request';

/**
 * 會員註冊/登入/實名驗證欄位設定 + 註冊限制（agent_groups type=6）。
 * page_type: 1=註冊欄位、2=登入欄位、3=實名驗證欄位。
 * apis 後端，路徑 agent/api/member-field-setting/*，成功碼 200；失敗時 code 為 400、訊息放在 message。
 */
export interface MemberFieldSettingRow {
  admin?: number;
  agent_group_id?: number | string;
  currency_id?: number;
  extra_config: null | Record<string, any>;
  field_group: number;
  field_key: string;
  id?: number;
  is_required: number;
  page_type?: number;
  sort: number;
  status: number;
}

export interface MemberRegisterLimitCustomValue {
  max_limit: number;
  value: string;
}

export interface MemberRegisterLimitRow {
  custom_enabled: number;
  custom_values: MemberRegisterLimitCustomValue[];
  field_key: string;
  platform_enabled: number;
  platform_max_limit: null | number;
}

interface Body<T = unknown> {
  code: number | string;
  data?: T;
  message?: string;
}

export type MemberFieldSettingBody = Body<{
  group_id: number | string;
  group_name: string;
  limits: MemberRegisterLimitRow[];
  login: MemberFieldSettingRow[];
  register: MemberFieldSettingRow[];
  verification: MemberFieldSettingRow[];
}>;

/** 取得某組別（agent_groups type=6）的註冊/登入欄位設定 + 註冊限制 */
export async function getMemberFieldSettingApi(groupId: number | string) {
  return requestClient.post<MemberFieldSettingBody>(
    '/agent/api/member-field-setting/view',
    { group_id: groupId },
    { responseReturn: 'body' },
  );
}

/** 組別底下是否已有會員（玩家帳號從開改成關、存檔前查，有會員才跳確認框） */
export async function getMemberFieldSettingHasMembersApi(
  groupId: number | string,
) {
  return requestClient.post<Body<{ has_members: boolean }>>(
    '/agent/api/member-field-setting/has-members',
    { group_id: groupId },
    { responseReturn: 'body' },
  );
}

/**
 * 更新欄位設定（送來的列 upsert，page_type 1=註冊 2=登入 3=實名驗證）。
 * 註冊頁玩家帳號關閉時，後端會把登入方式同步改成只用手機號碼。
 */
export async function updateMemberFieldsApi(payload: {
  fields: Array<Record<string, any>>;
  group_id: number | string;
  page_type: number;
}) {
  return requestClient.post<Body>(
    '/agent/api/member-field-setting/fields/save',
    payload,
    { responseReturn: 'body' },
  );
}

/** 更新註冊限制設定（主檔 upsert + 自訂逐值整組重建） */
export async function updateMemberLimitsApi(payload: {
  group_id: number | string;
  limits: Array<Record<string, any>>;
}) {
  return requestClient.post<Body>(
    '/agent/api/member-field-setting/register-limits/save',
    payload,
    { responseReturn: 'body' },
  );
}
