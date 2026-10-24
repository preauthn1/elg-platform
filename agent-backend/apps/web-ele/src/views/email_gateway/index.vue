<script lang="ts" setup>
import type { EmailGatewayGroupOption, EmailGatewayRow } from '#/api';

import { reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElMessageBox,
  ElOption,
  ElSelect,
  ElSwitch,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  deleteEmailGatewayApi,
  getEmailGatewayGroupDropdownApi,
  getEmailGatewayListApi,
  toggleEmailGatewayStatusApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';

import EmailGatewayEditDialog from './components/email-gateway-edit-dialog.vue';

defineOptions({ name: 'EmailGatewayIndex' });

const PER_PAGE = 20;

const editRef = ref<InstanceType<typeof EmailGatewayEditDialog>>();

const loading = ref(false);
const list = ref<EmailGatewayRow[]>([]);
const page = ref(1);
const total = ref(0);
const groupOptions = ref<EmailGatewayGroupOption[]>([]);

const filters = reactive({
  agent_group_id: undefined as number | undefined,
  email: '',
  status: '' as number | string,
});

async function loadData() {
  loading.value = true;
  try {
    const res = await getEmailGatewayListApi({
      page: page.value,
      per_page: PER_PAGE,
      ...(filters.agent_group_id === undefined
        ? {}
        : { agent_group_id: filters.agent_group_id }),
      ...(filters.email.trim() ? { email: filters.email.trim() } : {}),
      ...(filters.status === '' ? {} : { status: filters.status }),
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
  filters.agent_group_id = undefined;
  filters.email = '';
  filters.status = '';
  search();
}

function handlePageChange(next: number) {
  page.value = next;
  loadData();
}

async function loadGroups() {
  const res = await getEmailGatewayGroupDropdownApi().catch(() => null);
  groupOptions.value = res && Number(res.code) === 200 ? (res.data ?? []) : [];
}

/** 組別被刪除時顯示「無」而不是留白 */
function groupText(name?: null | string): string {
  return name?.trim() ? name : tl('none');
}

/** scheme → 顯示文字 */
function schemeText(scheme: string): string {
  return scheme === 'smtps'
    ? tl('email_gateway_scheme_smtps')
    : tl('email_gateway_scheme_smtp');
}

async function onToggle(row: EmailGatewayRow, next: boolean) {
  const res = await toggleEmailGatewayStatusApi(row.id, next ? 1 : 0).catch(
    () => null,
  );
  if (res && Number(res.code) === 200) {
    ElMessage.success(tl('operation_success'));
  } else {
    ElMessage.error(res?.message ?? tl('operation_failed'));
  }
  loadData();
}

async function onDelete(row: EmailGatewayRow) {
  try {
    await ElMessageBox.confirm(tl('email_gateway_delete_confirm'), tl('tips'), {
      cancelButtonText: tl('cancel'),
      confirmButtonText: tl('confirm'),
    });
  } catch {
    return;
  }

  const res = await deleteEmailGatewayApi(row.id).catch(() => null);
  if (res && Number(res.code) === 200) {
    ElMessage.success(tl('operation_success'));
    loadData();
  } else {
    ElMessage.error(res?.message ?? tl('operation_failed'));
  }
}

loadGroups();
loadData();
</script>

<template>
  <Page
    :title="`${tl('menu_email_management')} > ${tl('menu_email_gateway_list')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-3">
          <ElFormItem :label="tl('agent_group_type_7')">
            <ElSelect
              v-model="filters.agent_group_id"
              :placeholder="tl('please_choose')"
              class="!w-full"
              clearable
              filterable
            >
              <ElOption
                v-for="g in groupOptions"
                :key="g.id"
                :label="g.name"
                :value="g.id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('email')">
            <ElInput v-model="filters.email" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('status')">
            <ElSelect v-model="filters.status" class="!w-full">
              <ElOption :label="tl('all')" value="" />
              <ElOption :label="tl('enable')" :value="1" />
              <ElOption :label="tl('disable')" :value="0" />
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
            {{ tl('email_gateway_fallback_hint') }}
          </span>
          <ElButton type="success" @click="editRef?.open()">
            {{ tl('add') }}
          </ElButton>
        </div>
      </template>

      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn :label="tl('agent_group_type_7')" min-width="160">
          <template #default="{ row }">
            <div class="font-bold">{{ groupText(row.agent_group_name) }}</div>
            <div class="text-muted-foreground text-xs">
              {{ tl('id') }} {{ row.id }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('email')" min-width="200" prop="email" />
        <ElTableColumn :label="tl('email_gateway_host')" min-width="180">
          <template #default="{ row }">
            <div class="break-all">{{ row.host }}:{{ row.port }}</div>
            <div class="text-muted-foreground text-xs">
              {{ schemeText(row.scheme) }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('email_gateway_from_name')"
          min-width="120"
          prop="from_name"
        />
        <ElTableColumn :label="tl('field_sort')" min-width="80" prop="sort" />
        <ElTableColumn :label="tl('status')" min-width="120">
          <template #default="{ row }">
            <ElSwitch
              :active-text="tl('enable')"
              :inactive-text="tl('disable')"
              :model-value="Number(row.status) === 1"
              @change="(v) => onToggle(row as EmailGatewayRow, Boolean(v))"
            />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('operate_agent_username')" min-width="140">
          <template #default="{ row }">
            <div><AgentAccount :username="row.updated_by" /></div>
            <div class="text-muted-foreground text-xs">
              {{ row.updated_at }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('action')"
          align="center"
          width="140"
          fixed="right"
        >
          <template #default="{ row }">
            <ElButton
              size="small"
              @click="editRef?.open((row as EmailGatewayRow).id)"
            >
              {{ tl('edit') }}
            </ElButton>
            <ElButton
              size="small"
              type="danger"
              @click="onDelete(row as EmailGatewayRow)"
            >
              {{ tl('delete') }}
            </ElButton>
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

    <EmailGatewayEditDialog ref="editRef" @success="loadData" />
  </Page>
</template>
