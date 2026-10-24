<script lang="ts" setup>
import type { GgrSettlementRow } from '#/api';

import { computed, ref } from 'vue';

import { ElButton, ElDialog, ElMessage } from 'element-plus';

import { getGgrSettlementViewApi } from '#/api';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';

/**
 * 4125「計算」欄詳情：單筆結算的公式代入值＋計算明細快照。
 * calc_snapshot 由結算引擎批次寫入、結構屆時定案，這裡先以
 * 格式化 JSON 呈現（客服對帳看得到原始輸入即可）。
 */

const visible = ref(false);
const loading = ref(false);
const detail = ref<GgrSettlementRow | null>(null);

const fmt = (v?: null | number | string) => amountThousands(v ?? 0, 2);

/** 公式代入值列（label → 顯示值） */
const formulaRows = computed(() => {
  const row = detail.value;
  if (!row) return [];
  return [
    { label: tl('ggr_ggr_amount'), value: fmt(row.ggr_amount) },
    { label: tl('ggr_bonus_rate'), value: String(row.bonus_rate ?? '') },
    {
      label: tl('ggr_condition_rate'),
      value: String(row.condition_rate ?? ''),
    },
    { label: tl('ggr_rate_label'), value: String(row.ggr_rate ?? '') },
    { label: tl('ggr_admin_fee'), value: String(row.admin_fee_rate ?? '') },
    { label: tl('handling_fee'), value: fmt(row.handling_fee) },
    { label: tl('ggr_final_amount'), value: fmt(row.final_amount) },
  ];
});

const snapshotText = computed(() => {
  const snapshot = detail.value?.calc_snapshot;
  if (!snapshot || Object.keys(snapshot).length === 0) return '';
  return JSON.stringify(snapshot, null, 2);
});

async function open(id: number) {
  visible.value = true;
  loading.value = true;
  detail.value = null;
  try {
    const res = await getGgrSettlementViewApi(id).catch(() => null);
    if (res && Number(res.code) === 200 && res.data) {
      detail.value = res.data;
    } else {
      ElMessage.error(res?.message ?? tl('operation_failed'));
      visible.value = false;
    }
  } finally {
    loading.value = false;
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog v-model="visible" :title="tl('ggr_calc')" top="8vh" width="640">
    <div v-loading="loading" class="max-h-[64vh] overflow-y-auto">
      <template v-if="detail">
        <div class="text-muted-foreground mb-2 text-sm">
          {{ detail.plan_name }} / {{ detail.agent_username }} /
          {{ detail.period_start }} ~ {{ detail.period_end }}
        </div>
        <div class="border-border rounded border">
          <div
            v-for="item in formulaRows"
            :key="item.label"
            class="border-border flex items-center justify-between border-b px-3 py-2 text-sm last:border-b-0"
          >
            <span class="text-muted-foreground">{{ item.label }}</span>
            <span class="font-medium">{{ item.value }}</span>
          </div>
        </div>
        <template v-if="snapshotText">
          <div class="mb-1 mt-3 text-sm font-medium">
            {{ tl('ggr_calc') }}
          </div>
          <pre class="bg-muted/40 max-h-64 overflow-auto rounded p-3 text-xs">{{
            snapshotText
          }}</pre>
        </template>
        <div v-else class="text-muted-foreground mt-3 text-center text-sm">
          {{ tl('no_record') }}
        </div>
      </template>
    </div>
    <template #footer>
      <ElButton @click="visible = false">{{ tl('cancel') }}</ElButton>
    </template>
  </ElDialog>
</template>
