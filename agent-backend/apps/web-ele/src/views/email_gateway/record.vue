<script lang="ts" setup>
import type { EmailGatewayGroupOption, EmailGatewayRecordRow } from '#/api';

import { onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  getCustomizedSettingApi,
  getEmailGatewayGroupDropdownApi,
  getEmailGatewayRecordApi,
  getEmailGatewayRecordContentApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import { sixTypesQuickRange } from '#/utils/quick-range';
import { fromApiTime, toApiTime } from '#/utils/timezone-shift';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'EmailGatewayRecord' });

const PER_PAGE = 20;

const loading = ref(false);
const list = ref<EmailGatewayRecordRow[]>([]);
const page = ref(1);
const total = ref(0);
const groupOptions = ref<EmailGatewayGroupOption[]>([]);
/** 設定 5012：1=直接顯示驗證碼內容；設定讀回來前先隱藏 */
const contentVisible = ref(false);

const filters = reactive({
  agent_group_id: '' as number | string,
  agent_username: '',
  member_username: '',
  receiver: '',
  send_id: '',
  status: '' as number | string,
  time_range: sixTypesQuickRange('today') as [string, string],
});

function buildParams(pageNo: number, perPage: number) {
  const [start, end] = filters.time_range ?? ['', ''];

  return {
    page: pageNo,
    per_page: perPage,
    ...(filters.agent_group_id === ''
      ? {}
      : { agent_group_id: filters.agent_group_id }),
    ...(filters.agent_username.trim()
      ? { agent_username: filters.agent_username.trim() }
      : {}),
    ...(filters.member_username.trim()
      ? { member_username: filters.member_username.trim() }
      : {}),
    ...(filters.receiver.trim() ? { receiver: filters.receiver.trim() } : {}),
    ...(filters.send_id.trim() ? { send_id: filters.send_id.trim() } : {}),
    ...(filters.status === '' ? {} : { status: filters.status }),
    ...(start && end
      ? { end_time: toApiTime(end), start_time: toApiTime(start) }
      : {}),
  };
}

async function loadData() {
  loading.value = true;
  try {
    const res = await getEmailGatewayRecordApi(
      buildParams(page.value, PER_PAGE),
    );
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

function handlePageChange(next: number) {
  page.value = next;
  loadData();
}

function statusText(status: number | string): string {
  const n = Number(status);
  if (n === 1) return tl('sent_successfully');

  return n === 2 ? tl('failed_to_send') : tl('unsent');
}

const exporting = ref(false);

async function handleExport() {
  exporting.value = true;
  try {
    const [start, end] = filters.time_range ?? ['', ''];
    const params = buildParams(1, 100_000);
    const res = await getEmailGatewayRecordApi(params);
    const rows = Number(res.code) === 200 ? (res.data?.items ?? []) : [];
    await exportExcel({
      audit: { conditions: params },
      columns: [
        { title: tl('date'), width: 20 },
        { title: tl('email_gateway_sender'), width: 28 },
        { title: tl('agent_account'), width: 25 },
        { title: tl('member_account'), width: 25 },
        { title: tl('email'), width: 28 },
        { title: tl('content'), width: 50 },
        { title: tl('status'), width: 15 },
      ],
      dateRange: [start, end],
      rows: rows.map((r) => [
        fromApiTime(r.created_at),
        r.sender,
        r.agent_username,
        r.member_username,
        r.receiver,
        r.message_content,
        statusText(r.status),
      ]),
      title: tl('menu_email_gateway_record'),
    });
  } finally {
    exporting.value = false;
  }
}

const modalVisible = ref(false);
const modalContent = ref('');
const modalLoading = ref(false);

/** 開關關閉時列表不回傳內容，需另外向後端索取（後端會寫入代理查看紀錄） */
async function viewContent(row: EmailGatewayRecordRow) {
  modalVisible.value = true;

  if (contentVisible.value) {
    modalContent.value = row.message_content ?? '';
    return;
  }

  modalContent.value = '';
  modalLoading.value = true;
  try {
    const res = await getEmailGatewayRecordContentApi(row.id).catch(() => null);
    modalContent.value =
      res && Number(res.code) === 200 ? (res.data?.message_content ?? '') : '';
  } finally {
    modalLoading.value = false;
  }
}

onMounted(async () => {
  // 列表不等下拉與設定，三支並行
  loadData();

  const [groups, settings] = await Promise.all([
    getEmailGatewayGroupDropdownApi().catch(() => null),
    getCustomizedSettingApi().catch(() => []),
  ]);
  groupOptions.value =
    groups && Number(groups.code) === 200 ? (groups.data ?? []) : [];
  const s5012 = (settings ?? []).find((x) => Number(x.setting_key) === 5012);
  contentVisible.value = s5012 ? Number(s5012.value) === 1 : true;
});
</script>

<template>
  <Page
    :title="`${tl('menu_email_management')} > ${tl('menu_email_gateway_record')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-3">
          <ElFormItem :label="tl('agent_group_type_7')">
            <ElSelect
              v-model="filters.agent_group_id"
              class="!w-full"
              clearable
              filterable
            >
              <ElOption :label="tl('all')" value="" />
              <ElOption
                v-for="g in groupOptions"
                :key="g.id"
                :label="g.name"
                :value="g.id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('agent_account')">
            <ElInput v-model="filters.agent_username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('member_account')">
            <ElInput v-model="filters.member_username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('status')">
            <ElSelect v-model="filters.status" class="!w-full" clearable>
              <ElOption :label="tl('all')" value="" />
              <ElOption :label="tl('sent_successfully')" :value="1" />
              <ElOption :label="tl('failed_to_send')" :value="2" />
              <ElOption :label="tl('unsent')" :value="0" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('email')">
            <ElInput v-model="filters.receiver" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('send_id')">
            <ElInput v-model="filters.send_id" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('between')" class="md:col-span-2">
            <DateRangeFields
              v-model="filters.time_range"
              format="YYYY-MM-DD HH:mm"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm"
            />
          </ElFormItem>
        </div>
        <div class="flex justify-end gap-2">
          <ElButton :loading="exporting" type="success" @click="handleExport">
            {{ tl('excel') }}
          </ElButton>
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn :label="tl('send_id')" min-width="90" prop="id" />
        <ElTableColumn
          :formatter="
            (row: EmailGatewayRecordRow) => fromApiTime(row.created_at)
          "
          :label="tl('date')"
          min-width="160"
        />
        <ElTableColumn
          :label="tl('email_gateway_sender')"
          min-width="180"
          prop="sender"
        />
        <ElTableColumn
          :label="tl('agent_account')"
          min-width="120"
          prop="agent_username"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('member_account')" min-width="120">
          <template #default="{ row }">
            <MemberAccountLink :username="row.member_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('email')" min-width="180">
          <template #default="{ row }">
            <div class="break-all">{{ row.receiver }}</div>
            <div class="text-muted-foreground text-xs">{{ row.subject }}</div>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('content')" min-width="200">
          <template #default="{ row }">
            <div
              v-if="contentVisible"
              :title="row.message_content"
              class="line-clamp-2 cursor-pointer break-all"
              @click="viewContent(row as EmailGatewayRecordRow)"
            >
              {{ row.message_content }}
            </div>
            <ElButton
              v-else
              size="small"
              type="primary"
              @click="viewContent(row as EmailGatewayRecordRow)"
            >
              {{ tl('watch_data') }}
            </ElButton>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('status')" min-width="140">
          <template #default="{ row }">
            <span
              :class="Number(row.status) === 1 ? '' : 'text-red-500 font-bold'"
            >
              {{ statusText(row.status) }}
            </span>
            <div
              v-if="row.response_message"
              :title="row.response_message"
              class="text-muted-foreground line-clamp-2 text-xs"
            >
              {{ row.response_message }}
            </div>
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

    <ElDialog v-model="modalVisible" :title="tl('content')" width="640">
      <div
        v-loading="modalLoading"
        class="max-h-80 min-h-16 overflow-y-auto break-all whitespace-pre-wrap"
      >
        {{ modalContent }}
      </div>

      <template #footer>
        <ElButton @click="modalVisible = false">{{ tl('close') }}</ElButton>
      </template>
    </ElDialog>
  </Page>
</template>
