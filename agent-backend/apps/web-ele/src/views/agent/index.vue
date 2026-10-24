<script lang="ts" setup>
import type { AgentListParams, AgentRow } from '#/api';

import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import { useAccess } from '@vben/access';
import { Page } from '@vben/common-ui';
import { useUserStore } from '@vben/stores';

import {
  ElButton,
  ElCard,
  ElCheckbox,
  ElDropdown,
  ElDropdownItem,
  ElDropdownMenu,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElMessageBox,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
  ElTag,
  ElTooltip,
} from 'element-plus';

import {
  editAgentStatusApi,
  getAgentGroupDropdownApi,
  getAgentListApi,
  getCustomizedSettingApi,
  getPersonalSettingApi,
  resetAgent2faApi,
  setAgentRoleToCsApi,
  updateAgentSysLockApi,
} from '#/api';
import { getProfitShareDomainApi } from '#/api/profit-share';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import { amountThousands } from '#/utils/format';

import AgentPasswordDialog from './components/agent-password-dialog.vue';
import AgentProfileDialog from './components/agent-profile-dialog.vue';
import AgentBankAccountDialog from './components/bank-account-dialog.vue';
import AgentDomainDialog from './components/domain-dialog.vue';
import AgentStatusLogDialog from './components/status-log-dialog.vue';
import AgentTransactionDialog from './components/transaction-dialog.vue';
import { reset2faBlockReason, useSelfHas2fa } from './components/two-fa';
import TwoFaOtpDialog from './components/two-fa-otp-dialog.vue';
import AgentWalletTransactionDialog from './components/wallet-transaction-dialog.vue';

defineOptions({ name: 'AgentIndex' });

const route = useRoute();
const router = useRouter();
const userStore = useUserStore();
const { hasAccessByCodes } = useAccess();

const PER_PAGE = 50;
const selfId = Number((userStore.userInfo as any)?.id ?? 0);

/** 權限旗標 */
const canGroup = hasAccessByCodes(['agent_group']);
const canDisplayFunction = hasAccessByCodes(['display_function_column']);
const canDeposit = hasAccessByCodes(['deposit_to_agent']);
const canWithdrawal = hasAccessByCodes(['withdrawal_to_agent']);
const canAdvanceSetting = hasAccessByCodes(['agent_advance_setting']);
const canUpdateGroupPerm = hasAccessByCodes(['update_agent_group_permissions']);
const canExport = hasAccessByCodes(['agent_export_permission']);
const canSysLock = hasAccessByCodes(['agent_sys_lock']);
const canDeductPoint = hasAccessByCodes(['member_deduct_point']);
const canReset2fa = hasAccessByCodes(['agent_reset_2fa']);
const canModifyPassword = [REDACTED]'agent_modify_password']);
const canModifyPaymentPassword = [REDACTED]
  'agent_modify_payment_password',
]);

/** 重置2FA（target/tickets#4503）：反灰條件見 reset2faBlockReason */
const otpDialogRef = ref<InstanceType<typeof TwoFaOtpDialog>>();
const { loadSelfHas2fa, selfHas2fa } = useSelfHas2fa(selfId);

function reset2faReason(row: AgentRow) {
  return reset2faBlockReason(row, selfId, selfHas2fa.value);
}

/** 修改密碼／支付密碼（target/tickets#4506）：自己那一列 → 操作者未開 2FA，後端會再檢查一次 */
const passwordDialogRef = ref<InstanceType<typeof AgentPasswordDialog>>();

function modifyPasswordReason(row: AgentRow) {
  if (Number(row.id) === selfId) return 'agent_pwd_modify_self_hint';
  if (!selfHas2fa.value) return 'tfa_operator_not_enabled';
  return '';
}

function openModifyPassword(
  row: AgentRow,
  type: 'password' | 'payment_password',
) {
  if (modifyPasswordReason(row)) return;
  passwordDialogRef.value?.open(type, { id: row.id, username: row.username });
}

async function openReset2fa(row: AgentRow) {
  if (reset2faReason(row)) return;
  const ok = await otpDialogRef.value?.open({
    confirmText: tl('tfa_confirm_reset'),
    danger: true,
    description: tl('tfa_reset_desc'),
    label: tl('tfa_input_own_code'),
    submit: (otp) => resetAgent2faApi({ agent_id: row.id, secret: [REDACTED] }),
    title: tl('tfa_reset_title').replace('{name}', row.username),
  });
  if (ok) loadData();
}

/** 代理設定旗標（佔成錢包 4057 / 多錢包 6008） */
const profitShareWalletVisible = ref(false);
const multipleWalletSwitch = ref(false);
/** 個人設置：佔成錢包存入 95111 / 取出 95112 */
const profitShareWalletDeposit = ref(false);
const profitShareWalletWithdrawal = ref(false);

const filters = reactive({
  account_no: '',
  account_type: 'all',
  cash_flow_group_id: '',
  chat_group_id: '',
  code: '',
  customized_setting_group_id: '',
  email: '',
  first_login_ip: '',
  fuzzy_query: false,
  fuzzy_query_real_name: false,
  mobile: '',
  real_name: '',
  status: '',
  sys_lock: '',
  username: '',
});

const loading = ref(false);
const list = ref<AgentRow[]>([]);
const total = ref(0);
const page = ref(1);

/** 上線過濾（查看下線時帶上 id） */
const parentId = ref<number | undefined>(
  route.query.id ? Number(route.query.id) : undefined,
);

/** 分組下拉（依 agent_group_type 分類） */
const groups = ref<
  Array<{ agent_group_type: number; id: number; name: string }>
>([]);
const customizedGroups = computed(() =>
  groups.value.filter((g) => g.agent_group_type === 1),
);
const cashFlowGroups = computed(() =>
  groups.value.filter((g) => g.agent_group_type === 2),
);
const chatGroups = computed(() =>
  groups.value.filter((g) => g.agent_group_type === 4),
);

const settingsMap = ref<Map<number, any>>(new Map());

const statusLogRef = ref<InstanceType<typeof AgentStatusLogDialog>>();
const domainRef = ref<InstanceType<typeof AgentDomainDialog>>();
const transactionRef = ref<InstanceType<typeof AgentTransactionDialog>>();
const walletTransactionRef =
  ref<InstanceType<typeof AgentWalletTransactionDialog>>();
const bankAccountRef = ref<InstanceType<typeof AgentBankAccountDialog>>();
const profileRef = ref<InstanceType<typeof AgentProfileDialog>>();

function long2ip(n?: number) {
  if (!n || n <= 0) return '-';

  return [(n >>> 24) & 255, (n >>> 16) & 255, (n >>> 8) & 255, n & 255].join(
    '.',
  );
}

const fmtAmount = (v?: number | string) =>
  Number.isFinite(Number(v)) ? amountThousands(v, 4) : (v ?? '-');

function buildParams(): AgentListParams {
  const p: AgentListParams = { page: page.value, per_page: PER_PAGE };
  if (parentId.value) p.id = parentId.value;
  if (filters.username) p.username = filters.username;
  if (filters.fuzzy_query) p.fuzzy_query = 1;
  if (filters.real_name) p.real_name = filters.real_name;
  if (filters.fuzzy_query_real_name) p.fuzzy_query_real_name = 1;
  if (filters.account_type && filters.account_type !== 'all')
    p.account_type = filters.account_type;
  if (filters.mobile) p.mobile = filters.mobile;
  if (filters.email) p.email = filters.email;
  if (filters.account_no) p.account_no = filters.account_no;
  if (filters.status !== '') p.status = filters.status;
  if (filters.sys_lock !== '') p.sys_lock = filters.sys_lock;
  if (filters.code) p.code = filters.code;
  if (filters.first_login_ip) p.first_login_ip = filters.first_login_ip;
  if (filters.customized_setting_group_id !== '')
    p.customized_setting_group_id = filters.customized_setting_group_id;
  if (filters.cash_flow_group_id !== '')
    p.cash_flow_group_id = filters.cash_flow_group_id;
  if (filters.chat_group_id !== '') p.chat_group_id = filters.chat_group_id;
  return p;
}

async function loadData() {
  loading.value = true;
  try {
    const res = await getAgentListApi(buildParams());
    list.value = res.list;
    total.value = res.pagination.total;
  } finally {
    loading.value = false;
  }
}

function handleSearch() {
  page.value = 1;
  loadData();
}

function resetFilters() {
  Object.assign(filters, {
    account_no: '',
    account_type: 'all',
    cash_flow_group_id: '',
    chat_group_id: '',
    code: '',
    customized_setting_group_id: '',
    email: '',
    first_login_ip: '',
    fuzzy_query: false,
    fuzzy_query_real_name: false,
    mobile: '',
    real_name: '',
    status: '',
    sys_lock: '',
    username: '',
  });
}

function handleReset() {
  resetFilters();
  parentId.value = undefined;
  handleSearch();
}

function handlePageChange(p: number) {
  page.value = p;
  loadData();
}

/** 狀態格：啟停與限制登入是兩個獨立維度，同時成立時兩個都列出 */
function statusTags(row: AgentRow) {
  const tags: Array<{
    text: string;
    type: 'danger' | 'success' | 'warning';
  }> = [
    row.status === 1
      ? { text: tl('enable'), type: 'success' }
      : { text: tl('disable'), type: 'danger' },
  ];
  if (Number(row.sys_lock) === 1) {
    tags.push({ text: tl('restrict_login'), type: 'warning' });
  }
  return tags;
}

function balanceText(row: AgentRow) {
  return row.is_balance_unlimited === 1
    ? tl('unlimited')
    : fmtAmount(row.balance);
}

/** 查看下線：比照舊版 ?id= 導頁，清空其他查詢條件只留下線過濾（否則殘留條件會把結果過濾成空） */
function checkLower(id: number) {
  resetFilters();
  parentId.value = id;
  handleSearch();
}

/**
 * 複製推廣註冊網址：與頂部分享碼 QR 同一來源（agentDomain/self），點擊時即時取當下分享網域
 * （target/tickets#4490）。網域含 '?' 時直接接分享碼，否則接 /Signup/。
 */
async function copyPromoteUrl(code: string) {
  const res = await getProfitShareDomainApi().catch(() => null);
  const domain =
    res && Number(res.code) === 1000 ? (res.data?.domain ?? '') : '';
  const url = domain
    ? `https://${domain}${domain.includes('?') ? '' : '/Signup/'}${code}`
    : code;
  try {
    await navigator.clipboard.writeText(url);
    ElMessage.success(tl('copy_successfully'));
  } catch {
    ElMessage.warning(url);
  }
}

/** 設為客服（總代子帳號專用） */
async function setRoleToCs(row: AgentRow) {
  try {
    await ElMessageBox.confirm(
      `${tl('set_role_to_cs')}：${row.username}`,
      tl('prompt'),
      { cancelButtonText: tl('cancel'), confirmButtonText: tl('confirm') },
    );
    const res: any = await setAgentRoleToCsApi(row.id);
    if (res.code === 1000) {
      ElMessage.success(res.message || tl('success'));
      loadData();
    } else {
      ElMessage.error(res.message || tl('failure'));
    }
  } catch {
    // 取消
  }
}

/** 啟用/停用代理（需備註） */
async function toggleStatus(row: AgentRow) {
  const next = row.status === 1 ? 0 : 1;
  try {
    const { value: remark } = await ElMessageBox.prompt(
      `${tl('enter_memo')}...`,
      next === 1 ? tl('enable') : tl('disable'),
      {
        cancelButtonText: tl('cancel'),
        confirmButtonText: tl('submit'),
        inputType: 'textarea',
        inputValidator: (v) => (v ? true : tl('enter_memo')),
      },
    );
    const res: any = await editAgentStatusApi({
      id: row.id,
      remark: remark ?? '',
      status: next,
    });
    if (res.code === 1000) {
      ElMessage.success(tl('success'));
      loadData();
    } else {
      ElMessage.error(res.message || tl('failure'));
    }
  } catch {
    // 取消
  }
}

/** 限制登入/解除限制登入（需備註）；限制中的帳號連正確密碼也不能登入 */
async function toggleSysLock(row: AgentRow) {
  const next = Number(row.sys_lock) === 1 ? 0 : 1;
  try {
    const { value: remark } = await ElMessageBox.prompt(
      `${tl('enter_memo')}...`,
      next === 1 ? tl('restrict_login') : tl('remove_restrict_login'),
      {
        cancelButtonText: tl('cancel'),
        confirmButtonText: tl('submit'),
        inputType: 'textarea',
        inputValidator: (v) => (v ? true : tl('enter_memo')),
      },
    );
    const res = await updateAgentSysLockApi({
      agent_id: row.id,
      remark: remark ?? '',
      sys_lock: next,
    });
    if (Number(res.code) === 200) {
      ElMessage.success(tl('success'));
      loadData();
    } else {
      ElMessage.error(res.message || tl('failure'));
    }
  } catch {
    // 取消
  }
}

onMounted(() => {
  loadData();
  if (canReset2fa || canModifyPassword || canModifyPaymentPassword) {
    loadSelfHas2fa();
  }
  if (canGroup) {
    getAgentGroupDropdownApi()
      .then((g) => {
        groups.value = g ?? [];
      })
      .catch(() => {});
  }
  getCustomizedSettingApi()
    .then((s) => {
      const map = new Map(s.map((i) => [Number(i.setting_key), i.value]));
      settingsMap.value = map;
      profitShareWalletVisible.value = Number(map.get(4057) ?? 0) === 1;
      multipleWalletSwitch.value = Number(map.get(6008) ?? 0) === 1;
    })
    .catch(() => {});
  if (selfId) {
    getPersonalSettingApi(selfId)
      .then((ps) => {
        const m = new Map(
          (ps ?? []).map((i) => [Number(i.setting_id), i.data_value]),
        );
        profitShareWalletDeposit.value = Number(m.get(95_111) ?? 0) === 1;
        profitShareWalletWithdrawal.value = Number(m.get(95_112) ?? 0) === 1;
      })
      .catch(() => {});
  }
});

const exporting = ref(false);
/** Excel 匯出：依目前搜尋條件抓全部後輸出（欄位對齊列表） */
async function handleExport() {
  exporting.value = true;
  try {
    const params = { ...buildParams(), page: 1, per_page: 100_000 };
    const res = await getAgentListApi(params);
    const rows = res.list ?? [];
    await exportExcel(
      `agent_${Date.now()}`,
      [
        tl('createdate'),
        tl('last_login'),
        tl('first_ip'),
        tl('last_ip'),
        tl('agent_previous'),
        tl('username'),
        tl('sharecode'),
        tl('roles'),
        tl('nickname'),
        tl('real_name'),
        tl('balance'),
        tl('email'),
        tl('tel'),
        tl('status'),
        tl('realname_authentication'),
        tl('bank_account_count'),
        tl('last_operator'),
        tl('created_operator'),
      ],
      rows.map((r) => [
        r.created_at ?? '',
        r.last_login ?? '',
        long2ip(r.first_ip),
        long2ip(r.last_ip),
        r.parent_username ?? '',
        `${Number(r.host_id) > 0 ? '* ' : ''}${r.username ?? ''}`,
        r.code ?? '',
        tl(`role_${r.role_id}`),
        r.nickname ?? '',
        r.real_name ?? '',
        balanceText(r),
        r.email ?? '',
        r.mobile ?? '',
        statusTags(r)
          .map((t) => t.text)
          .join('/'),
        Number(r.realname_validate) === 1 ? tl('passed') : tl('no_pass'),
        r.bank_account_count ?? 0,
        r.operator ?? '',
        r.created_by ?? '',
      ]),
      {
        audit: { conditions: params },
        title: tl('agent_account_management'),
      },
    );
  } finally {
    exporting.value = false;
  }
}
</script>

<template>
  <Page
    :title="`${tl('agnet_management')} > ${tl('agent_account_management')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <div class="flex items-center justify-between">
          <span class="font-medium">{{ tl('search') }}</span>
          <ElTag v-if="parentId" closable type="info" @close="handleReset">
            {{ tl('check_lower') }}：{{ parentId }}
          </ElTag>
        </div>
      </template>
      <ElForm
        :model="filters"
        label-width="auto"
        @submit.prevent="handleSearch"
      >
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-3">
          <ElFormItem :label="tl('username')">
            <div class="flex w-full items-center gap-2">
              <ElInput v-model="filters.username" clearable />
              <ElCheckbox v-model="filters.fuzzy_query">
                {{ tl('fuzzy_query') }}
              </ElCheckbox>
            </div>
          </ElFormItem>
          <ElFormItem :label="tl('real_name')">
            <div class="flex w-full items-center gap-2">
              <ElInput v-model="filters.real_name" clearable />
              <ElCheckbox v-model="filters.fuzzy_query_real_name">
                {{ tl('fuzzy_query') }}
              </ElCheckbox>
            </div>
          </ElFormItem>
          <ElFormItem :label="tl('account_type')">
            <ElSelect v-model="filters.account_type">
              <ElOption :label="tl('all')" value="all" />
              <ElOption :label="tl('agent_account')" value="agent" />
              <ElOption :label="tl('sub_account')" value="sub" />
            </ElSelect>
          </ElFormItem>

          <ElFormItem :label="tl('mobile')">
            <ElInput v-model="filters.mobile" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('email')">
            <ElInput v-model="filters.email" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('account_no')">
            <ElInput v-model="filters.account_no" clearable />
          </ElFormItem>

          <ElFormItem :label="tl('status')">
            <ElSelect v-model="filters.status" clearable>
              <ElOption :label="tl('all')" value="" />
              <ElOption :label="tl('enable')" value="1" />
              <ElOption :label="tl('disable')" value="0" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('restrict_login')">
            <ElSelect v-model="filters.sys_lock" clearable>
              <ElOption :label="tl('all')" value="" />
              <ElOption :label="tl('yes')" value="1" />
              <ElOption :label="tl('no')" value="0" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('sharecode')">
            <ElInput v-model="filters.code" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('first_ip')">
            <ElInput v-model="filters.first_login_ip" clearable />
          </ElFormItem>

          <template v-if="canGroup">
            <ElFormItem :label="tl('agent_group_type_1')">
              <ElSelect v-model="filters.customized_setting_group_id" clearable>
                <ElOption :label="tl('all')" value="" />
                <ElOption
                  v-for="g in customizedGroups"
                  :key="g.id"
                  :label="g.name"
                  :value="g.id"
                />
              </ElSelect>
            </ElFormItem>
            <ElFormItem :label="tl('agent_group_type_2')">
              <ElSelect v-model="filters.cash_flow_group_id" clearable>
                <ElOption :label="tl('all')" value="" />
                <ElOption
                  v-for="g in cashFlowGroups"
                  :key="g.id"
                  :label="g.name"
                  :value="g.id"
                />
              </ElSelect>
            </ElFormItem>
            <ElFormItem :label="tl('agent_group_type_4')">
              <ElSelect v-model="filters.chat_group_id" clearable>
                <ElOption :label="tl('all')" value="" />
                <ElOption
                  v-for="g in chatGroups"
                  :key="g.id"
                  :label="g.name"
                  :value="g.id"
                />
              </ElSelect>
            </ElFormItem>
          </template>
        </div>
        <div class="flex justify-end gap-2">
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
          <ElButton @click="handleReset">{{ tl('clear') }}</ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <div v-if="canExport" class="mb-3 flex justify-end">
        <ElButton :loading="exporting" type="success" @click="handleExport">
          {{ tl('excel') }}
        </ElButton>
      </div>
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <!-- 創建日期/最後登錄時間/首次登錄IP/最後登錄IP -->
        <ElTableColumn width="170">
          <template #header>
            <div>{{ tl('createdate') }}</div>
            <div>{{ tl('last_login') }}</div>
            <div>{{ tl('first_ip') }}</div>
            <div>{{ tl('last_ip') }}</div>
          </template>
          <template #default="{ row }">
            <div>{{ row.created_at || '-' }}</div>
            <div class="text-muted-foreground">{{ row.last_login || '-' }}</div>
            <div class="text-xs">{{ long2ip(row.first_ip) }}</div>
            <div class="text-xs">{{ long2ip(row.last_ip) }}</div>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('agent_previous')" min-width="110">
          <template #default="{ row }">
            <span class="text-info font-medium">
              <AgentAccount :username="row.parent_username" />
            </span>
          </template>
        </ElTableColumn>
        <!-- 帳號(*為子帳號)/分享碼 -->
        <ElTableColumn min-width="130">
          <template #header>
            <div>{{ tl('username') }}{{ tl('sub_account_tip') }}</div>
            <div>{{ tl('sharecode') }}</div>
          </template>
          <template #default="{ row }">
            <div
              :class="row.host_id > 0 ? 'text-green-600' : ''"
              class="font-medium"
            >
              <span v-if="row.host_id > 0" class="mr-0.5">*</span>
              <AgentAccount :username="row.username" />
            </div>
            <div class="text-xs">{{ row.code }}</div>
          </template>
        </ElTableColumn>
        <!-- 帳號角色/暱稱/真實姓名 -->
        <ElTableColumn min-width="120">
          <template #header>
            <div>{{ tl('roles') }}</div>
            <div>{{ tl('nickname') }}</div>
            <div>{{ tl('real_name') }}</div>
          </template>
          <template #default="{ row }">
            <div>{{ tl(`role_${row.role_id}`) }}</div>
            <div class="text-xs">{{ row.nickname || '-' }}</div>
            <div
              :title="row.real_name"
              class="text-muted-foreground line-clamp-1 text-xs"
            >
              {{ row.real_name || '-' }}
            </div>
          </template>
        </ElTableColumn>
        <!-- 餘額(+佔成錢包) -->
        <ElTableColumn align="right" width="120">
          <template #header>
            <div>{{ tl('balance') }}</div>
            <div v-if="profitShareWalletVisible" class="text-info">
              {{ tl('wallet_type.3') }}
            </div>
          </template>
          <template #default="{ row }">
            <div>{{ balanceText(row as AgentRow) }}</div>
            <div v-if="multipleWalletSwitch">
              {{ fmtAmount(row.second_wallet_balance ?? 0) }}
            </div>
            <div v-if="profitShareWalletVisible" class="text-info">
              {{
                row.profit_share_unlimited === 1
                  ? tl('unlimited')
                  : fmtAmount(row.profit_share_balance)
              }}
            </div>
          </template>
        </ElTableColumn>
        <!-- 電子郵件/聯絡電話 -->
        <ElTableColumn min-width="140">
          <template #header>
            <div>{{ tl('email') }}</div>
            <div>{{ tl('tel') }}</div>
          </template>
          <template #default="{ row }">
            <div :title="row.email" class="text-info line-clamp-1 font-medium">
              {{ row.email || '-' }}
            </div>
            <div class="text-xs">{{ row.mobile || '-' }}</div>
          </template>
        </ElTableColumn>
        <!-- 狀態/實名認證/銀行卡數量 -->
        <ElTableColumn width="110">
          <template #header>
            <div>{{ tl('status') }}</div>
            <div>{{ tl('realname_authentication') }}</div>
            <div>{{ tl('bank_account_count') }}</div>
          </template>
          <template #default="{ row }">
            <div class="flex flex-wrap items-center gap-1">
              <ElTag
                v-for="t in statusTags(row as AgentRow)"
                :key="t.text"
                :type="t.type"
                size="small"
              >
                {{ t.text }}
              </ElTag>
            </div>
            <div class="text-xs">
              {{ row.realname_validate === 1 ? tl('passed') : tl('no_pass') }}
            </div>
            <div class="text-xs">
              <ElButton
                link
                type="primary"
                @click="bankAccountRef?.open(row.id, row.username)"
              >
                {{ row.bank_account_count ?? 0 }}
              </ElButton>
            </div>
          </template>
        </ElTableColumn>
        <!-- 最後操作人/創建人 -->
        <ElTableColumn width="110">
          <template #header>
            <div>{{ tl('last_operator') }}</div>
            <div>{{ tl('created_operator') }}</div>
          </template>
          <template #default="{ row }">
            <div class="text-xs">
              <AgentAccount :username="row.operator" />
            </div>
            <div class="text-muted-foreground text-xs">
              <AgentAccount :username="row.created_by" />
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('operate')"
          align="center"
          width="90"
          fixed="right"
        >
          <template #default="{ row }">
            <ElDropdown>
              <ElButton size="small">{{ tl('operate') }}</ElButton>
              <template #dropdown>
                <ElDropdownMenu>
                  <template v-if="canDisplayFunction">
                    <ElDropdownItem @click="profileRef?.open(row.id)">
                      {{ tl('detailinfo') }}
                    </ElDropdownItem>
                    <ElDropdownItem @click="checkLower(row.id)">
                      {{ tl('check_lower') }}
                    </ElDropdownItem>
                    <ElDropdownItem
                      @click="
                        router.push({
                          path: '/vendor/index',
                          query: { id: row.id },
                        })
                      "
                    >
                      {{ tl('menu_vendor') }}
                    </ElDropdownItem>
                    <ElDropdownItem @click="domainRef?.open(row.id)">
                      {{ tl('domain_noted_check') }}
                    </ElDropdownItem>
                    <ElDropdownItem @click="copyPromoteUrl(row.code)">
                      {{ tl('copy_promote_url') }}
                    </ElDropdownItem>
                    <ElDropdownItem
                      v-if="canUpdateGroupPerm && row.host_id !== 1"
                      @click="
                        router.push({
                          path: '/agent/agent_group_permission',
                          query: { agent_id: row.id },
                        })
                      "
                    >
                      {{ tl('menu_update_agent_group_permissions') }}
                    </ElDropdownItem>
                    <ElDropdownItem
                      v-if="canAdvanceSetting && row.host_id === 0"
                      @click="
                        router.push({
                          path: '/agent/advance_setting',
                          query: { agent_id: row.id },
                        })
                      "
                    >
                      {{ tl('menu_agent_advance_setting') }}
                    </ElDropdownItem>
                    <ElDropdownItem
                      v-if="
                        row.host_id > 0 &&
                        row.parent_parent === 1 &&
                        row.role_id !== 12
                      "
                      @click="setRoleToCs(row as AgentRow)"
                    >
                      {{ tl('set_role_to_cs') }}
                    </ElDropdownItem>
                    <ElDropdownItem
                      v-if="canDeposit"
                      divided
                      @click="transactionRef?.open(row as AgentRow, 1)"
                    >
                      {{ tl('deposit') }}
                    </ElDropdownItem>
                    <ElDropdownItem
                      v-if="canWithdrawal"
                      @click="transactionRef?.open(row as AgentRow, 2)"
                    >
                      {{ tl('withdrawal') }}
                    </ElDropdownItem>
                    <ElDropdownItem
                      v-if="
                        multipleWalletSwitch && canDeposit && row.id !== selfId
                      "
                      @click="walletTransactionRef?.open(row as AgentRow, 1)"
                    >
                      {{ tl('game_wallet') }} {{ tl('deposit') }}
                    </ElDropdownItem>
                    <ElDropdownItem
                      v-if="
                        multipleWalletSwitch &&
                        canDeductPoint &&
                        canWithdrawal &&
                        row.id !== selfId
                      "
                      @click="walletTransactionRef?.open(row as AgentRow, 2)"
                    >
                      {{ tl('game_wallet') }} {{ tl('deduct') }}
                    </ElDropdownItem>
                    <ElDropdownItem
                      v-if="profitShareWalletDeposit"
                      @click="transactionRef?.open(row as AgentRow, 1, 3)"
                    >
                      {{ tl('wallet_type.3') }} {{ tl('deposit') }}
                    </ElDropdownItem>
                    <ElDropdownItem
                      v-if="profitShareWalletWithdrawal"
                      @click="transactionRef?.open(row as AgentRow, 2, 3)"
                    >
                      {{ tl('wallet_type.3') }} {{ tl('withdrawal') }}
                    </ElDropdownItem>
                    <ElDropdownItem
                      v-if="row.id !== selfId"
                      divided
                      @click="toggleStatus(row as AgentRow)"
                    >
                      {{ row.status === 1 ? tl('disable') : tl('enable') }}
                    </ElDropdownItem>
                    <ElDropdownItem
                      v-if="canSysLock && row.id !== selfId"
                      @click="toggleSysLock(row as AgentRow)"
                    >
                      {{
                        Number(row.sys_lock) === 1
                          ? tl('remove_restrict_login')
                          : tl('restrict_login')
                      }}
                    </ElDropdownItem>
                    <ElDropdownItem
                      v-if="canReset2fa"
                      :disabled="!!reset2faReason(row as AgentRow)"
                      @click="openReset2fa(row as AgentRow)"
                    >
                      <ElTooltip
                        :content="tl(reset2faReason(row as AgentRow))"
                        :disabled="!reset2faReason(row as AgentRow)"
                        placement="left"
                      >
                        <span>{{ tl('tfa_reset') }}</span>
                      </ElTooltip>
                    </ElDropdownItem>
                    <ElDropdownItem
                      v-if="canModifyPassword"
                      :disabled="!!modifyPasswordReason(row as AgentRow)"
                      @click="openModifyPassword(row as AgentRow, 'password')"
                    >
                      <ElTooltip
                        :content="tl(modifyPasswordReason(row as AgentRow))"
                        :disabled="!modifyPasswordReason(row as AgentRow)"
                        placement="left"
                      >
                        <span>{{ tl('modify_password') }}</span>
                      </ElTooltip>
                    </ElDropdownItem>
                    <ElDropdownItem
                      v-if="canModifyPaymentPassword"
                      :disabled="!!modifyPasswordReason(row as AgentRow)"
                      @click="
                        openModifyPassword(row as AgentRow, 'payment_password')
                      "
                    >
                      <ElTooltip
                        :content="tl(modifyPasswordReason(row as AgentRow))"
                        :disabled="!modifyPasswordReason(row as AgentRow)"
                        placement="left"
                      >
                        <span>{{ tl('modify_payment_password') }}</span>
                      </ElTooltip>
                    </ElDropdownItem>
                  </template>
                  <ElDropdownItem
                    @click="statusLogRef?.open(row.id, row.username)"
                  >
                    {{ tl('operation_record') }}
                  </ElDropdownItem>
                </ElDropdownMenu>
              </template>
            </ElDropdown>
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>

      <TablePagination
        :current-page="page"
        :page-size="PER_PAGE"
        :total="total"
        @current-change="handlePageChange"
      />
    </ElCard>

    <AgentStatusLogDialog ref="statusLogRef" />
    <AgentDomainDialog ref="domainRef" />
    <AgentBankAccountDialog ref="bankAccountRef" />
    <AgentTransactionDialog
      ref="transactionRef"
      :settings="settingsMap"
      @success="loadData"
    />
    <AgentWalletTransactionDialog
      ref="walletTransactionRef"
      :settings="settingsMap"
      @success="loadData"
    />
    <AgentProfileDialog ref="profileRef" />
    <TwoFaOtpDialog ref="otpDialogRef" />
    <AgentPasswordDialog ref="passwordDialogRef" />
  </Page>
</template>
