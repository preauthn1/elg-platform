<script lang="ts" setup>
import type { MemberFieldSettingRow, MemberRegisterLimitRow } from '#/api';

import { computed, onMounted, reactive, ref, toRaw } from 'vue';
import { useRoute } from 'vue-router';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElInput,
  ElInputNumber,
  ElMessage,
  ElMessageBox,
  ElSwitch,
  ElTable,
  ElTableColumn,
  ElTabPane,
  ElTabs,
} from 'element-plus';

import {
  getMemberFieldSettingApi,
  getMemberFieldSettingHasMembersApi,
  updateMemberFieldsApi,
  updateMemberLimitsApi,
} from '#/api';
import { tl } from '#/locales';

import MemberFieldTable from './components/member-field-table.vue';
import {
  fieldLengthMax,
  normalizeExtraConfig,
  USERNAME_PREFIX_KEY,
  USERNAME_PREFIX_PATTERN,
} from './member-field-schema';

defineOptions({ name: 'AgentMemberFieldSetting' });

const props = defineProps<{ embedGroupId?: string }>();

const route = useRoute();
const groupId = props.embedGroupId || (route.query.group_id as string) || '';

/** 各頁面欄位固定順序（對齊 apis MemberFieldKeyEnum / API_Agent 白名單，非使用者可增減） */
// 驗證碼不再獨立成欄位:手機號碼欄位本身即含「填號 + 發碼 + 驗證」一體,電子郵件同理。
// 故清單不列 sms_verify_code / email_verify_code,手機/信箱開啟即代表含驗證。
const REGISTER_FIELD_KEYS = [
  'username',
  'last_name',
  'password',
  'confirm_password',
  'referral_code',
  'mobile',
  'email',
  'birthday',
];
// 登入 3 選 1：玩家帳號 / 手機號碼 / 帳號+手機號（擇一）；密碼恆為必要、不在此顯示
const LOGIN_FIELD_KEYS = ['username', 'mobile'];
/** 登入「帳號+手機號」虛擬列 key：對應底層 username=1 且 mobile=1 */
const LOGIN_BOTH_KEY = 'username_mobile';
/** 登入 3 選 1 顯示列 key（含虛擬列） */
const LOGIN_DISPLAY_KEYS = new Set([LOGIN_BOTH_KEY, 'mobile', 'username']);
/**
 * 註冊欄位在 API 未回傳時的初始值：玩家帳號缺列視為開啟（與 apis 判斷關閉模式的規則一致），
 * 避免顯示成關閉、存檔時誤觸發玩家帳號關閉的規則。
 */
const REGISTER_FALLBACKS: Record<string, Partial<MemberFieldSettingRow>> = {
  username: { is_required: 1, status: 1 },
};
/** 實名驗證欄位：真實姓名恆開啟必填，其餘顯示與必填可自行決定 */
const VERIFICATION_FIELD_KEYS = [
  'last_name',
  'bank',
  'birthday',
  'id_number',
  'id_photo',
];
/**
 * 實名驗證欄位在 API 未回傳時的初始值（對齊後端 verificationFieldDefaults）：
 * 真實姓名與銀行卡維持現行流程的開啟必填，避免顯示成關閉、存檔後把銀行卡關掉。
 */
const VERIFICATION_FALLBACKS: Record<string, Partial<MemberFieldSettingRow>> = {
  bank: { is_required: 1, status: 1 },
  last_name: { is_required: 1, status: 1 },
};
// 密碼經 bcrypt 無法比對重複,不設密碼限制項(2026-07-29 移除)
const LIMIT_FIELD_KEYS = ['email', 'phone', 'ip_address', 'name'];
/** apis 成功碼 */
const SUCCESS_CODE = 200;
/** 註冊/綁定限制:每欄位自訂逐值上限組數 */
const MAX_LIMIT_VALUES = 10;

/** 註冊限制欄位顯示名：name（真實姓名）用 page 情境專屬 key，不動全域共用 key */
const LIMIT_LABEL_KEY: Record<string, string> = { name: 'real_name' };
function fieldLabel(key: string) {
  return tl(LIMIT_LABEL_KEY[key] ?? key, key);
}

/** 註冊欄位顯示名（錯誤訊息用）：對齊 member-field-table 的情境 label */
function registerFieldLabel(key: string) {
  if (key === 'username') return tl('player_account');
  if (key === 'password') return tl('set_password');
  return tl(key, key);
}

/** 深拷貝快照（extra_config 為巢狀物件，取消還原需切斷參照；先 toRaw 脫離 reactive proxy） */
function clone<T>(rows: T[]): T[] {
  return rows.map((r) => structuredClone(toRaw(r)));
}

function defaultFieldRow(
  fieldKey: string,
  pageType: number,
): MemberFieldSettingRow {
  return {
    extra_config: null,
    field_group: 1,
    field_key: fieldKey,
    is_required: 0,
    page_type: pageType,
    sort: 0,
    status: 0,
  };
}

function defaultLimitRow(fieldKey: string): MemberRegisterLimitRow {
  return {
    custom_enabled: 0,
    custom_values: [],
    field_key: fieldKey,
    platform_enabled: 0,
    platform_max_limit: null,
  };
}

/**
 * 依固定欄位順序 merge API 資料；缺的補預設值，並把 extra_config 正規化成完整結構。
 * fallbacks 用於 API 沒回該欄位時的初始值，避免把「未回傳」誤存成關閉。
 */
function mergeFieldRows(
  keys: string[],
  rows: MemberFieldSettingRow[] | undefined,
  pageType: number,
  fallbacks?: Record<string, Partial<MemberFieldSettingRow>>,
) {
  return keys.map((key) => {
    const found = (rows ?? []).find((r) => r.field_key === key);
    const merged = found
      ? { ...defaultFieldRow(key, pageType), ...found }
      : { ...defaultFieldRow(key, pageType), ...fallbacks?.[key] };
    merged.extra_config = normalizeExtraConfig(key, merged.extra_config);
    return merged;
  });
}

function mergeLimitRows(rows: MemberRegisterLimitRow[] | undefined) {
  return LIMIT_FIELD_KEYS.map((key) => {
    const found = (rows ?? []).find((r) => r.field_key === key);
    return found
      ? {
          ...defaultLimitRow(key),
          ...found,
          custom_values: [...(found.custom_values ?? [])],
        }
      : defaultLimitRow(key);
  });
}

/** 欄位列 → 更新 API payload 形狀 */
function toFieldPayload(r: MemberFieldSettingRow) {
  return {
    extra_config: r.extra_config,
    field_group: r.field_group,
    field_key: r.field_key,
    is_required: r.is_required,
    sort: r.sort,
    status: r.status,
  };
}

/**
 * 登入 3 選 1 顯示列：玩家帳號 / 手機號碼 / 帳號+手機號（虛擬列）。
 * 由底層 username / mobile 的 status 推導選中列：兩者皆開→帳號+手機號；僅手機→手機；其餘（含皆未開）→玩家帳號。
 */
function buildLoginDisplayRows(realRows: MemberFieldSettingRow[]) {
  const usernameRow =
    realRows.find((r) => r.field_key === 'username') ??
    defaultFieldRow('username', 2);
  const mobileRow =
    realRows.find((r) => r.field_key === 'mobile') ??
    defaultFieldRow('mobile', 2);
  const bothRow = defaultFieldRow(LOGIN_BOTH_KEY, 2);
  bothRow.sort = 3;

  const usernameOn = Number(usernameRow.status) === 1;
  const mobileOn = Number(mobileRow.status) === 1;
  let mode: 'both' | 'mobile' | 'username' = 'username';
  if (usernameOn && mobileOn) mode = 'both';
  else if (mobileOn) mode = 'mobile';
  usernameRow.status = mode === 'username' ? 1 : 0;
  mobileRow.status = mode === 'mobile' ? 1 : 0;
  bothRow.status = mode === 'both' ? 1 : 0;

  return [usernameRow, mobileRow, bothRow];
}

const loading = ref(false);
const groupName = ref('');
const activeTab = ref('register');

const registerRows = ref<MemberFieldSettingRow[]>([]);
const loginRows = ref<MemberFieldSettingRow[]>([]);
const verificationRows = ref<MemberFieldSettingRow[]>([]);
const limitRows = ref<MemberRegisterLimitRow[]>([]);

/**
 * 已存檔的註冊設定是否關閉玩家帳號（關閉模式）。以存檔後的狀態為準，不看註冊頁編輯中的值：
 * - 登入欄位設定：登入方式固定為手機號碼，三個選項鎖住
 * - 註冊/綁定限制：手機號碼上限鎖住，顯示「目前為一號一帳」；原設定值照常顯示、照常送回
 * - 註冊頁從開改成關時，存檔前查組別是否已有會員，有才跳確認框
 */
const usernameOffSaved = ref(false);

function usernameOffIn(rows: MemberFieldSettingRow[]): boolean {
  const row = rows.find((r) => r.field_key === 'username');
  return row !== undefined && Number(row.status) === 0;
}

/** 註冊/綁定限制：關閉模式下手機號碼上限鎖住（一號一帳） */
function limitLocked(row: MemberRegisterLimitRow): boolean {
  return usernameOffSaved.value && row.field_key === 'phone';
}

/** 登入列改成只用手機號碼（對齊 apis 存註冊時同步登入設定的結果） */
function applyLoginMobileOnly(rows: MemberFieldSettingRow[]) {
  for (const r of rows) {
    if (LOGIN_DISPLAY_KEYS.has(r.field_key)) {
      r.status = r.field_key === 'mobile' ? 1 : 0;
    }
  }
}

/** 各 tab 編輯前的快照，取消時用於還原 */
let registerSnapshot: MemberFieldSettingRow[] = [];
let loginSnapshot: MemberFieldSettingRow[] = [];
let verificationSnapshot: MemberFieldSettingRow[] = [];
let limitSnapshot: MemberRegisterLimitRow[] = [];

type FieldTab = 'login' | 'register' | 'verification';
const TAB_BY_PAGE_TYPE: Record<number, FieldTab> = {
  1: 'register',
  2: 'login',
  3: 'verification',
};

const editing = reactive({
  limits: false,
  login: false,
  register: false,
  verification: false,
});
const saving = reactive({
  limits: false,
  login: false,
  register: false,
  verification: false,
});

async function loadData() {
  if (!groupId) return;
  loading.value = true;
  try {
    const res = await getMemberFieldSettingApi(groupId);
    if (Number(res.code) === SUCCESS_CODE && res.data) {
      groupName.value = res.data.group_name ?? '';
      registerRows.value = mergeFieldRows(
        REGISTER_FIELD_KEYS,
        res.data.register,
        1,
        REGISTER_FALLBACKS,
      );
      usernameOffSaved.value = usernameOffIn(registerRows.value);
      // 確認密碼的必填恆等於狀態，既有資料不一致也修正顯示（對齊儲存時的強制規則）
      const confirmPasswordRow = registerRows.value.find(
        (r) => r.field_key === 'confirm_password',
      );
      if (confirmPasswordRow) {
        confirmPasswordRow.is_required =
          Number(confirmPasswordRow.status) === 1 ? 1 : 0;
      }
      loginRows.value = buildLoginDisplayRows(
        mergeFieldRows(LOGIN_FIELD_KEYS, res.data.login, 2),
      );
      verificationRows.value = mergeFieldRows(
        VERIFICATION_FIELD_KEYS,
        res.data.verification,
        3,
        VERIFICATION_FALLBACKS,
      );
      // 真實姓名恆為開啟必填，既有資料為關閉也修正顯示（對齊儲存時的強制規則）
      const realNameRow = verificationRows.value.find(
        (r) => r.field_key === 'last_name',
      );
      if (realNameRow) {
        realNameRow.is_required = 1;
        realNameRow.status = 1;
      }
      limitRows.value = mergeLimitRows(res.data.limits);
    } else {
      ElMessage.error(res.message || tl('failure'));
    }
  } finally {
    loading.value = false;
  }
}

function startEdit(tab: 'limits' | FieldTab) {
  if (tab === 'register') registerSnapshot = clone(registerRows.value);
  if (tab === 'login') loginSnapshot = clone(loginRows.value);
  if (tab === 'verification')
    verificationSnapshot = clone(verificationRows.value);
  if (tab === 'limits') limitSnapshot = clone(limitRows.value);
  editing[tab] = true;
}

function cancelEdit(tab: 'limits' | FieldTab) {
  if (tab === 'register') registerRows.value = registerSnapshot;
  if (tab === 'login') loginRows.value = loginSnapshot;
  if (tab === 'verification') verificationRows.value = verificationSnapshot;
  if (tab === 'limits') limitRows.value = limitSnapshot;
  editing[tab] = false;
}

async function saveFields(pageType: 1 | 2 | 3) {
  const tab = TAB_BY_PAGE_TYPE[pageType] as FieldTab;
  const rowsByTab: Record<FieldTab, MemberFieldSettingRow[]> = {
    login: loginRows.value,
    register: registerRows.value,
    verification: verificationRows.value,
  };
  const rows = rowsByTab[tab];

  // 登入 3 選 1：玩家帳號 / 手機號碼 / 帳號+手機號 擇一；虛擬列「帳號+手機號」映射底層 username=1 且 mobile=1
  let loginFields: null | ReturnType<typeof toFieldPayload>[] = null;
  if (pageType === 2) {
    const selected = rows.find(
      (r) => LOGIN_DISPLAY_KEYS.has(r.field_key) && Number(r.status) === 1,
    );
    if (!selected) {
      ElMessage.error(tl('login_field_need_identifier'));
      return;
    }
    const isBoth = selected.field_key === LOGIN_BOTH_KEY;
    const usernameRow =
      rows.find((r) => r.field_key === 'username') ??
      defaultFieldRow('username', 2);
    const mobileRow =
      rows.find((r) => r.field_key === 'mobile') ??
      defaultFieldRow('mobile', 2);
    loginFields = [
      {
        ...toFieldPayload(usernameRow),
        status: selected.field_key === 'username' || isBoth ? 1 : 0,
      },
      {
        ...toFieldPayload(mobileRow),
        status: selected.field_key === 'mobile' || isBoth ? 1 : 0,
      },
    ];
  }

  // 註冊：層級須從 1 起連號,跳號會讓中間那一關的註冊表單沒有任何欄位;停用欄位不計
  if (pageType === 1) {
    const groups = rows
      .filter((r) => Number(r.status) === 1)
      .map((r) => Number(r.field_group));
    const distinct = [...new Set(groups)].toSorted((a, b) => a - b);
    if (distinct.some((g, i) => g !== i + 1)) {
      ElMessage.error(tl('field_group_sequential'));
      return;
    }
  }

  // 註冊：有字數設定(min/max_length)的「啟用」欄位,上下限皆須填寫、最短須≥1、
  //       最短不可>最長,且玩家帳號／設定密碼不可超過 FIELD_LENGTH_MAX（members.username 為 varchar(32)）
  if (pageType === 1) {
    for (const r of rows) {
      const cfg = r.extra_config as null | Record<string, any>;
      if (
        Number(r.status) !== 1 ||
        !cfg ||
        !('min_length' in cfg) ||
        !('max_length' in cfg)
      ) {
        continue;
      }
      const min = cfg.min_length;
      const max = cfg.max_length;
      const hasMin = min !== null && min !== '';
      const hasMax = max !== null && max !== '';
      if (!hasMin || !hasMax) {
        ElMessage.error(
          `${registerFieldLabel(r.field_key)}：${tl('field_length_required')}`,
        );
        return;
      }
      if (Number(min) < 1) {
        ElMessage.error(
          `${registerFieldLabel(r.field_key)}：${tl('field_length_min_zero')}`,
        );
        return;
      }
      if (Number(min) > Number(max)) {
        ElMessage.error(
          `${registerFieldLabel(r.field_key)}：${tl('field_length_min_gt_max')}`,
        );
        return;
      }
      const limit = fieldLengthMax(r.field_key);
      if (limit !== undefined && Number(max) > limit) {
        ElMessage.error(
          `${registerFieldLabel(r.field_key)}：${tl('field_length_max_over')} ${limit}`,
        );
        return;
      }
    }

    // 玩家帳號關閉:帳號前綴必填且合格(英數字 2～16 字、至少一個英文字母),存檔前轉小寫;
    //               手機號碼+驗證強制開啟、必填(會員改用手機號碼註冊)
    if (usernameOffIn(rows)) {
      const usernameRow = rows.find((r) => r.field_key === 'username');
      const cfg = (usernameRow?.extra_config ?? {}) as Record<string, any>;
      const prefix = String(cfg[USERNAME_PREFIX_KEY] ?? '').trim();
      if (prefix === '') {
        ElMessage.error(tl('username_prefix_required'));
        return;
      }
      if (!USERNAME_PREFIX_PATTERN.test(prefix)) {
        ElMessage.error(tl('username_prefix_invalid'));
        return;
      }
      cfg[USERNAME_PREFIX_KEY] = prefix.toLowerCase();
      const mobileRow = rows.find((r) => r.field_key === 'mobile');
      if (mobileRow) {
        mobileRow.status = 1;
        mobileRow.is_required = 1;
      }
    }

    // 手機號碼+驗證 / 電子郵件+驗證:狀態開啟即含驗證,必填強制為 1(不能要驗證卻不用填);兜底正規化
    for (const r of rows) {
      if (
        (r.field_key === 'mobile' || r.field_key === 'email') &&
        Number(r.status) === 1
      ) {
        r.is_required = 1;
      }
      // 確認密碼:必填恆等於狀態,兩個方向都兜底
      if (r.field_key === 'confirm_password') {
        r.is_required = Number(r.status) === 1 ? 1 : 0;
      }
    }
  }

  saving[tab] = true;
  try {
    // 註冊:玩家帳號從開改成關,組別已有會員時先確認(既有會員之後只能用手機號碼登入)
    const turningUsernameOff =
      pageType === 1 && !usernameOffSaved.value && usernameOffIn(rows);
    if (turningUsernameOff) {
      const check = await getMemberFieldSettingHasMembersApi(groupId);
      if (Number(check.code) !== SUCCESS_CODE) {
        ElMessage.error(check.message || tl('failure'));
        return;
      }
      if (check.data?.has_members) {
        const ok = await ElMessageBox.confirm(
          tl('username_off_confirm'),
          tl('tips'),
          { type: 'warning' },
        ).catch(() => false);
        if (!ok) return;
      }
    }

    const res = await updateMemberFieldsApi({
      fields: loginFields ?? rows.map((r) => toFieldPayload(r)),
      group_id: groupId,
      page_type: pageType,
    });
    if (Number(res.code) === SUCCESS_CODE) {
      ElMessage.success(tl('success'));
      editing[tab] = false;
      if (pageType === 1) {
        usernameOffSaved.value = usernameOffIn(rows);
        // 後端已把登入設定改成只用手機號碼:畫面同步,正在編輯的登入頁一併結束編輯
        if (usernameOffSaved.value) {
          applyLoginMobileOnly(loginRows.value);
          editing.login = false;
        }
      }
    } else {
      ElMessage.error(res.message || tl('failure'));
    }
  } finally {
    saving[tab] = false;
  }
}

async function saveLimits() {
  // 驗證:限制次數最低為 1、自訂逐值不可重複、且每欄位不超過上限組數
  for (const r of limitRows.value) {
    // 整個平台限制:啟用時上限次數須 >=1
    if (r.platform_enabled && Number(r.platform_max_limit) < 1) {
      ElMessage.error(
        `${fieldLabel(r.field_key)}：${tl('limit_count_min_one')}`,
      );
      return;
    }
    if (!r.custom_enabled) continue;
    const vals = r.custom_values
      .map((v) => String(v.value))
      .filter((v) => v !== '');
    if (vals.length > MAX_LIMIT_VALUES) {
      ElMessage.error(`${fieldLabel(r.field_key)}：${tl('limit_value_max')}`);
      return;
    }
    if (new Set(vals).size !== vals.length) {
      ElMessage.error(
        `${fieldLabel(r.field_key)}：${tl('limit_value_duplicate')}`,
      );
      return;
    }
    // 風俗逐值:有填值的上限次數須 >=1
    if (
      r.custom_values.some(
        (v) => String(v.value) !== '' && Number(v.max_limit) < 1,
      )
    ) {
      ElMessage.error(
        `${fieldLabel(r.field_key)}：${tl('limit_count_min_one')}`,
      );
      return;
    }
  }

  saving.limits = true;
  try {
    const res = await updateMemberLimitsApi({
      group_id: groupId,
      limits: limitRows.value.map((r) => ({
        custom_enabled: r.custom_enabled,
        // 只送有填值的逐值列；未啟用自訂則清空
        custom_values: r.custom_enabled
          ? r.custom_values
              .filter((v) => String(v.value) !== '')
              .map((v) => ({
                max_limit: Number(v.max_limit) || 0,
                value: v.value,
              }))
          : [],
        field_key: r.field_key,
        platform_enabled: r.platform_enabled,
        platform_max_limit: r.platform_max_limit,
      })),
    });
    if (Number(res.code) === SUCCESS_CODE) {
      ElMessage.success(tl('success'));
      editing.limits = false;
    } else {
      ElMessage.error(res.message || tl('failure'));
    }
  } finally {
    saving.limits = false;
  }
}

/* ---- 註冊限制：自訂逐值（inline 編輯，直接操作該行 custom_values） ---- */
function addLimitValue(row: MemberRegisterLimitRow) {
  if (row.custom_values.length >= MAX_LIMIT_VALUES) {
    ElMessage.warning(tl('limit_value_max'));
    return;
  }
  row.custom_values.push({ max_limit: 1, value: '' });
}

function removeLimitValue(row: MemberRegisterLimitRow, index: number) {
  row.custom_values.splice(index, 1);
}

const groupTitle = computed(() =>
  groupName.value ? `${groupName.value}（${groupId}）` : String(groupId),
);

onMounted(loadData);
</script>

<template>
  <Page
    :content-class="embedGroupId ? 'px-0' : ''"
    :title="`${tl('agnet_management')} > ${tl('menu_member_field_setting')}`"
  >
    <template #description>
      <b>{{ tl('name') }}</b
      >：{{ groupTitle }}
    </template>

    <div v-loading="loading">
      <ElTabs v-model="activeTab">
        <!-- 註冊欄位設定 -->
        <ElTabPane :label="tl('register_fields')" name="register">
          <div class="mb-3 flex justify-end gap-2">
            <ElButton
              v-if="!editing.register"
              size="small"
              type="primary"
              @click="startEdit('register')"
            >
              {{ tl('edit') }}
            </ElButton>
            <template v-else>
              <ElButton
                :loading="saving.register"
                size="small"
                type="success"
                @click="saveFields(1)"
              >
                {{ tl('btn_update') }}
              </ElButton>
              <ElButton size="small" @click="cancelEdit('register')">
                {{ tl('cancel') }}
              </ElButton>
            </template>
          </div>
          <MemberFieldTable :editable="editing.register" :rows="registerRows" />
        </ElTabPane>

        <!-- 登入欄位設定 -->
        <ElTabPane :label="tl('login_fields')" name="login">
          <div class="mb-3 flex justify-end gap-2">
            <ElButton
              v-if="!editing.login"
              size="small"
              type="primary"
              @click="startEdit('login')"
            >
              {{ tl('edit') }}
            </ElButton>
            <template v-else>
              <ElButton
                :loading="saving.login"
                size="small"
                type="success"
                @click="saveFields(2)"
              >
                {{ tl('btn_update') }}
              </ElButton>
              <ElButton size="small" @click="cancelEdit('login')">
                {{ tl('cancel') }}
              </ElButton>
            </template>
          </div>
          <MemberFieldTable
            :editable="editing.login"
            :login-locked="usernameOffSaved"
            :rows="loginRows"
            login
          />
          <div v-if="usernameOffSaved" class="text-warning mt-2 text-sm">
            {{ tl('login_mobile_only_hint') }}
          </div>
        </ElTabPane>

        <!-- 實名驗證欄位設定 -->
        <ElTabPane :label="tl('verification_fields')" name="verification">
          <div class="mb-3 flex justify-end gap-2">
            <ElButton
              v-if="!editing.verification"
              size="small"
              type="primary"
              @click="startEdit('verification')"
            >
              {{ tl('edit') }}
            </ElButton>
            <template v-else>
              <ElButton
                :loading="saving.verification"
                size="small"
                type="success"
                @click="saveFields(3)"
              >
                {{ tl('btn_update') }}
              </ElButton>
              <ElButton size="small" @click="cancelEdit('verification')">
                {{ tl('cancel') }}
              </ElButton>
            </template>
          </div>
          <MemberFieldTable
            :editable="editing.verification"
            :rows="verificationRows"
            verification
          />
        </ElTabPane>

        <!-- 註冊限制 -->
        <ElTabPane :label="tl('register_limits')" name="limits">
          <div class="mb-3 flex justify-end gap-2">
            <ElButton
              v-if="!editing.limits"
              size="small"
              type="primary"
              @click="startEdit('limits')"
            >
              {{ tl('edit') }}
            </ElButton>
            <template v-else>
              <ElButton
                :loading="saving.limits"
                size="small"
                type="success"
                @click="saveLimits"
              >
                {{ tl('btn_update') }}
              </ElButton>
              <ElButton size="small" @click="cancelEdit('limits')">
                {{ tl('cancel') }}
              </ElButton>
            </template>
          </div>
          <ElTable
            :data="limitRows"
            border
            class="mfs-table"
            row-key="field_key"
          >
            <ElTableColumn type="expand" width="40">
              <template #default="{ row }">
                <div class="px-12 py-3">
                  <div class="mb-1 text-sm font-medium">
                    {{ tl('platform_limit') }}
                  </div>
                  <ElInputNumber
                    v-model="row.platform_max_limit"
                    :disabled="
                      !editing.limits ||
                      !row.platform_enabled ||
                      limitLocked(row as MemberRegisterLimitRow)
                    "
                    :min="1"
                    class="mb-4 !w-64"
                  />

                  <div class="mb-2 text-sm font-medium">
                    {{ tl('custom_limit') }}
                  </div>
                  <template v-if="row.custom_enabled">
                    <div class="text-muted-foreground mb-1 flex gap-3 text-xs">
                      <span class="w-64">{{ fieldLabel(row.field_key) }}</span>
                      <span class="w-40">{{ tl('max_limit') }}</span>
                    </div>
                    <div
                      v-for="(v, idx) in row.custom_values"
                      :key="idx"
                      class="mb-2 flex items-center gap-3"
                    >
                      <ElInput
                        v-model="v.value"
                        :disabled="
                          !editing.limits ||
                          limitLocked(row as MemberRegisterLimitRow)
                        "
                        :placeholder="tl('please_enter')"
                        class="!w-64"
                      />
                      <ElInputNumber
                        v-model="v.max_limit"
                        :disabled="
                          !editing.limits ||
                          limitLocked(row as MemberRegisterLimitRow)
                        "
                        :min="1"
                        class="!w-40"
                      />
                      <ElButton
                        v-if="
                          editing.limits &&
                          !limitLocked(row as MemberRegisterLimitRow)
                        "
                        circle
                        size="small"
                        type="danger"
                        @click="
                          removeLimitValue(
                            row as MemberRegisterLimitRow,
                            Number(idx),
                          )
                        "
                      >
                        ×
                      </ElButton>
                    </div>
                    <ElButton
                      v-if="
                        editing.limits &&
                        !limitLocked(row as MemberRegisterLimitRow)
                      "
                      :disabled="row.custom_values.length >= MAX_LIMIT_VALUES"
                      size="small"
                      type="success"
                      @click="addLimitValue(row as MemberRegisterLimitRow)"
                    >
                      {{ tl('add_value') }}
                    </ElButton>
                  </template>
                  <div v-else class="text-muted-foreground text-sm">
                    {{ tl('custom_disabled_hint') }}
                  </div>
                </div>
              </template>
            </ElTableColumn>
            <ElTableColumn :label="tl('name')" min-width="150">
              <template #default="{ row }">
                {{ fieldLabel(row.field_key) }}
                <span
                  v-if="limitLocked(row as MemberRegisterLimitRow)"
                  class="text-warning ml-2 text-xs"
                >
                  {{ tl('one_mobile_one_account') }}
                </span>
              </template>
            </ElTableColumn>
            <ElTableColumn
              :label="tl('platform_limit')"
              align="center"
              width="200"
            >
              <template #default="{ row }">
                <ElSwitch
                  v-model="row.platform_enabled"
                  :active-value="1"
                  :disabled="
                    !editing.limits ||
                    limitLocked(row as MemberRegisterLimitRow)
                  "
                  :inactive-value="0"
                />
              </template>
            </ElTableColumn>
            <ElTableColumn
              :label="tl('custom_limit')"
              align="center"
              width="140"
            >
              <template #default="{ row }">
                <ElSwitch
                  v-model="row.custom_enabled"
                  :active-value="1"
                  :disabled="
                    !editing.limits ||
                    limitLocked(row as MemberRegisterLimitRow)
                  "
                  :inactive-value="0"
                />
              </template>
            </ElTableColumn>
          </ElTable>
        </ElTabPane>
      </ElTabs>
    </div>
  </Page>
</template>

<style scoped>
.mfs-table :deep(.el-table__expand-icon) {
  color: var(--el-color-primary);
}
</style>
