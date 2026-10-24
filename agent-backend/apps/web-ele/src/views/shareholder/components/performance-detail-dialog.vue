<script lang="ts" setup>
import type { ShareholderPerfDetailRow } from '#/api';

import { computed, ref, watch } from 'vue';

import { ElDialog, ElTable, ElTableColumn } from 'element-plus';

import {
  getShareholderPerformanceDetailApi,
  getShareholderPerformanceDirectDetailApi,
} from '#/api';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'ShareholderPerformanceDetailDialog' });

const props = defineProps<{
  agentId: number | string;
  endTime: string;
  /** 'detail'=彙總明細、'direct'=直屬明細 */
  mode: 'detail' | 'direct';
  shareholderCategoryId: number | string;
  shareholderCategoryName: string;
  startTime: string;
  variant2?: boolean;
}>();
const visible = defineModel<boolean>({ default: false });

const loading = ref(false);
const rows = ref<ShareholderPerfDetailRow[]>([]);

const isSettlement = computed(() => Number(props.shareholderCategoryId) === 1);
const dateText = computed(
  () =>
    `${(props.startTime || '').split(' ')[0]} - ${(props.endTime || '').split(' ')[0]}`,
);
const valueLabel = computed(() => {
  if (props.variant2) return tl('total_bet_amount2');
  return isSettlement.value ? tl('company_expenses') : tl('turnover');
});
/** detail 模式是否顯示遊戲平台欄（variant2 永遠顯示；variant1 非結算才顯示）。 */
const showVendorCol = computed(() => props.variant2 || !isSettlement.value);

async function load() {
  loading.value = true;
  try {
    const params = {
      agent_id: props.agentId,
      end_time: props.endTime,
      shareholder_category_id: props.shareholderCategoryId,
      start_time: props.startTime,
    };
    if (props.mode === 'detail') {
      const res = await getShareholderPerformanceDetailApi(
        params,
        props.variant2,
      );
      rows.value =
        Number(res.code) === 1000 ? (res.data?.summary_detail ?? []) : [];
    } else {
      const res = await getShareholderPerformanceDirectDetailApi(
        params,
        props.variant2,
      );
      rows.value = Number(res.code) === 1000 ? (res.data?.detail ?? []) : [];
    }
  } finally {
    loading.value = false;
  }
}

/** detail 模式金額：variant2 用 turnover；variant1 settlement 用 game_profit、否則 turnover；direct 模式用 bet。 */
function amountOf(row: ShareholderPerfDetailRow) {
  if (props.mode === 'direct') return row.bet;
  if (props.variant2) return row.turnover;
  return isSettlement.value ? row.game_profit : row.turnover;
}

watch(visible, (v) => {
  if (v) load();
});
</script>

<template>
  <ElDialog v-model="visible" width="950px">
    <template #header>
      <div class="flex flex-wrap gap-6">
        <span>
          {{ tl('date') }}:
          <b class="font-mono">{{ dateText }}</b>
        </span>
        <span>
          {{ tl('gaming_platform_group') }}:
          <b class="font-mono">{{ shareholderCategoryName }}</b>
        </span>
      </div>
    </template>
    <ElTable v-loading="loading" :data="rows" border size="small" stripe>
      <ElTableColumn
        v-if="mode === 'direct'"
        :label="tl('username')"
        min-width="120"
      >
        <template #default="{ row }">
          <MemberAccountLink :username="row.username" />
        </template>
      </ElTableColumn>
      <ElTableColumn
        v-if="mode === 'detail' && showVendorCol"
        :label="tl('gaming_platform')"
        prop="vendor_name"
        min-width="120"
      />
      <ElTableColumn
        v-if="mode === 'direct'"
        :label="tl('vendor')"
        prop="vendor_name"
        min-width="120"
      />
      <ElTableColumn :label="tl('type')" min-width="120">
        <template #default="{ row }">
          {{ mode === 'direct' ? row.game_type_name : row.type_name }}
        </template>
      </ElTableColumn>
      <ElTableColumn :label="valueLabel" align="right" min-width="120">
        <template #default="{ row }">
          <span
            :class="
              Number(amountOf(row as ShareholderPerfDetailRow)) < 0
                ? 'text-red-500'
                : ''
            "
          >
            {{ amountThousands(amountOf(row as ShareholderPerfDetailRow), 2) }}
          </span>
        </template>
      </ElTableColumn>
      <template #empty>{{ tl('no_record') }}</template>
    </ElTable>
  </ElDialog>
</template>
