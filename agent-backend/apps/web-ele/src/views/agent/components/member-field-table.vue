<script lang="ts" setup>
import type { MemberFieldSettingRow } from '#/api';

import {
  ElCheckbox,
  ElInput,
  ElInputNumber,
  ElOption,
  ElRadioButton,
  ElRadioGroup,
  ElSelect,
  ElSwitch,
  ElTable,
  ElTableColumn,
  ElTooltip,
} from 'element-plus';

import { tl } from '#/locales';

import {
  hasFieldConfig,
  PASSWORD_RULE_OPTIONS,
  USERNAME_PREFIX_KEY,
  visibleConfigControls,
} from '../member-field-schema';

defineOptions({ name: 'MemberFieldTable' });

const props = defineProps<{
  editable: boolean;
  /** 登入模式：只顯示 名稱 / 順序 / 狀態，不含展開設定、欄位組、必填 */
  login?: boolean;
  /** 登入模式：註冊已關閉玩家帳號，登入方式固定為手機號碼，三個選項全部鎖住 */
  loginLocked?: boolean;
  rows: MemberFieldSettingRow[];
  verification?: boolean;
}>();

/**
 * 欄位顯示名（page 情境專屬 label key，不動全域共用 key）：
 * - username → 玩家帳號、last_name → 真實姓名
 * - password：註冊情境「設定密碼」、登入情境「密碼」
 * - mobile/email：註冊情境顯示「+驗證」（驗證碼已併入，開啟即含驗證）；登入情境維持原名
 */
const FIELD_LABEL_KEY: Record<string, string> = {
  bank: 'bank_card',
  last_name: 'real_name',
  username: 'player_account',
};
const REGISTER_LABEL_KEY: Record<string, string> = {
  email: 'email_with_verify',
  mobile: 'mobile_with_verify',
};
function fieldLabel(key: string) {
  if (key === 'password')
    return props.login ? tl('password') : tl('set_password', key);
  if (!props.login && REGISTER_LABEL_KEY[key])
    return tl(REGISTER_LABEL_KEY[key], key);
  return tl(FIELD_LABEL_KEY[key] ?? key, key);
}

/**
 * 鎖定欄位：必填/狀態強制不可調整（UI 強鎖，值仍由 DB 決定）。
 * 註冊：設定密碼(硬鎖)——註冊必然要收密碼。
 * 註冊的玩家帳號可以關閉：關閉時帳號由系統以「前綴-uniqid」自動產生，會員改用手機號碼註冊，
 * 所以手機號碼+驗證強制開啟、必填並鎖住；玩家帳號的必填維持鎖住（開啟就一定要填）。
 * 登入：玩家帳號 / 手機號碼 / 帳號+手機號 三選一（互斥）；密碼恆為必要、不顯示於登入清單。
 * 註冊已關閉玩家帳號時，登入三個選項全部鎖住（loginLocked）。
 * 實名驗證：真實姓名維持鎖定（原規範不動）。
 *
 * 註冊的真實姓名解除鎖定（target/tickets#4120 一併處理）：原本硬鎖為開啟＋必填，
 * 但與實況不符——六套皮的註冊表單都沒有這個欄位（C15 SignUpForm 該區塊整段註解）、
 * 舊註冊接口 last_name 為 nullable、v2 寫入為 first_name ?? null 且 members 兩個
 * 姓名欄位皆 nullable，未填不會報錯。註冊未收者於實名驗證頁補填即可。
 */
const REGISTER_LOCKED_KEYS = new Set(['password']);
/** 註冊：狀態可調、必填鎖住的欄位 */
const REGISTER_REQUIRED_LOCKED_KEYS = new Set(['username']);

/** 註冊：目前編輯中的玩家帳號是否為關閉（缺列視為開啟，與 apis 判斷一致） */
function isUsernameOff(): boolean {
  const row = props.rows.find((r) => r.field_key === 'username');
  return row !== undefined && Number(row.status) === 0;
}

function isLocked(key: string) {
  if (props.verification) return key === 'last_name';
  if (props.login) return Boolean(props.loginLocked);
  if (key === 'mobile' && isUsernameOff()) return true;
  return REGISTER_LOCKED_KEYS.has(key);
}

/** 登入 3 選 1 顯示列：玩家帳號 / 手機號碼 / 帳號+手機號（互斥，只能選一個） */
const LOGIN_IDENTIFIER_KEYS = new Set([
  'mobile',
  'username',
  'username_mobile',
]);
/**
 * 註冊：含驗證的欄位（手機號碼+驗證 / 電子郵件+驗證）。
 * 驗證碼已併入這些欄位，狀態開啟 = 一定要填 + 驗證，故強制必填、必填鎖定不可關。
 */
const VERIFY_FIELDS = new Set(['email', 'mobile']);
/**
 * 註冊：必填恆等於狀態、必填開關全程鎖住的欄位。
 * 確認密碼只要顯示，前台就會比對設定密碼，關掉必填也擋不下來。
 */
const REQUIRED_FOLLOWS_STATUS = new Set(['confirm_password']);

function onStatusChange(row: MemberFieldSettingRow) {
  if (props.login) {
    // 登入 3 選 1(互斥):開啟此列 → 其餘登入列關閉;關閉此列時若無其他開啟則擋回,恆保持恰好一個
    if (!LOGIN_IDENTIFIER_KEYS.has(row.field_key)) return;
    if (Number(row.status) === 1) {
      for (const r of props.rows) {
        if (r !== row && LOGIN_IDENTIFIER_KEYS.has(r.field_key)) r.status = 0;
      }
    } else {
      const anyOn = props.rows.some(
        (r) =>
          r !== row &&
          LOGIN_IDENTIFIER_KEYS.has(r.field_key) &&
          Number(r.status) === 1,
      );
      if (!anyOn) row.status = 1;
    }
    return;
  }
  // 註冊:玩家帳號關閉 → 手機號碼+驗證強制開啟、必填(會員改用手機號碼註冊);重新打開時不動手機號碼
  if (row.field_key === 'username' && Number(row.status) === 0) {
    const mobileRow = props.rows.find((r) => r.field_key === 'mobile');
    if (mobileRow) {
      mobileRow.status = 1;
      mobileRow.is_required = 1;
    }
  }
  // 註冊:手機號碼+驗證 / 電子郵件+驗證 狀態開啟 → 強制必填(含驗證,不能要驗證卻不用填);
  //       關閉時必填隨之解除(欄位關閉必填無意義)。確認密碼同樣必填跟著狀態走
  if (
    VERIFY_FIELDS.has(row.field_key) ||
    REQUIRED_FOLLOWS_STATUS.has(row.field_key)
  ) {
    row.is_required = Number(row.status) === 1 ? 1 : 0;
  }
}
/** 狀態開關禁用:非編輯 / 鎖定欄位 */
function statusDisabled(row: MemberFieldSettingRow): boolean {
  return !props.editable || isLocked(row.field_key);
}
/**
 * 必填開關禁用:非編輯 / 鎖定欄位 /
 * (註冊)手機號碼+驗證、電子郵件+驗證 狀態開啟時強制必填、鎖住不可關 /
 * (註冊)確認密碼必填恆等於狀態,兩種狀態都鎖住
 */
function requiredDisabled(row: MemberFieldSettingRow): boolean {
  if (!props.editable || isLocked(row.field_key)) return true;
  if (props.login) return false;
  if (REGISTER_REQUIRED_LOCKED_KEYS.has(row.field_key)) return true;
  if (REQUIRED_FOLLOWS_STATUS.has(row.field_key)) return true;
  return VERIFY_FIELDS.has(row.field_key) && Number(row.status) === 1;
}

function schemaOf(row: MemberFieldSettingRow) {
  return visibleConfigControls(row.field_key, row.status);
}

/** 取（並確保）該行的 extra_config 物件，供 v-model 綁定；已於載入時正規化，此處僅保底 */
function cfg(row: MemberFieldSettingRow): Record<string, any> {
  row.extra_config ??= {};
  return row.extra_config;
}

function rulesOf(row: MemberFieldSettingRow): Record<string, string> {
  const c = cfg(row);
  c.rules ??= {};
  return c.rules;
}

/** 無進階設定的欄位（或登入模式）隱藏展開箭頭 */
function rowClassName({ row }: { row: MemberFieldSettingRow }) {
  return !props.login && hasFieldConfig(row.field_key) ? '' : 'mfs-no-expand';
}

/** 設定密碼與確認密碼拆到不同關卡沒有意義，兩者層級恆一致，改一邊另一邊跟著走 */
const FIELD_GROUP_PAIRS: Record<string, string> = {
  confirm_password: [REDACTED]',
  password: [REDACTED]',
};

function syncPairedFieldGroup(
  row: MemberFieldSettingRow,
  value: number | undefined,
) {
  const pairedKey = FIELD_GROUP_PAIRS[row.field_key];
  if (!pairedKey || value === undefined) return;
  const paired = props.rows.find((r) => r.field_key === pairedKey);
  if (paired && paired.field_group !== value) paired.field_group = value;
}
</script>

<template>
  <ElTable
    :data="rows"
    :row-class-name="rowClassName"
    border
    class="mfs-table"
    row-key="field_key"
  >
    <ElTableColumn v-if="!login && !verification" type="expand" width="40">
      <template #default="{ row }">
        <div
          v-if="schemaOf(row as MemberFieldSettingRow).length > 0"
          class="flex flex-wrap items-center gap-x-8 gap-y-3 px-12 py-3"
        >
          <div
            v-for="ctrl in schemaOf(row as MemberFieldSettingRow)"
            :key="ctrl.key"
            class="flex items-center gap-2"
          >
            <span class="flex items-center gap-1 text-sm font-medium">
              {{ tl(ctrl.labelKey) }}
              <ElTooltip
                v-if="ctrl.tipKey"
                :content="tl(ctrl.tipKey)"
                placement="top"
              >
                <span class="text-muted-foreground cursor-help">ⓘ</span>
              </ElTooltip>
            </span>

            <!-- 數字（最短/最長字數,最低 1） -->
            <ElInputNumber
              v-if="ctrl.type === 'number'"
              v-model="cfg(row as MemberFieldSettingRow)[ctrl.key]"
              :disabled="!editable"
              :max="ctrl.max"
              :min="1"
              class="!w-32"
              size="small"
            />

            <!-- 文字（玩家帳號關閉時的帳號前綴，下方附說明與範例） -->
            <div v-else-if="ctrl.type === 'text'" class="flex flex-col gap-1">
              <ElInput
                v-model="cfg(row as MemberFieldSettingRow)[ctrl.key]"
                :disabled="!editable"
                :maxlength="ctrl.max"
                :placeholder="tl('username_prefix_required')"
                class="!w-48"
                size="small"
              />
              <template v-if="ctrl.key === USERNAME_PREFIX_KEY">
                <span class="text-muted-foreground text-xs">
                  {{ tl('username_prefix_hint') }}
                </span>
                <span class="text-muted-foreground text-xs">
                  {{ tl('username_prefix_example') }}
                </span>
              </template>
            </div>

            <!-- 布林（通用勾選；目前無欄位使用，保留 schema 型別支援） -->
            <ElCheckbox
              v-else-if="ctrl.type === 'boolean'"
              v-model="cfg(row as MemberFieldSettingRow)[ctrl.key]"
              :disabled="!editable"
            />

            <!-- 下拉（select 型設定，通用） -->
            <ElSelect
              v-else-if="ctrl.type === 'select'"
              v-model="cfg(row as MemberFieldSettingRow)[ctrl.key]"
              :disabled="!editable"
              class="!w-40"
              size="small"
            >
              <ElOption
                v-for="opt in ctrl.options"
                :key="opt"
                :label="tl(`${ctrl.labelKey}_${opt}`, opt)"
                :value="opt"
              />
            </ElSelect>

            <!-- 規則（密碼規則：每個類型各自三選一） -->
            <div
              v-else-if="ctrl.type === 'rules'"
              class="flex flex-wrap items-center gap-x-6 gap-y-2"
            >
              <div
                v-for="rk in ctrl.ruleKeys"
                :key="rk"
                class="flex items-center gap-2"
              >
                <span class="text-sm">{{ tl(`cfg_rule_${rk}`, rk) }}</span>
                <ElRadioGroup
                  v-model="rulesOf(row as MemberFieldSettingRow)[rk]"
                  :disabled="!editable"
                  size="small"
                >
                  <ElRadioButton
                    v-for="opt in PASSWORD_RULE_OPTIONS"
                    :key="opt"
                    :value="opt"
                  >
                    {{ tl(`cfg_rule_${opt}`, opt) }}
                  </ElRadioButton>
                </ElRadioGroup>
              </div>
            </div>
          </div>
        </div>
      </template>
    </ElTableColumn>

    <ElTableColumn :label="tl('name')" min-width="150">
      <template #default="{ row }">{{ fieldLabel(row.field_key) }}</template>
    </ElTableColumn>
    <ElTableColumn
      v-if="!login && !verification"
      :label="tl('field_group')"
      width="180"
    >
      <template #default="{ row }">
        <ElInputNumber
          v-model="row.field_group"
          :disabled="!editable"
          :min="1"
          class="!w-36"
          size="small"
          @change="
            (val) => syncPairedFieldGroup(row as MemberFieldSettingRow, val)
          "
        />
      </template>
    </ElTableColumn>
    <ElTableColumn v-if="!verification" :label="tl('field_sort')" width="180">
      <template #default="{ row }">
        <ElInputNumber
          v-model="row.sort"
          :disabled="!editable"
          :min="0"
          class="!w-36"
          size="small"
        />
      </template>
    </ElTableColumn>
    <ElTableColumn
      v-if="!login"
      :label="tl('required')"
      align="center"
      width="100"
    >
      <template #default="{ row }">
        <ElSwitch
          v-model="row.is_required"
          :active-value="1"
          :disabled="requiredDisabled(row as MemberFieldSettingRow)"
          :inactive-value="0"
        />
      </template>
    </ElTableColumn>
    <ElTableColumn :label="tl('status')" align="center" width="100">
      <template #default="{ row }">
        <ElSwitch
          v-model="row.status"
          :active-value="1"
          :disabled="statusDisabled(row as MemberFieldSettingRow)"
          :inactive-value="0"
          @change="onStatusChange(row as MemberFieldSettingRow)"
        />
      </template>
    </ElTableColumn>
  </ElTable>
</template>

<style scoped>
.mfs-table :deep(.mfs-no-expand .el-table__expand-icon) {
  visibility: hidden;
  pointer-events: none;
}
</style>
