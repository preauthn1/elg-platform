<script lang="ts" setup>
import type { AutoDepositRow } from '#/api';

import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getAutoDepositApi, getCustomizedSettingApi } from '#/api';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useExcelExport } from '#/composables/use-excel-export';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';
import { fromApiTime, nowInUserTz, toApiTime } from '#/utils/timezone-shift';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

import OrderReviewDialog from './components/order-review-dialog.vue';

defineOptions({ name: 'OrderIndex' });

const PER_PAGE = 50;

const route = useRoute();
const reviewRef = ref<InstanceType<typeof OrderReviewDialog>>();

/** 代理設定：4011 啟用 BSB Code、3015 BSB 別名 */
const enableBsb = ref(false);
const bsbAlias = ref('BSB Code');

/** 帳號搜尋類型 → API 參數名 */
const TYPE_PARAM: Record<number, string> = {
  1: 'order_no',
  2: 'member_username',
  3: 'account_no',
};

function defaultFilters() {
  return {
    status: -1 as number | string,
    time_range: [
      nowInUserTz().subtract(24, 'hour').format('YYYY-MM-DD 00:00'),
      nowInUserTz().format('YYYY-MM-DD 23:59'),
    ] as string[],
    type: 1,
    username: '',
  };
}

const filters = reactive(defaultFilters());

/** 查看下線：route query 帶入的代理 id */
const drillId = ref<number | string | undefined>();

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<AutoDepositRow>(
    ({ page, per_page }) => {
      const [start, end] = filters.time_range ?? [];
      const params: Record<string, unknown> = {
        end_time: toApiTime(end),
        page,
        per_page,
        // status 一定要送字串：後端 `$request->status != ''` 弱型別比較，
        // 送數字 0（訂單建立）會被誤判成沒帶值，篩選會失效
        status: String(filters.status),
        start_time: toApiTime(start),
        [TYPE_PARAM[filters.type] ?? '']: filters.username,
      };
      if (drillId.value !== undefined) {
        params.id = drillId.value;
      }
      return getAutoDepositApi(params);
    },
    {
      guard: () => {
        const [start, end] = filters.time_range ?? [];
        if (!start || !end) {
          ElMessage.warning(tl('between'));
          return false;
        }
        return true;
      },
      perPage: PER_PAGE,
      showError: true,
    },
  );

const fmtAmount = (v?: null | number | string) => amountThousands(v, 2);

const accountNoLabel = computed(() =>
  enableBsb.value ? `${bsbAlias.value}/${tl('account_no')}` : tl('account_no'),
);

/** 狀態配色（target/tickets#4164）：拒絕紅、通過綠，其餘不上色 */
function statusClass(status?: number) {
  const s = Number(status);
  if (s === 2) return 'text-red-500';
  if (s !== 0 && s !== 1) return 'text-green-600';
  return '';
}
function statusText(status?: number) {
  switch (Number(status)) {
    case 0: {
      return tl('order_create');
    }
    case 1: {
      return tl('pending_review');
    }
    case 2: {
      return tl('rejected');
    }
    default: {
      return tl('deposit_success');
    }
  }
}

/** remark 為 JSON 字串，取其中 message */
function remarkMessage(raw?: string) {
  if (!raw) return '';
  try {
    return JSON.parse(raw)?.message ?? '';
  } catch {
    return raw;
  }
}

function handleClear() {
  Object.assign(filters, defaultFilters());
  page.value = 1;
  loadData();
}

const { exporting, runExport } = useExcelExport();
/** 匯出 Excel：欄位對齊舊版 order/ajax.php（9 欄，無備註欄） */
async function handleExport() {
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) {
    ElMessage.warning(tl('between'));
    return;
  }
  const params: Record<string, unknown> = {
    end_time: toApiTime(end),
    page: 1,
    per_page: 100_000,
    start_time: toApiTime(start),
    status: String(filters.status),
    [TYPE_PARAM[filters.type] ?? '']: filters.username,
  };
  if (drillId.value !== undefined) {
    params.id = drillId.value;
  }
  await runExport(
    () => getAutoDepositApi(params),
    `order_review_${Date.now()}`,
    [
      tl('date'),
      tl('ordernum'),
      tl('bank_name'),
      tl('bank_code'),
      tl('account_name'),
      tl('account_no'),
      tl('member_account'),
      tl('amount'),
      tl('order_status'),
    ],
    (r: AutoDepositRow) => [
      r.create_at,
      r.order_no,
      r.bank_name,
      r.bank_code,
      r.account_name,
      r.account_no,
      r.username,
      fmtAmount(r.amount),
      statusText(r.status),
    ],
    { audit: { conditions: params }, title: tl('menu_order_review') },
  );
}

onMounted(() => {
  if (route.query.id) {
    drillId.value = route.query.id as string;
  }
  // 由通知鈴鐺進來時帶 status（待審核=1），未帶則維持預設全部（target/tickets#4193）
  if (route.query.status !== undefined) {
    filters.status = Number(route.query.status);
  }
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
  <Page :title="`${tl('menu_auto_deposit')} > ${tl('menu_order_review')}`">
    <!-- 查詢 -->
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2">
          <ElFormItem>
            <template #label>
              <ElSelect v-model="filters.type" class="!w-40">
                <ElOption :label="tl('ordernumber')" :value="1" />
                <ElOption :label="tl('member_account')" :value="2" />
                <ElOption :label="tl('account_no')" :value="3" />
              </ElSelect>
            </template>
            <ElInput v-model="filters.username" clearable />
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
              <ElOption :label="tl('all')" :value="-1" />
              <ElOption :label="tl('order_create')" :value="0" />
              <ElOption :label="tl('pending_review')" :value="1" />
              <ElOption :label="tl('rejected')" :value="2" />
              <ElOption :label="tl('deposit_success')" :value="3" />
            </ElSelect>
          </ElFormItem>
        </div>
        <div class="flex flex-wrap items-center justify-end gap-2">
          <div>
            <ElButton :loading="exporting" type="success" @click="handleExport">
              {{ tl('excel') }}
            </ElButton>
            <ElButton :loading="loading" native-type="submit" type="primary">
              {{ tl('search') }}
            </ElButton>
            <ElButton @click="handleClear">{{ tl('clear') }}</ElButton>
          </div>
        </div>
      </ElForm>
    </ElCard>

    <!-- 列表 -->
    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn
          :formatter="(row: AutoDepositRow) => fromApiTime(row.create_at)"
          :label="tl('created_time')"
          width="160"
        />
        <ElTableColumn
          :label="tl('ordernum')"
          prop="order_no"
          min-width="150"
        />
        <ElTableColumn :label="tl('bank_name')" min-width="120">
          <template #default="{ row }">
            <span class="font-bold text-green-600">{{ row.bank_name }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('bank_code')" prop="bank_code" width="100" />
        <ElTableColumn
          :label="tl('account_name')"
          prop="account_name"
          min-width="110"
        />
        <ElTableColumn :label="accountNoLabel" min-width="130">
          <template #default="{ row }">
            <template v-if="enableBsb">{{ row.bsb_code }}<br /></template>
            {{ row.account_no }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('member_account')" min-width="110">
          <template #default="{ row }">
            <MemberAccountLink :username="row.username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('amount')" align="right" width="110">
          <template #default="{ row }">{{ fmtAmount(row.amount) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('memo')" min-width="140">
          <template #default="{ row }">
            {{ remarkMessage(row.remark) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('order_status')" align="center" width="100">
          <template #default="{ row }">
            <span :class="statusClass(row.status)">
              {{ statusText(row.status) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn align="center" width="100" fixed="right">
          <template #default="{ row }">
            <!-- 訂單資訊按鈕顏色與審核一致（target/tickets#4164） -->
            <ElButton
              type="primary"
              size="small"
              @click="reviewRef?.open(row.order_id)"
            >
              {{ Number(row.status) === 1 ? tl('review') : tl('order_info') }}
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

    <OrderReviewDialog
      ref="reviewRef"
      :bsb-alias="bsbAlias"
      :enable-bsb="enableBsb"
      @saved="loadData"
    />
  </Page>
</template>
