<script lang="ts" setup>
import type { SmsDropdownRow, SmsRecordRow } from '#/api';

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
  getSmsContentApi,
  getSmsDropdownApi,
  getSmsRecordApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { hasMenuCode } from '#/router/agent-menu';
import { exportExcel } from '#/utils/export-excel';
import { sixTypesQuickRange } from '#/utils/quick-range';
import { fromApiTime, toApiTime } from '#/utils/timezone-shift';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'SmsRecord' });

const PER_PAGE = 20;

/** 匯出權限（target/tickets#4302）：純權限旗標，不是頁面，只控制這顆按鈕 */
const canExport = hasMenuCode('sms_record_export');

const gateways = ref<SmsDropdownRow[]>([]);
const contentVisible = ref(true); // 設定 5011：1=直接顯示內容
const filters = reactive({
  sms_gateway_id: '' as number | string,
  agent_username: '',
  member_username: '',
  status: '' as number | string,
  mobile: '',
  send_id: '',
  time_range: sixTypesQuickRange('today') as [string, string],
});

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<SmsRecordRow>(
    ({ page, per_page }) => {
      const [start, end] = filters.time_range ?? [];
      return getSmsRecordApi({
        agent_username: filters.agent_username,
        end_time: toApiTime(end),
        member_username: filters.member_username,
        mobile: filters.mobile,
        page,
        per_page,
        send_id: filters.send_id,
        sms_gateway_id: filters.sms_gateway_id || 0,
        start_time: toApiTime(start),
        status: filters.status,
      });
    },
    { perPage: PER_PAGE, showError: true },
  );

const exporting = ref(false);
/** 匯出 Excel：欄位（8 欄；無 send_id 欄） */
async function handleExport() {
  exporting.value = true;
  try {
    const [start, end] = filters.time_range ?? ['', ''];
    const params = {
      agent_username: filters.agent_username,
      end_time: toApiTime(end),
      member_username: filters.member_username,
      mobile: filters.mobile,
      page: 1,
      per_page: 100_000,
      send_id: filters.send_id,
      sms_gateway_id: filters.sms_gateway_id || 0,
      start_time: toApiTime(start),
      status: filters.status,
    };
    const res = await getSmsRecordApi(params);
    const rows = Number(res.code) === 1000 ? (res.data ?? []) : [];
    await exportExcel({
      audit: { conditions: params },
      columns: [
        { title: tl('date'), width: 20 },
        { title: tl('sms_gateway_name'), width: 25 },
        { title: tl('agent_account'), width: 25 },
        { title: tl('member_account'), width: 25 },
        { title: tl('mobile'), width: 15 },
        { title: tl('content'), width: 50 },
        { title: tl('transaction_id'), width: 20 },
        { title: tl('status'), width: 15 },
      ],
      dateRange: [start, end],
      rows: rows.map((r) => [
        fromApiTime(r.created_at),
        r.sms_gateway_name,
        r.agent_username,
        r.username,
        r.mobile,
        r.message_content,
        r.transaction_id,
        Number(r.status) === 1 ? tl('status_1') : tl('status_0'),
      ]),
      title: tl('sms_record'),
    });
  } finally {
    exporting.value = false;
  }
}

// 查看簡訊內容 modal
const modalVisible = ref(false);
const modalSendId = ref<number | string>('');
const modalContent = ref('');

async function viewContent(row: SmsRecordRow) {
  modalSendId.value = row.id;
  modalContent.value = '';
  modalVisible.value = true;
  const res = await getSmsContentApi(row.id).catch(() => null);
  modalContent.value = res?.message_content ?? '';
}

onMounted(async () => {
  const [gw, settings] = await Promise.all([
    getSmsDropdownApi().catch(() => []),
    getCustomizedSettingApi().catch(() => []),
  ]);
  gateways.value = gw ?? [];
  const s5011 = (settings ?? []).find((x) => Number(x.setting_key) === 5011);
  contentVisible.value = s5011 ? Number(s5011.value) === 1 : true;
  loadData();
});
</script>

<template>
  <Page :title="`${tl('sms_management')} > ${tl('sms_record')}`">
    <ElCard class="mb-3" shadow="never">
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-3">
          <ElFormItem :label="tl('sms_gateway_name')">
            <ElSelect
              v-model="filters.sms_gateway_id"
              class="!w-full"
              clearable
            >
              <ElOption :label="tl('all')" value="" />
              <ElOption
                v-for="g in gateways"
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
              <ElOption :label="tl('sent_successfully')" value="1" />
              <ElOption :label="tl('failed_to_send')" value="2" />
              <ElOption :label="tl('unsent')" value="0" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('mobile')">
            <ElInput v-model="filters.mobile" clearable />
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
        <div class="flex items-center justify-end">
          <div class="flex gap-2">
            <ElButton
              v-if="canExport"
              :loading="exporting"
              type="success"
              @click="handleExport"
            >
              {{ tl('excel') }}
            </ElButton>
            <ElButton :loading="loading" native-type="submit" type="primary">
              {{ tl('search') }}
            </ElButton>
          </div>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn :label="tl('send_id')" min-width="90" prop="id" />
        <ElTableColumn
          :formatter="(row: SmsRecordRow) => fromApiTime(row.created_at)"
          :label="tl('date')"
          min-width="160"
        />
        <ElTableColumn
          :label="tl('sms_gateway_name')"
          min-width="120"
          prop="sms_gateway_name"
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
            <MemberAccountLink :username="row.username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('mobile')" min-width="120" prop="mobile" />
        <ElTableColumn :label="tl('content')" min-width="200">
          <template #default="{ row }">
            <span v-if="contentVisible" class="whitespace-pre-wrap">
              {{ row.message_content }}
            </span>
            <ElButton
              v-else
              size="small"
              type="primary"
              @click="viewContent(row as SmsRecordRow)"
            >
              {{ tl('watch_data') }}
            </ElButton>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('transaction_id')"
          min-width="140"
          prop="transaction_id"
        />
        <ElTableColumn :label="tl('status')" min-width="100">
          <template #default="{ row }">
            <span
              :class="Number(row.status) === 1 ? '' : 'text-red-500 font-bold'"
            >
              {{ Number(row.status) === 1 ? tl('status_1') : tl('status_0') }}
            </span>
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

    <ElDialog v-model="modalVisible" :title="tl('detail')" width="480">
      <div>{{ tl('send_id') }}: {{ modalSendId }}</div>
      <div class="mt-2 whitespace-pre-wrap">
        {{ tl('content') }}: {{ modalContent }}
      </div>
      <template #footer>
        <ElButton @click="modalVisible = false">{{ tl('close') }}</ElButton>
      </template>
    </ElDialog>
  </Page>
</template>
