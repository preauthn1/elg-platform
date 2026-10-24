<script lang="ts" setup>
import type { WinLoseRankingPath, WinLoseRankingRow } from '#/api';

import { computed, onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElMessage,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getVendorListApi, getWinLoseRankingApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useClientTable } from '#/composables/use-client-table';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import { amountThousands, buildPlainAmounts } from '#/utils/format';
import { nowInUserTz, toApiTime } from '#/utils/timezone-shift';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

import WinLoseDetailDialog from './win-lose-detail-dialog.vue';

defineOptions({ name: 'WinLoseRankingPage' });

const props = defineProps<{
  /** member=玩家 / agent=代理 / team=團隊 輸贏排行報表 */
  variant: WinLoseRankingPath;
}>();

const PER_PAGE = 20;
/** 不支援的平台（SR12_NO_SUPPORT） */
const NO_SUPPORT = new Set([3, 10, 23]);

const titleKey = computed(() => `${props.variant}_win_lose_ranking`);

const vendors = ref<Array<{ id: number; name: string }>>([]);

const filters = reactive({
  time_range: [
    nowInUserTz().format('YYYY-MM-DD 00:00'),
    nowInUserTz().format('YYYY-MM-DD 23:59'),
  ] as string[],
  type: 1 as number | string,
  vendor_id: 0 as number | string,
});

const loading = ref(false);
const list = ref<WinLoseRankingRow[]>([]);
/** 是否已查詢過（對齊舊版：進頁不自動查，按查詢才顯示結果） */
const searched = ref(false);

const detailRef = ref<InstanceType<typeof WinLoseDetailDialog>>();

const fmt = (v?: number | string) => amountThousands(v, 2);

function buildParams() {
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) {
    ElMessage.warning(tl('between'));
    return null;
  }
  return {
    end_time: toApiTime(`${end}:59`),
    start_time: toApiTime(`${start}:00`),
    type: filters.type,
    vendor_id: filters.vendor_id,
  };
}

const { handlePageChange, page, pagedRows, total } = useClientTable(list, {
  perPage: PER_PAGE,
  searchFields: (r) => [r.agent_username, r.username],
});

async function handleSearch() {
  const params = buildParams();
  if (!params) return;
  loading.value = true;
  searched.value = true;
  page.value = 1;
  try {
    const res = await getWinLoseRankingApi(props.variant, params);
    if (Number(res.code) === 1000) {
      list.value = res.data ?? [];
    } else {
      list.value = [];
      ElMessage.warning(res.message);
    }
  } finally {
    loading.value = false;
  }
}

function openDetail(row: WinLoseRankingRow) {
  const params = buildParams();
  if (!params) return;
  const id = props.variant === 'member' ? row.member_id : row.agent_id;
  detailRef.value?.open(
    props.variant,
    { id: id ?? 0, username: row.username ?? '' },
    params,
  );
}

const exporting = ref(false);
/** 匯出 Excel（欄位對齊舊版 ajax action={variant}_win_lose_ranking） */
async function handleExport() {
  const params = buildParams();
  if (!params) return;
  exporting.value = true;
  try {
    const res = await getWinLoseRankingApi(props.variant, params);
    const rows = Number(res.code) === 1000 ? (res.data ?? []) : [];
    const isMember = props.variant === 'member';
    await buildPlainAmounts(() =>
      exportExcel(
        `${props.variant}_win_lose_ranking_${Date.now()}`,
        [
          ...(isMember
            ? [tl('agent_account'), tl('member_account')]
            : [tl('agent_account')]),
          tl('total_bet'),
          tl('total_win_lose'),
        ],
        rows.map((r) => [
          ...(isMember
            ? [r.agent_username ?? '', r.username ?? '']
            : [r.username ?? '']),
          fmt(r.bet_amount),
          fmt(r.win_lose_amount),
        ]),
        {
          audit: { conditions: params },
          dateRange: filters.time_range as [string, string],
          title: tl(titleKey.value),
        },
      ),
    );
  } finally {
    exporting.value = false;
  }
}

onMounted(() => {
  getVendorListApi()
    .then((d) => {
      vendors.value = (d ?? []).filter((v) => !NO_SUPPORT.has(Number(v.id)));
    })
    .catch(() => {});
});
</script>

<template>
  <Page :title="`${tl('statistical_report')} > ${tl(titleKey)}`">
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="handleSearch">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-3">
          <ElFormItem :label="tl('type')">
            <ElSelect v-model="filters.type" class="!w-full">
              <ElOption :label="tl('win_point')" :value="1" />
              <ElOption :label="tl('lose_point')" :value="2" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('platform')">
            <ElSelect v-model="filters.vendor_id" class="!w-full" filterable>
              <ElOption :label="tl('all')" :value="0" />
              <ElOption
                v-for="v in vendors"
                :key="v.id"
                :label="v.name"
                :value="v.id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('between')">
            <DateRangeFields
              v-model="filters.time_range"
              format="YYYY-MM-DD HH:mm"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm"
            />
          </ElFormItem>
        </div>
        <div class="flex flex-wrap items-center justify-end gap-2">
          <ElButton :loading="exporting" type="success" @click="handleExport">
            {{ tl('excel') }}
          </ElButton>
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard v-if="searched" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('detaillist') }}</span>
      </template>
      <ElTable v-loading="loading" :data="pagedRows" border size="small" stripe>
        <ElTableColumn
          v-if="variant === 'member'"
          :label="tl('agent_account')"
          min-width="110"
          prop="agent_username"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="
            variant === 'member' ? tl('member_account') : tl('agent_account')
          "
          min-width="110"
        >
          <template #default="{ row }">
            <!-- 工單4274：只有玩家排行是會員帳號可點；代理／團隊排行維持純文字 -->
            <MemberAccountLink
              v-if="variant === 'member'"
              :member-id="row.member_id"
              :username="row.username"
            />
            <AgentAccount v-else :username="row.username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('total_bet')" align="right" min-width="120">
          <template #default="{ row }">
            <span
              :class="
                Number(row.bet_amount) < 0 ? 'text-red-500' : 'text-blue-500'
              "
            >
              {{ fmt(row.bet_amount) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('total_win_lose')"
          align="right"
          min-width="120"
        >
          <template #default="{ row }">
            <span
              :class="
                Number(row.win_lose_amount) < 0
                  ? 'text-red-500'
                  : 'text-blue-500'
              "
            >
              {{ fmt(row.win_lose_amount) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('features')"
          align="center"
          width="90"
          fixed="right"
        >
          <template #default="{ row }">
            <ElButton
              size="small"
              @click="openDetail(row as WinLoseRankingRow)"
            >
              {{ tl('detail') }}
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

    <WinLoseDetailDialog ref="detailRef" />
  </Page>
</template>
