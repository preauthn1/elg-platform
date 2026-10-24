<script lang="ts" setup>
import type { AgentDetailCount, ShareholderUserRow } from '#/api';

import { computed, reactive, ref } from 'vue';

import { useAccess } from '@vben/access';
import { Page } from '@vben/common-ui';
import { useUserStore } from '@vben/stores';

import {
  ElButton,
  ElCard,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElTable,
  ElTableColumn,
  ElTooltip,
} from 'element-plus';

import {
  getAgentDetailApi,
  getShareholderUserListApi,
  resetAgent2faApi,
  resetShareholderUserPasswordApi,
} from '#/api';
import CopyIcon from '#/components/copy-icon.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useClientTable } from '#/composables/use-client-table';
import { tl } from '#/locales';

import AgentUserProfileDialog from './components/agent-user-profile-dialog.vue';
import { reset2faBlockReason, useSelfHas2fa } from './components/two-fa';
import TwoFaOtpDialog from './components/two-fa-otp-dialog.vue';

defineOptions({ name: 'AgentUserList' });

const profileRef = ref<InstanceType<typeof AgentUserProfileDialog>>();

const userStore = useUserStore();
const { hasAccessByCodes } = useAccess();
const selfId = Number((userStore.userInfo as any)?.id ?? 0);
const canReset2fa = hasAccessByCodes(['cj7_user_list_reset_2fa']);
const selfRole = Number((userStore.userInfo as any)?.role_id ?? 0);
const selfUsername = String((userStore.userInfo as any)?.username ?? '');

const isGrandAgent = [1, 11, 12].includes(selfRole);
const isInfoDisplayable = ref(false);

const filters = reactive({ username: '' });
const queryUsername = ref('');
const loading = ref(false);
const searched = ref(false);
const rows = ref<ShareholderUserRow[]>([]);
const searchPath = ref<Array<{ id: number | string; username: string }>>([]);
const pageSize = ref(25);

const title = computed(() => tl('menu_cj7_user_list'));

/* 遮罩函式 */
function encUsername(u = '') {
  const a = [...u];
  return a.slice(0, 2).join('') + '*'.repeat(Math.max(0, a.length - 2));
}
function encMobile(m = '') {
  const a = [...m];
  return '*'.repeat(Math.max(0, a.length - 3)) + a.slice(-3).join('');
}
function encEmail(e = '') {
  const [local = '', domain = ''] = e.split('@');
  const a = [...local];
  return `${a.slice(0, 2).join('')}${'*'.repeat(Math.max(0, a.length - 2))}@${domain}`;
}
function encRealName(r = '') {
  const a = [...r];
  const show = Math.ceil(a.length / 3);
  return a.slice(0, show).join('') + '*'.repeat(Math.max(0, a.length - show));
}
function isSubOrSelected(row: ShareholderUserRow) {
  return (
    Number(row.sub) === 1 ||
    (queryUsername.value !== '' &&
      queryUsername.value.toLowerCase() === String(row.username).toLowerCase())
  );
}
function canSeeInfo(row: ShareholderUserRow) {
  return isGrandAgent || (isInfoDisplayable.value && isSubOrSelected(row));
}

function canSeeUsername(row: ShareholderUserRow) {
  return isGrandAgent || isSubOrSelected(row);
}

function displayUsername(row: ShareholderUserRow) {
  return canSeeUsername(row) ? row.username : encUsername(row.username);
}
function displayMobile(row: ShareholderUserRow) {
  if (!row.mobile || !String(row.mobile).trim()) return '--';
  return canSeeInfo(row) ? row.mobile : encMobile(row.mobile);
}
function displayEmail(row: ShareholderUserRow) {
  if (!row.email || !String(row.email).trim()) return '--';
  return canSeeInfo(row) ? row.email : encEmail(row.email);
}

// 快速搜尋比對「顯示值」：避免用關鍵字反推被遮罩的手機/信箱/真名
const {
  handlePageChange,
  handleSearch: handleQuickSearch,
  handleSortChange,
  keyword,
  page,
  pagedRows,
  total,
} = useClientTable(rows, {
  perPage: pageSize,
  searchFields: (r) => [
    displayUsername(r),
    r.nickname,
    encRealName(r.real_name),
    displayMobile(r),
    displayEmail(r),
    r.last_login,
  ],
});

function handleSizeChange(size: number) {
  pageSize.value = size;
  page.value = 1;
}

async function load(
  agentId: number | string = 0,
  memberId: number | string = 0,
) {
  loading.value = true;
  searched.value = true;
  queryUsername.value = filters.username;
  try {
    const [res, settings] = await Promise.all([
      getShareholderUserListApi({
        agent_id: agentId,
        member_id: memberId,
        username: filters.username,
      }),
      // 8009 是否顯示完整資訊
      import('#/api').then((m) => m.getCustomizedSettingApi()),
    ]);
    isInfoDisplayable.value =
      Number(
        (settings ?? []).find((s: any) => String(s.setting_key) === '8009')
          ?.value,
      ) === 1;
    if (Number(res.code) === 1000) {
      rows.value = res.data?.shareholder ?? [];
      searchPath.value = res.data?.search_path ?? [];
    } else {
      rows.value = [];
      searchPath.value = [];
      ElMessage.error(res.message);
    }
    page.value = 1;
  } finally {
    loading.value = false;
  }
}

function handleSearch() {
  load(0, 0);
}
function setMe() {
  filters.username = selfUsername;
}
/**
 * 下鑽查看下級（target/tickets#4162）。
 *
 * 後端 ShareholderController 對 username 的優先序高於 agent_id：username 非空時
 * 直接以該帳號查詢、agent_id 被忽略。故下鑽前必須清空搜尋框，否則點上層節點仍
 * 只會查回搜尋框裡的那一位，表現為「點名稱無法查看下級」。
 */
function drillAgent(id: number | string) {
  filters.username = '';
  load(id, 0);
}
function drillRow(row: ShareholderUserRow) {
  filters.username = '';
  load(row.agent_id ?? 0, row.member_id ?? 0);
}
function pathLabel(p: { username: string }, i: number) {
  return !isGrandAgent && i > 1 ? encUsername(p.username) : p.username;
}

/* 統計查看 modal */
const statVisible = ref(false);
const statLoading = ref(false);
const stat = ref<AgentDetailCount>({});
async function openStat(row: ShareholderUserRow) {
  statVisible.value = true;
  statLoading.value = true;
  stat.value = {};
  try {
    const id = Number(row.type) === 1 ? row.agent_id : row.member_id;
    const res = await getAgentDetailApi(Number(id));
    stat.value = res ?? {};
  } finally {
    statLoading.value = false;
  }
}

/* 重置2FA（target/tickets#4503）：只有代理列有，反灰條件見 reset2faBlockReason */
const otpDialogRef = ref<InstanceType<typeof TwoFaOtpDialog>>();
const { loadSelfHas2fa, selfHas2fa } = useSelfHas2fa(selfId);
if (canReset2fa) loadSelfHas2fa();

function reset2faReason(row: ShareholderUserRow) {
  return reset2faBlockReason(
    { ...row, id: row.agent_id ?? 0 },
    selfId,
    selfHas2fa.value,
  );
}

async function openReset2fa(row: ShareholderUserRow) {
  if (reset2faReason(row)) return;
  const ok = await otpDialogRef.value?.open({
    confirmText: tl('tfa_confirm_reset'),
    danger: true,
    description: tl('tfa_reset_desc'),
    label: tl('tfa_input_own_code'),
    submit: (otp) =>
      resetAgent2faApi({ agent_id: Number(row.agent_id), secret: [REDACTED] }),
    title: tl('tfa_reset_title').replace('{name}', displayUsername(row) ?? ''),
  });
  if (ok) row.has_2fa = 0;
}

/* 重置密碼 modal */
const pwVisible = ref(false);
const pwSaving = ref(false);
const pwForm = reactive({
  user_name: '',
  agent_id: '' as number | string,
  new_password: '',
  confirm_new_password: '',
});
function openResetPw(row: ShareholderUserRow) {
  pwForm.user_name = String(row.username ?? '');
  pwForm.agent_id = row.agent_id ?? '';
  pwForm.new_password = '';
  pwForm.confirm_new_password = '';
  pwVisible.value = true;
}
async function submitResetPw() {
  if (pwForm.new_password !== pwForm.confirm_new_password) {
    ElMessage.error(tl('new_password_confirm_error'));
    return;
  }
  pwSaving.value = true;
  try {
    const res = await resetShareholderUserPasswordApi({ ...pwForm });
    if (Number(res.code) === 1000) {
      ElMessage.success(res.message);
      pwVisible.value = false;
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    pwSaving.value = false;
  }
}
</script>

<template>
  <Page :title="title">
    <ElCard class="mb-3" shadow="never">
      <ElForm label-width="auto" @submit.prevent="handleSearch">
        <div class="flex flex-wrap items-end gap-3">
          <ElFormItem :label="tl('username')" class="!mb-0">
            <ElInput
              v-model="filters.username"
              clearable
              placeholder="Account"
            />
          </ElFormItem>
          <a class="cursor-pointer text-primary" @click="setMe">Me</a>
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>

      <div v-if="searchPath.length > 0" class="mt-3 text-sm">
        <template v-for="(p, i) in searchPath" :key="p.id">
          <span v-if="i > 0"> -&gt; </span>
          <a class="cursor-pointer text-indigo-500" @click="drillAgent(p.id)">
            {{ pathLabel(p, i) }}
          </a>
        </template>
      </div>
    </ElCard>

    <ElCard v-if="searched" shadow="never">
      <div class="mb-3">
        <ElInput
          v-model="keyword"
          :placeholder="tl('search')"
          class="!w-56"
          clearable
          @input="handleQuickSearch"
        />
      </div>
      <ElTable
        v-loading="loading"
        :data="pagedRows"
        border
        size="small"
        stripe
        @sort-change="handleSortChange"
      >
        <ElTableColumn
          type="index"
          label="No"
          width="60"
          :index="(i) => (page - 1) * pageSize + i + 1"
        />
        <ElTableColumn
          :label="tl('login_account_uid')"
          min-width="140"
          prop="username"
          sortable="custom"
        >
          <template #default="{ row }">
            <span>
              <a
                class="cursor-pointer text-indigo-500"
                @click="drillRow(row as ShareholderUserRow)"
              >
                {{ displayUsername(row as ShareholderUserRow) }}
              </a>
              <CopyIcon
                v-if="canSeeUsername(row as ShareholderUserRow)"
                :text="row.username"
              />
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('user_type')"
          min-width="100"
          prop="type"
          sortable="custom"
        >
          <template #default="{ row }">
            {{ Number(row.type) === 2 ? tl('cash_player') : tl('shareholder') }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('name')"
          prop="nickname"
          min-width="110"
          sortable="custom"
        />
        <ElTableColumn :label="tl('real_name')" min-width="110">
          <template #default="{ row }">
            {{ encRealName(row.real_name) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('mobile')" min-width="120">
          <template #default="{ row }">
            {{ displayMobile(row as ShareholderUserRow) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('mailbox')" min-width="160">
          <template #default="{ row }">
            {{ displayEmail(row as ShareholderUserRow) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('status')"
          min-width="90"
          prop="status"
          sortable="custom"
        >
          <template #default="{ row }">
            {{ Number(row.status) === 1 ? tl('enable') : tl('disable') }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('last_login')"
          min-width="150"
          prop="last_login"
          sortable="custom"
        >
          <template #default="{ row }">{{ row.last_login || '--' }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('operate')" min-width="200">
          <template #default="{ row }">
            <div class="flex flex-wrap gap-2">
              <a
                v-if="Number(row.type) === 1 && Number(row.sub) === 1"
                class="cursor-pointer text-indigo-500"
                @click="
                  profileRef?.open((row as ShareholderUserRow).agent_id ?? 0)
                "
              >
                {{ tl('detail') }}
              </a>
              <a
                v-if="Number(row.type) === 1"
                class="cursor-pointer text-indigo-500"
                @click="openStat(row as ShareholderUserRow)"
                >{{ tl('statistics_view') }}</a
              >
              <a
                v-if="!row.last_login"
                class="cursor-pointer text-indigo-500"
                @click="openResetPw(row as ShareholderUserRow)"
                >{{ tl('label_change_password') }}</a
              >
              <ElTooltip
                v-if="canReset2fa && Number(row.type) === 1"
                :content="tl(reset2faReason(row as ShareholderUserRow))"
                :disabled="!reset2faReason(row as ShareholderUserRow)"
              >
                <a
                  :class="
                    reset2faReason(row as ShareholderUserRow)
                      ? 'cursor-not-allowed text-gray-400'
                      : 'cursor-pointer text-indigo-500'
                  "
                  @click="openReset2fa(row as ShareholderUserRow)"
                  >{{ tl('tfa_reset') }}</a
                >
              </ElTooltip>
            </div>
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
      <TablePagination
        :current-page="page"
        :page-size="pageSize"
        :total="total"
        :page-sizes="[10, 25, 50, 100]"
        layout="total, sizes, prev, pager, next, jumper"
        @current-change="handlePageChange"
        @size-change="handleSizeChange"
      />
    </ElCard>

    <!-- 統計查看 -->
    <ElDialog v-model="statVisible" :title="tl('team_headcount')" width="420px">
      <div v-loading="statLoading" class="space-y-2">
        <p>{{ tl('shareholder') }}：{{ stat.agent_count ?? 0 }}</p>
        <p>{{ tl('player') }}：{{ stat.member_count ?? 0 }}</p>
        <p>{{ tl('active_player') }}：{{ stat.active_member_count ?? 0 }}</p>
        <p>
          {{ tl('new_registered_player') }}：{{
            stat.registered_member_count ?? 0
          }}
        </p>
      </div>
    </ElDialog>

    <!-- 重置密碼 -->
    <ElDialog v-model="pwVisible" :title="tl('reset_password')" width="460px">
      <ElForm label-position="top" @submit.prevent="submitResetPw">
        <ElFormItem :label="tl('new_password')">
          <ElInput
            v-model="pwForm.new_password"
            type="password"
            show-password
          />
        </ElFormItem>
        <ElFormItem :label="tl('new_password_confirm')">
          <ElInput
            v-model="pwForm.confirm_new_password"
            type="password"
            show-password
          />
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="pwVisible = false">{{ tl('cancel') }}</ElButton>
        <ElButton :loading="pwSaving" type="primary" @click="submitResetPw">
          {{ tl('submit') }}
        </ElButton>
      </template>
    </ElDialog>

    <AgentUserProfileDialog ref="profileRef" @success="load()" />
    <TwoFaOtpDialog ref="otpDialogRef" />
  </Page>
</template>
