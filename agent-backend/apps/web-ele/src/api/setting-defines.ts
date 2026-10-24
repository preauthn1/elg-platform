import { requestClient } from '#/api/request';

/**
 * 代理設置鍵值定義與管理 API（agent/advance_setting_defines）。 */

export interface SettingDefineRow {
  /** 1=代理可編輯 */
  agent_edit?: number;
  data_type?: string;
  default_value?: null | number | string;
  is_visible?: number;
  language_text_code?: string;
  setting_id: number | string;
  text?: string;
  type?: number | string;
}

export interface SettingDefineGroup {
  is_visible?: number;
  setting_category_id: number | string;
  setting_category_language_text_code?: string;
  setting_category_name?: string;
  settings?: SettingDefineRow[];
}

/** 取得設置鍵值定義 */
export async function getSettingDefinesApi() {
  return requestClient.post<{
    code: number | string;
    data?: { groups?: SettingDefineGroup[] };
    message: string;
  }>('/api/agent/setting-defines', {}, { responseReturn: 'body' });
}

/** 新增主類別(type 1,parent 0)或設定項(type 2/3)。 */
export async function createSettingDefineApi(payload: {
  agent_edit?: number | string;
  data_type?: string;
  default_value?: string;
  language_text_code: string;
  memo?: string;
  parent: number | string;
  setting_id: number | string;
  type: number | string;
}) {
  return requestClient.post<{
    code: number | string;
    data?: any;
    message: string;
  }>('/api/agent/setting-defines/create', payload, { responseReturn: 'body' });
}

/** 產生該設定的 INSERT SQL */
export async function getSettingInsertSqlApi(settingId: number | string) {
  return requestClient.post<{
    code: number | string;
    data?: { sql_list?: string[] };
    message: string;
  }>(
    '/api/agent/insert-sql-generate',
    { setting_id: settingId },
    { responseReturn: 'body' },
  );
}

export interface LanguageTextSetting {
  language_id: number | string;
  language_name?: string;
  value?: string;
}

/** 取多語文字 */
export async function getLanguageTextApi(languageCode: string) {
  return requestClient.post<{
    code: number | string;
    data?: {
      available_languages?: Array<{ id?: number | string; name?: string }>;
      language_code?: string;
      text_settings?: LanguageTextSetting[];
    };
    message: string;
  }>(
    '/api/language-text/show',
    { language_code: languageCode },
    { responseReturn: 'body' },
  );
}

/** 更新多語文字 */
export async function updateLanguageTextApi(payload: Record<string, any>) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/api/language-text/update',
    payload,
    { responseReturn: 'body' },
  );
}

/** 自動指派客製化預設值 */
export async function autoAssignDefaultValueApi(settingId: number | string) {
  return requestClient.post<{
    code: number | string;
    data?: string;
    message: string;
  }>(
    '/api/agent/customized-settings/assign-default-value',
    { setting_id: settingId },
    { responseReturn: 'body' },
  );
}
