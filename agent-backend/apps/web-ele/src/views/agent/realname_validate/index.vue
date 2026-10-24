<script lang="ts" setup>
import type { AgentRealnameValidateRow } from '#/api';

import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElDescriptions,
  ElDescriptionsItem,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  getAgentRealnameValidateListApi,
  getAgentRealnameValidateViewApi,
  getCustomizedSettingApi,
  reviewAgentRealnameValidateApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useExcelExport } from '#/composables/use-excel-export';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { fromApiTime, nowInUserTz, toApiTime } from '#/utils/timezone-shift';

defineOptions({ name: 'AgentRealnameValidate' });

const PER_PAGE = 30;

const route = useRoute();

const filters = reactive({
  agent_username: '',
  bank_account: '',
  // 由通知鈴鐺進來時帶 status（待審核=0），否則預設全部（target/tickets#4193）
  status: (route.query.status as string) ?? ('-1' as number | string),
  time_range: [
    nowInUserTz().format('YYYY-MM-DD 00:00'),
    nowInUserTz().format('YYYY-MM-DD 23:59'),
  ] as string[],
});

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<AgentRealnameValidateRow>(
    ({ page, per_page }) => {
      const [start, end] = filters.time_range ?? [];
      return getAgentRealnameValidateListApi({
        agent_username: filters.agent_username,
        bank_account: filters.bank_account,
        end_time: toApiTime(end ?? ''),
        page,
        per_page,
        start_time: toApiTime(start ?? ''),
        status: filters.status,
      });
    },
    { perPage: PER_PAGE, showError: true },
  );

/** 代理設定：4011 啟用 BSB Code、3015 BSB 別名 */
const enableBsb = ref(false);
const bsbAlias = ref('BSB Code');

const accountNoLabel = computed(() =>
  enableBsb.value
    ? `${bsbAlias.value} / ${tl('account_no')}`
    : tl('account_no'),
);

function statusText(s?: number) {
  if (Number(s) === 0) return tl('pending');
  if (Number(s) === 1) return tl('pass');
  if (Number(s) === 2) return tl('no_pass');
  return '-';
}

/* ---- 審核彈窗 ---- */
const reviewVisible = ref(false);
const reviewLoading = ref(false);
const saving = ref(false);
const record = ref<AgentRealnameValidateRow | null>(null);
const remark = ref('');
const rowStatus = ref(0);

async function openReview(row: AgentRealnameValidateRow) {
  reviewVisible.value = true;
  reviewLoading.value = true;
  record.value = null;
  remark.value = '';
  rowStatus.value = Number(row.status ?? 0);
  try {
    record.value = (await getAgentRealnameValidateViewApi(row.id)) ?? null;
  } finally {
    reviewLoading.value = false;
  }
}

/** 審核（1 通過 / 2 拒絕） */
async function review(status: number) {
  if (!record.value) return;
  saving.value = true;
  try {
    const res = await reviewAgentRealnameValidateApi({
      id: record.value.id,
      remark: remark.value,
      status,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(res.message);
      reviewVisible.value = false;
      loadData();
    } else {
      // 2002 顯示 data，其餘顯示 message
      ElMessage.error(
        Number(res.code) === 2002 ? String(res.data) : res.message,
      );
    }
  } finally {
    saving.value = false;
  }
}

// 匯出 Excel（對齊舊版 realname_validate_ajax.php 的欄位）
const { exporting, runExport } = useExcelExport();
function handleExport() {
  const [start, end] = filters.time_range ?? [];
  const params = {
    agent_username: filters.agent_username,
    bank_account: filters.bank_account,
    end_time: toApiTime(end ?? ''),
    page: 1,
    per_page: 100_000,
    start_time: toApiTime(start ?? ''),
    status: filters.status,
  };
  return runExport(
    () => getAgentRealnameValidateListApi(params),
    `realname_validate_${Date.now()}`,
    [
      tl('created_time'),
      tl('num'),
      tl('username'),
      tl('realname'),
      tl('account_name'),
      tl('account_no'),
      tl('memo'),
      tl('status'),
    ],
    (row: AgentRealnameValidateRow) => [
      row.created_at,
      row.id,
      row.username,
      row.real_name,
      row.bank_name,
      row.bank_account,
      row.remark,
      statusText(row.status),
    ],
    {
      audit: { conditions: params },
      dateRange: [start ?? '', end ?? ''],
      title: tl('menu_agent_real_name_validate'),
    },
  );
}

// 頁面載入即查詢
onMounted(() => {
  loadData();
  getCustomizedSettingApi()
    .then((settings) => {
      const map = new Map(
        settings.map((s) => [Number(s.setting_key), s.value]),
      );
      enableBsb.value = Number(map.get(4011)) === 1;
      bsbAlias.value = (map.get(3015) as string) || 'BSB Code';
    })
    .catch(() => {});
});
</script>

<template>
  <Page
    :title="`${tl('agnet_management')} > ${tl('menu_agent_real_name_validate')}`"
  >
    <!-- 查詢 -->
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('agent_account')">
            <ElInput v-model="filters.agent_username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('account_no')">
            <ElInput v-model="filters.bank_account" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('between')">
            <DateRangeFields
              v-model="filters.time_range"
              format="YYYY-MM-DD HH:mm"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm"
            />
          </ElFormItem>
          <ElFormItem :label="tl('status')">
            <ElSelect v-model="filters.status" class="w-full">
              <ElOption :label="tl('all')" value="-1" />
              <ElOption :label="tl('pending_review')" value="0" />
              <ElOption :label="tl('pass')" value="1" />
              <ElOption :label="tl('rejected')" value="2" />
            </ElSelect>
          </ElFormItem>
        </div>
        <div class="flex flex-wrap items-center justify-end gap-2">
          <ElButton :loading="exporting" type="success" @click="handleExport">
            {{ tl('excel') }}
          </ElButton>
          <ElButton :loading="loading" type="primary" @click="search">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <!-- 列表 -->
    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn
          :label="tl('created_time')"
          :formatter="
            (row: AgentRealnameValidateRow) => fromApiTime(row.created_at)
          "
          width="160"
        />
        <ElTableColumn :label="tl('num')" prop="id" width="80" />
        <ElTableColumn
          :label="tl('username')"
          prop="username"
          min-width="110"
        />
        <ElTableColumn
          :label="tl('real_name')"
          prop="real_name"
          min-width="100"
        />
        <ElTableColumn
          :label="tl('account_name')"
          prop="bank_name"
          min-width="110"
        />
        <ElTableColumn :label="accountNoLabel" min-width="130">
          <template #default="{ row }">
            <div v-if="enableBsb">
              <div>{{ row.bsb_code }}</div>
              <div>{{ row.bank_account }}</div>
            </div>
            <span v-else>{{ row.bank_account }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('operating_time')" width="160">
          <template #default="{ row }">
            {{ Number(row.status) === 0 ? '-' : row.updated_at }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('memo')" prop="remark" min-width="130" />
        <ElTableColumn :label="tl('status')" width="100">
          <template #default="{ row }">{{ statusText(row.status) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('operator')" prop="operator" min-width="110">
          <template #default="{ row }">
            <AgentAccount :username="row.operator" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          align="center"
          width="100"
          :label="tl('action')"
          fixed="right"
        >
          <template #default="{ row }">
            <ElButton
              v-if="Number(row.status) === 0"
              size="small"
              type="primary"
              @click="openReview(row as AgentRealnameValidateRow)"
            >
              {{ tl('review') }}
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

    <!-- 審核彈窗 -->
    <ElDialog
      v-model="reviewVisible"
      :title="tl('detail')"
      width="700"
      align-center
    >
      <ElDescriptions v-loading="reviewLoading" :column="2" border>
        <ElDescriptionsItem :label="tl('num')">
          {{ record?.id ?? '-' }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('username')">
          {{ record?.username ?? '-' }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('realname')">
          {{ record?.real_name ?? '-' }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('account_name')">
          {{ record?.bank_name ?? '-' }}
        </ElDescriptionsItem>
        <ElDescriptionsItem v-if="enableBsb" :label="bsbAlias">
          {{ record?.bsb_code ?? '-' }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('account_no')">
          {{ record?.bank_account ?? '-' }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('memo')" :span="2">
          <ElInput
            v-if="rowStatus === 0"
            v-model="remark"
            :rows="3"
            type="textarea"
          />
          <span v-else>{{ record?.remark }}</span>
        </ElDescriptionsItem>
      </ElDescriptions>
      <template #footer>
        <template v-if="rowStatus === 0">
          <ElButton :loading="saving" type="success" @click="review(1)">
            {{ tl('pass') }}
          </ElButton>
          <ElButton :loading="saving" type="danger" @click="review(2)">
            {{ tl('reject') }}
          </ElButton>
        </template>
      </template>
    </ElDialog>
  </Page>
</template>
