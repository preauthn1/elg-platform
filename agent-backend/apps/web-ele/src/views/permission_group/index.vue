<script lang="ts" setup>
import type {
  AgentPermissionGroupAgentRow,
  AgentPermissionGroupOwnerOption,
  AgentPermissionGroupRow,
} from '#/api';

import { computed, onActivated, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElOption,
  ElSelect,
  ElSwitch,
  ElTable,
  ElTableColumn,
  ElTag,
} from 'element-plus';

import {
  getAgentPermissionGroupAgentsApi,
  getAgentPermissionGroupListApi,
  getAgentPermissionGroupOwnerDropdownApi,
  setAgentPermissionGroupStatusApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';
import { fromApiTime } from '#/utils/timezone-shift';

defineOptions({ name: 'PermissionGroupIndex' });

const PER_PAGE = 25;

const router = useRouter();

const loading = ref(false);
const list = ref<AgentPermissionGroupRow[]>([]);
const page = ref(1);
const total = ref(0);

const filters = reactive({
  name: '',
  /** 工單4080／4543：依持有者篩選。'' = 不篩選、0 = admin 持有；僅 super admin 看得到此條件 */
  owner_agent_id: '' as number | string,
  status: '' as number | string,
});

/**
 * 工單4080／4543：所屬代理篩選。
 *
 * super admin 看得到全部持有者；總代看得到自己＋下線建的（工單示意圖總代列表有此篩選），
 * 兩者都需要依所屬代理篩。一般代理只有自己的，不顯示。是否顯示由後端 can_filter 決定。
 */
const owners = ref<AgentPermissionGroupOwnerOption[]>([]);
const canFilterOwner = ref(false);
/** 「admin 持有」這個篩選項只有 super admin 用得到（總代的列表不會有 admin 持有的角色）；後端只給 super admin is_admin 那項 */
const isSuperAdminOwners = computed(() => owners.value.some((o) => o.is_admin));

async function loadOwners() {
  const res = await getAgentPermissionGroupOwnerDropdownApi().catch(() => null);
  const data = res && Number(res.code) === 200 ? res.data : undefined;
  owners.value = data?.owners ?? [];
  canFilterOwner.value = data?.can_filter ?? false;
}

async function loadData() {
  loading.value = true;
  try {
    const res = await getAgentPermissionGroupListApi({
      page: page.value,
      per_page: PER_PAGE,
      ...(filters.name.trim() ? { name: filters.name.trim() } : {}),
      ...(filters.status === '' ? {} : { status: filters.status }),
      ...(filters.owner_agent_id === ''
        ? {}
        : { owner_agent_id: filters.owner_agent_id }),
    });
    const ok = Number(res.code) === 200;
    list.value = ok ? (res.data?.items ?? []) : [];
    total.value = ok ? (res.data?.pagination?.total ?? 0) : 0;
  } finally {
    loading.value = false;
  }
}

function search() {
  page.value = 1;
  loadData();
}

function clear() {
  filters.name = '';
  filters.status = '';
  filters.owner_agent_id = '';
  search();
}

function handlePageChange(next: number) {
  page.value = next;
  loadData();
}

/** 建立／編輯一律另開分頁（工單要求，彈窗畫面太小不好操作） */
function goCreate() {
  router.push({ path: '/permission_group/edit' });
}

function goEdit(row: AgentPermissionGroupRow) {
  router.push({ path: '/permission_group/edit', query: { id: row.id } });
}

/** 預設角色預設給誰：owner 1＝admin 子帳號、owner 空＝新營運商、其他＝該持有者線下級（工單4543） */
function defaultScopeLabel(row: AgentPermissionGroupRow): string {
  if (row.owner_agent_id === 1) return tl('default_for_admin_sub');
  if (!row.owner_agent_id) return tl('default_for_new_operator');
  return tl('default_for_line').replace('{name}', row.owner_username ?? '');
}

async function onToggle(row: AgentPermissionGroupRow, next: boolean) {
  const res = await setAgentPermissionGroupStatusApi({
    id: row.id,
    status: next ? 1 : 0,
  }).catch(() => null);

  if (res && Number(res.code) === 200) {
    ElMessage.success(tl('operation_success'));
    loadData();
  } else {
    ElMessage.error(res?.message ?? tl('operation_failed'));
    // 失敗時把開關轉回原狀，避免畫面與實際狀態不一致
    row.status = next ? 0 : 1;
  }
}

/* ---- 代理清單彈窗（target/op_tickets#3168：代理數點擊查看）---- */

const agentsDialog = reactive({
  visible: false,
  loading: false,
  /** 開啟時的角色，供標題顯示與換頁重查 */
  groupId: 0 as number | string,
  groupName: '',
  page: 1,
  total: 0,
});

const agentRows = ref<AgentPermissionGroupAgentRow[]>([]);

async function loadAgents() {
  agentsDialog.loading = true;
  try {
    const res = await getAgentPermissionGroupAgentsApi({
      id: agentsDialog.groupId,
      page: agentsDialog.page,
      per_page: PER_PAGE,
    }).catch(() => null);

    if (!res || Number(res.code) !== 200) {
      ElMessage.error(res?.message ?? tl('operation_failed'));
      agentRows.value = [];
      agentsDialog.total = 0;
      return;
    }

    agentRows.value = res.data?.items ?? [];
    agentsDialog.total = res.data?.pagination?.total ?? 0;
  } finally {
    agentsDialog.loading = false;
  }
}

/**
 * 代理數為 0 時不開彈窗——開了也只是空表，徒增一次請求。
 *
 * 注意列表的 agents_count 是「該角色底下的全部代理」，彈窗因範疇過濾只列
 * 操作者看得到的那些，兩個數字對不上是預期的（跨線的代理不該被看到）。
 */
function showAgents(row: AgentPermissionGroupRow) {
  if (!row.agents_count) return;

  agentsDialog.groupId = row.id;
  agentsDialog.groupName = row.name;
  agentsDialog.page = 1;
  agentsDialog.visible = true;
  agentRows.value = [];
  loadAgents();
}

function handleAgentsPageChange(next: number) {
  agentsDialog.page = next;
  loadAgents();
}

/**
 * 分頁受 KeepAlive 快取（元件不會重新 mount），只在 setup／onMounted 載入的話，
 * 從編輯分頁新增或修改角色後切回列表會看到舊資料。
 *
 * 用 onActivated 而非 onMounted：KeepAlive 元件首次掛載時兩者都會觸發，
 * 只寫 onActivated 即可涵蓋「首次進入」與「每次切回」，且不會重複查詢。
 */
onActivated(() => {
  loadData();
});

// 總代清單不會變動，載入一次即可，不隨列表重查
loadOwners();
</script>

<template>
  <Page
    :title="`${tl('menu_agent_management')} > ${tl('menu_permission_group_management')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-3">
          <ElFormItem :label="tl('role_name')">
            <ElInput
              v-model="filters.name"
              clearable
              :placeholder="tl('please_enter')"
            />
          </ElFormItem>
          <ElFormItem :label="tl('status')">
            <ElSelect v-model="filters.status" class="!w-full">
              <ElOption :label="tl('all')" value="" />
              <ElOption :label="tl('enable')" :value="1" />
              <ElOption :label="tl('disable')" :value="0" />
            </ElSelect>
          </ElFormItem>
          <!-- 工單4080／4543：super admin 與總代可依所屬代理篩選 -->
          <ElFormItem v-if="canFilterOwner" :label="tl('role_owner_agent')">
            <ElSelect
              v-model="filters.owner_agent_id"
              class="!w-full"
              clearable
              filterable
              @change="search"
            >
              <ElOption :label="tl('all')" value="" />
              <!-- 工單4543：0＝admin 持有（owner 為空），只有 admin 能套 -->
              <ElOption
                v-if="isSuperAdminOwners"
                :label="tl('role_owner_unassigned')"
                :value="0"
              />
              <!-- 工單4422：admin預設角色（子帳號專用）排在後面（後端固定第一項），其後畫分隔線才接一般總代 -->
              <template v-for="o in owners" :key="o.id">
                <ElOption
                  :label="
                    o.is_admin ? tl('role_owner_admin_sub_only') : o.username
                  "
                  :value="o.id"
                />
                <li
                  v-if="o.is_admin"
                  role="separator"
                  class="border-border mx-3 my-1 border-t"
                ></li>
              </template>
            </ElSelect>
          </ElFormItem>
        </div>
        <div class="flex justify-end gap-2">
          <ElButton @click="clear">{{ tl('clear') }}</ElButton>
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <template #header>
        <div class="flex items-center justify-between">
          <span class="text-muted-foreground text-xs">
            {{ tl('agent_role_hint') }}
          </span>
          <ElButton type="success" @click="goCreate">
            {{ tl('add') }}
          </ElButton>
        </div>
      </template>

      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn :label="tl('role_name')" min-width="180">
          <template #default="{ row }">
            <div class="font-bold">{{ row.name }}</div>
            <div class="text-muted-foreground text-xs">
              {{ tl('id') }} {{ row.id }}
            </div>
          </template>
        </ElTableColumn>
        <!-- 工單4543：owner 1＝admin預設角色（子帳號專用）、空＝admin 持有（顯示 admin）、其他＝持有的代理帳號 -->
        <ElTableColumn :label="tl('role_owner_agent')" min-width="140">
          <template #default="{ row }">
            <span v-if="row.owner_agent_id === 1">
              {{ tl('role_owner_admin_sub_only') }}
            </span>
            <AgentAccount
              v-else-if="row.owner_username"
              :username="row.owner_username"
            />
            <span v-else>
              {{ tl('role_owner_all_lines') }}
            </span>
          </template>
        </ElTableColumn>
        <!-- 工單4543：預設角色寫出預設給誰，admin預設角色（owner 1）與共用預設角色（owner 空）才分得出來 -->
        <ElTableColumn :label="tl('set_as_default_group')" min-width="140">
          <template #default="{ row }">
            <template v-if="row.default_setting === 1">
              <ElTag size="small" type="warning">{{ tl('yes') }}</ElTag>
              <div class="text-muted-foreground mt-1 text-xs">
                {{ defaultScopeLabel(row as AgentPermissionGroupRow) }}
              </div>
            </template>
            <span v-else class="text-muted-foreground">{{ tl('no') }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('agent_count')" min-width="90">
          <template #default="{ row }">
            <!-- 有代理才做成連結：0 個點了只會開出空表 -->
            <ElButton
              v-if="row.agents_count"
              link
              type="primary"
              @click="showAgents(row as AgentPermissionGroupRow)"
            >
              {{ row.agents_count }}
            </ElButton>
            <span v-else class="text-muted-foreground">0</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('status')" min-width="90">
          <template #default="{ row }">
            <!-- 預設角色恆為啟用：新增營運商要靠它帶出預設權限；工單4543：下線建的只能檢視，不給開關 -->
            <ElSwitch
              :disabled="row.default_setting === 1 || row.can_edit === false"
              :model-value="row.status === 1"
              @change="
                (v: any) => onToggle(row as AgentPermissionGroupRow, Boolean(v))
              "
            />
          </template>
        </ElTableColumn>
        <!-- 後端回 UTC，依使用者所選時區顯示（工單4199 順修：原直出少 8 小時） -->
        <ElTableColumn :label="tl('updated_at')" min-width="160">
          <template #default="{ row }">
            {{ fromApiTime(row.updated_at) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('memo')" min-width="200">
          <template #default="{ row }">{{ row.remark || '-' }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('operate')" fixed="right" width="100">
          <template #default="{ row }">
            <!-- 工單4543：總代對下線建的角色只能檢視（編輯頁會以唯讀開啟） -->
            <ElButton
              link
              type="primary"
              @click="goEdit(row as AgentPermissionGroupRow)"
            >
              {{ row.can_edit === false ? tl('view') : tl('edit') }}
            </ElButton>
          </template>
        </ElTableColumn>
      </ElTable>

      <TablePagination
        :current-page="page"
        :page-size="PER_PAGE"
        :total="total"
        @current-change="handlePageChange"
      />
    </ElCard>

    <!-- 角色底下的代理清單（target/op_tickets#3168） -->
    <ElDialog
      v-model="agentsDialog.visible"
      :title="`${agentsDialog.groupName} - ${tl('agent_count')}`"
      width="640px"
    >
      <ElTable
        v-loading="agentsDialog.loading"
        :data="agentRows"
        border
        max-height="420"
        size="small"
        stripe
      >
        <ElTableColumn :label="tl('agent_account')" min-width="160">
          <template #default="{ row }">
            <AgentAccount :username="row.username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('agent_previous')" min-width="160">
          <template #default="{ row }">
            <AgentAccount
              v-if="row.parent_username"
              :username="row.parent_username"
            />
            <span v-else class="text-muted-foreground">-</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('status')" min-width="90">
          <template #default="{ row }">
            <ElTag :type="row.status === 1 ? 'success' : 'info'" size="small">
              {{ row.status === 1 ? tl('enable') : tl('disable') }}
            </ElTag>
          </template>
        </ElTableColumn>
      </ElTable>

      <TablePagination
        :current-page="agentsDialog.page"
        :page-size="PER_PAGE"
        :total="agentsDialog.total"
        @current-change="handleAgentsPageChange"
      />
    </ElDialog>
  </Page>
</template>
