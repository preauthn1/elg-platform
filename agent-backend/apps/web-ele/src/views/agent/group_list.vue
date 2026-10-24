<script lang="ts" setup>
import type { AgentGroupRow } from '#/api';

import {
  defineAsyncComponent,
  onMounted,
  reactive,
  ref,
  shallowRef,
} from 'vue';

import { Page } from '@vben/common-ui';

import dayjs from 'dayjs';
import {
  ElButton,
  ElCard,
  ElDialog,
  ElDropdown,
  ElDropdownItem,
  ElDropdownMenu,
  ElEmpty,
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
} from 'element-plus';

import {
  copyAgentGroupApi,
  createAgentGroupApi,
  getAgentGroupListApi,
  getAgentsByGroupApi,
  getOrganizationDropdownApi,
  updateAgentGroupApi,
} from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';

defineOptions({ name: 'AgentGroupList' });

const PER_PAGE = 20;

/* ---- 高級設置彈窗（各設定頁改為彈窗載入，不再跳頁）---- */
const OnlineDepositSetting = defineAsyncComponent(
  () => import('./agent_online_deposit_setting.vue'),
);
const SmsSetting = defineAsyncComponent(
  () => import('./agent_sms_setting.vue'),
);
const VendorCustomizedSetting = defineAsyncComponent(
  () => import('./vendor_customized_items_setting.vue'),
);
const CustomizedSettingNew = defineAsyncComponent(
  () => import('./agent_customized_setting_new.vue'),
);
const MemberFieldSetting = defineAsyncComponent(
  () => import('./member_field_setting.vue'),
);
const EmailSetting = defineAsyncComponent(
  () => import('./agent_email_setting.vue'),
);

const settingVisible = ref(false);
const settingComp = shallowRef<any>(null);
const settingGroupId = ref<number | string>('');
const settingTitle = ref('');

function openSetting(comp: any, row: AgentGroupRow, title: string) {
  settingComp.value = comp;
  settingGroupId.value = row.id;
  settingTitle.value = title;
  settingVisible.value = true;
}

/** 組別類型 → 查人數用的欄位名 */
const GROUP_COLUMN: Record<number, string> = {
  1: 'customized_setting_group_id',
  2: 'cash_flow_group_id',
  3: 'sms_gateway_group_id',
  4: 'chat_group_id',
  5: 'vendor_customize_group_id',
  6: 'member_field_setting_group_id',
  7: 'email_gateway_group_id',
};

const TYPE_CLASS: Record<number, string> = {
  1: 'font-bold text-blue-600',
  2: 'font-bold text-red-700',
  3: 'font-bold text-gray-500',
  4: 'font-bold text-green-700',
  5: 'font-bold',
  6: 'font-bold text-purple-600',
  7: 'font-bold text-amber-600',
};

const orgs = ref<Array<{ id: number; name: string }>>([]);

function defaultFilters() {
  return {
    agent_group_type: ' ' as number | string,
    agent_name: '',
    org_id: 0 as number | string,
  };
}

const filters = reactive(defaultFilters());

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<AgentGroupRow>(
    ({ page, per_page }) =>
      getAgentGroupListApi({
        agent_group_type: filters.agent_group_type,
        agent_name: filters.agent_name,
        org_id: filters.org_id,
        page,
        per_page,
      }),
    { perPage: PER_PAGE, showError: true },
  );

/** created_at 顯示 +8 小時 */
function displayTime(v?: string) {
  return v ? dayjs(v).add(8, 'hour').format('YYYY-MM-DD HH:mm:ss') : '-';
}

function handleClear() {
  Object.assign(filters, defaultFilters());
}

/* ---- 新增/編輯 ---- */
const editVisible = ref(false);
const saving = ref(false);
const editId = ref(0);
const editTypeName = ref('');
const form = reactive({
  agent_group_type: '' as number | string,
  memo: '',
  name: '',
  organization_id: '' as number | string,
});

function openCreate() {
  editId.value = 0;
  form.name = '';
  form.memo = '';
  form.agent_group_type = '';
  form.organization_id = '';
  editVisible.value = true;
}

function openEdit(row: AgentGroupRow) {
  editId.value = row.id;
  editTypeName.value = tl(`agent_group_type_${row.agent_group_type}`);
  form.name = row.name ?? '';
  form.memo = row.memo ?? '';
  editVisible.value = true;
}

async function handleSave() {
  saving.value = true;
  try {
    const res = await (editId.value
      ? updateAgentGroupApi({
          id: editId.value,
          memo: form.memo,
          name: form.name,
        })
      : createAgentGroupApi({
          agent_group_type: form.agent_group_type,
          memo: form.memo,
          name: form.name,
          organization_id: form.organization_id,
        }));
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      editVisible.value = false;
      loadData();
    } else {
      ElMessage.error(`${res.message}.${res.data ?? ''}`);
    }
  } finally {
    saving.value = false;
  }
}

/** 複製此並建立一筆新資料 */
async function copyAndCreateNew(row: AgentGroupRow) {
  try {
    await ElMessageBox.confirm(tl('are_you_sure'));
  } catch {
    return;
  }
  const res = await copyAgentGroupApi(row.id);
  if (Number(res.code) === 1000) {
    ElMessage.success(tl('success'));
    loadData();
  } else {
    ElMessage.error(`${res.message}.${res.data ?? ''}`);
  }
}

/* ---- 組別內代理 ---- */
const agentsVisible = ref(false);
const agentsLoading = ref(false);
const groupAgents = ref<Array<{ id: number; username: string }>>([]);

async function showGroupAgents(row: AgentGroupRow) {
  const column = GROUP_COLUMN[Number(row.agent_group_type)];
  if (!column) {
    ElMessage.error('not define code.');
    return;
  }
  agentsVisible.value = true;
  agentsLoading.value = true;
  groupAgents.value = [];
  try {
    groupAgents.value = (await getAgentsByGroupApi({ [column]: row.id })) ?? [];
  } finally {
    agentsLoading.value = false;
  }
}

/** 金流/線上存款群組（type2）進階設定 → 線上存款設定彈窗 */
function onlineDepositSetting(row: AgentGroupRow) {
  openSetting(OnlineDepositSetting, row, tl('online_deposit_setting'));
}

/** 簡訊閘道群組（type3）進階設定 → 代理簡訊設定彈窗 */
function smsSetting(row: AgentGroupRow) {
  openSetting(SmsSetting, row, tl('menu_agent_advance_setting'));
}

/** 遊戲商群組（type5）進階設定 → 自訂項目設定彈窗 */
function vendorCustomizedSetting(row: AgentGroupRow) {
  openSetting(VendorCustomizedSetting, row, tl('menu_agent_advance_setting'));
}

/** 自訂群組（type1）進階設定 → 群組客製化設定彈窗 */
function customizedSettingNew(row: AgentGroupRow) {
  openSetting(CustomizedSettingNew, row, tl('menu_agent_advance_setting'));
}

/** 會員欄位設置群組（type6）進階設定 → 註冊/登入欄位設定 & 註冊限制彈窗 */
function memberFieldSetting(row: AgentGroupRow) {
  openSetting(MemberFieldSetting, row, tl('menu_agent_advance_setting'));
}

/** 電子郵件設置群組（type7）進階設定 → 郵件群組設定 & 郵件內容彈窗（工單4286） */
function emailSetting(row: AgentGroupRow) {
  openSetting(EmailSetting, row, tl('menu_agent_advance_setting'));
}

// 頁面載入即查詢
onMounted(() => {
  loadData();
  getOrganizationDropdownApi()
    .then((d) => {
      orgs.value = d ?? [];
    })
    .catch(() => {});
});
</script>

<template>
  <Page :title="`${tl('agnet_management')} > ${tl('menu_agent_group')}`">
    <div class="mb-3">
      <ElButton type="success" @click="openCreate()">{{ tl('add') }}</ElButton>
    </div>

    <!-- 查詢 -->
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-3">
          <ElFormItem :label="tl('type')">
            <ElSelect v-model="filters.agent_group_type" class="w-full">
              <ElOption :label="tl('all')" value=" " />
              <ElOption
                v-for="t in [1, 2, 3, 4, 5, 6, 7]"
                :key="t"
                :label="tl(`agent_group_type_${t}`)"
                :value="t"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('organization')">
            <ElSelect v-model="filters.org_id" class="w-full" filterable>
              <ElOption :label="tl('all')" :value="0" />
              <ElOption
                v-for="o in orgs"
                :key="o.id"
                :label="o.name"
                :value="o.id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('agent_name')">
            <ElInput v-model="filters.agent_name" clearable />
          </ElFormItem>
        </div>
        <div class="flex justify-end gap-2">
          <ElButton @click="handleClear">{{ tl('clear') }}</ElButton>
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <!-- 列表 -->
    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn :label="tl('id')" prop="id" width="80" />
        <ElTableColumn :label="tl('type')" width="140">
          <template #default="{ row }">
            <span :class="TYPE_CLASS[Number(row.agent_group_type)]">
              {{ tl(`agent_group_type_${row.agent_group_type}`) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('organization')}${tl('name')}`"
          prop="organization_name"
          min-width="120"
        />
        <ElTableColumn :label="tl('name')" min-width="140">
          <template #default="{ row }">
            <ElButton
              link
              type="primary"
              @click="openEdit(row as AgentGroupRow)"
            >
              {{ row.name }}
            </ElButton>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('memo')" prop="memo" min-width="120" />
        <ElTableColumn :label="tl('totalPeople')" align="center" width="100">
          <template #default="{ row }">
            <ElButton
              link
              type="primary"
              @click="showGroupAgents(row as AgentGroupRow)"
            >
              {{ row.total_agent_count }}
            </ElButton>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('time')" width="170">
          <template #default="{ row }">
            {{ displayTime(row.created_at) }}
          </template>
        </ElTableColumn>
        <ElTableColumn align="center" width="90" fixed="right">
          <template #default="{ row }">
            <ElDropdown
              v-if="[1, 2, 3, 5, 6, 7].includes(Number(row.agent_group_type))"
            >
              <ElButton size="small">{{ tl('features') }}</ElButton>
              <template #dropdown>
                <ElDropdownMenu>
                  <ElDropdownItem
                    v-if="Number(row.agent_group_type) === 2"
                    @click="onlineDepositSetting(row as AgentGroupRow)"
                  >
                    {{ tl('online_deposit_setting') }}
                  </ElDropdownItem>
                  <ElDropdownItem
                    v-else-if="Number(row.agent_group_type) === 5"
                    @click="vendorCustomizedSetting(row as AgentGroupRow)"
                  >
                    {{ tl('menu_agent_advance_setting') }}
                  </ElDropdownItem>
                  <ElDropdownItem
                    v-else-if="Number(row.agent_group_type) === 1"
                    @click="customizedSettingNew(row as AgentGroupRow)"
                  >
                    {{ tl('menu_agent_advance_setting') }}
                  </ElDropdownItem>
                  <ElDropdownItem
                    v-else-if="Number(row.agent_group_type) === 6"
                    @click="memberFieldSetting(row as AgentGroupRow)"
                  >
                    {{ tl('menu_agent_advance_setting') }}
                  </ElDropdownItem>
                  <ElDropdownItem
                    v-else-if="Number(row.agent_group_type) === 7"
                    @click="emailSetting(row as AgentGroupRow)"
                  >
                    {{ tl('menu_agent_advance_setting') }}
                  </ElDropdownItem>
                  <ElDropdownItem
                    v-else-if="Number(row.agent_group_type) === 3"
                    @click="smsSetting(row as AgentGroupRow)"
                  >
                    {{ tl('menu_agent_advance_setting') }}
                  </ElDropdownItem>
                  <ElDropdownItem
                    v-if="[1, 5, 6].includes(Number(row.agent_group_type))"
                    @click="copyAndCreateNew(row as AgentGroupRow)"
                  >
                    {{ tl('duplicate') }}
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

    <!-- 新增/編輯 -->
    <ElDialog
      v-model="editVisible"
      :title="editId ? tl('detailinfo_edit') : tl('add')"
      width="600"
      align-center
    >
      <ElForm label-width="auto" @submit.prevent="handleSave">
        <template v-if="editId">
          <ElFormItem :label="tl('id')">
            <ElInput :model-value="String(editId)" disabled />
          </ElFormItem>
          <ElFormItem :label="tl('type')">
            <ElInput :model-value="editTypeName" disabled />
          </ElFormItem>
        </template>
        <ElFormItem :label="tl('name')">
          <ElInput v-model="form.name" maxlength="50" />
        </ElFormItem>
        <ElFormItem :label="tl('memo')">
          <ElInput v-model="form.memo" maxlength="50" />
        </ElFormItem>
        <template v-if="!editId">
          <ElFormItem :label="tl('type')">
            <ElSelect v-model="form.agent_group_type" class="w-full">
              <ElOption :label="tl('please_choose')" value="" />
              <ElOption
                v-for="t in [1, 2, 3, 4, 5, 6, 7]"
                :key="t"
                :label="tl(`agent_group_type_${t}`)"
                :value="t"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="`${tl('organization')}${tl('name')}`">
            <ElSelect v-model="form.organization_id" class="w-full" filterable>
              <ElOption :label="tl('please_choose')" value="" />
              <ElOption
                v-for="o in orgs"
                :key="o.id"
                :label="o.name"
                :value="o.id"
              />
            </ElSelect>
          </ElFormItem>
        </template>
      </ElForm>
      <template #footer>
        <ElButton :loading="saving" type="primary" @click="handleSave">
          {{ tl('btn_update') }}
        </ElButton>
        <ElButton @click="editVisible = false">{{ tl('close') }}</ElButton>
      </template>
    </ElDialog>

    <!-- 組別內代理 -->
    <ElDialog
      v-model="agentsVisible"
      :title="tl('search_agent_group')"
      width="700"
      align-center
    >
      <div
        v-loading="agentsLoading"
        class="flex max-h-[70vh] flex-wrap gap-2 overflow-y-auto"
      >
        <ElTag v-for="a in groupAgents" :key="a.id" size="large" type="info">
          {{ a.username }}
          <span class="ml-1 opacity-60">{{ a.id }}</span>
        </ElTag>
        <ElEmpty
          v-if="!agentsLoading && groupAgents.length === 0"
          :description="tl('no_record')"
          class="w-full"
        />
      </div>
    </ElDialog>

    <!-- 高級設置（各設定頁以彈窗載入） -->
    <ElDialog
      v-model="settingVisible"
      :title="settingTitle"
      destroy-on-close
      top="5vh"
      width="1100"
    >
      <div class="max-h-[80vh] overflow-y-auto">
        <component
          :is="settingComp"
          v-if="settingVisible && settingComp"
          :key="settingGroupId"
          :embed-group-id="String(settingGroupId)"
          @close="settingVisible = false"
          @saved="loadData"
        />
      </div>
    </ElDialog>
  </Page>
</template>
