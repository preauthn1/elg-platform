<script lang="ts" setup>
import type { SettlementPeriod } from '#/api';

import { onMounted, ref } from 'vue';

import { Page } from '@vben/common-ui';

import { ElButton, ElCard, ElMessage, ElOption, ElSelect } from 'element-plus';

import { getSettlementPeriodApi } from '#/api';
import { tl } from '#/locales';

const props = defineProps<{
  /** 期數欄位標題 */
  label: string;
  /** 紅字提示 */
  remark: string;
  /** 送出：回 API 結果碼為 1000 視為成功 */
  submit: (
    period: number | string,
  ) => Promise<{ code: number; message: string }>;
  /** 頁面標題 */
  title: string;
}>();

const periods = ref<SettlementPeriod[]>([]);
const period = ref<number | string>('');
const submitting = ref(false);

function periodLabel(row: SettlementPeriod) {
  return `${tl('periods').replace('{N}', String(row.period))} - ${row.start_date} ~ ${row.end_date}`;
}

async function handleSubmit() {
  if (period.value === '') return;
  submitting.value = true;
  try {
    const res = await props.submit(period.value);
    if (Number(res?.code) === 1000) {
      ElMessage.success(res.message);
    } else {
      ElMessage.error(res?.message);
    }
  } finally {
    submitting.value = false;
  }
}

onMounted(async () => {
  const data = await getSettlementPeriodApi().catch(() => []);
  periods.value = data ?? [];
  if (periods.value.length > 0) {
    period.value = periods.value[0]?.period ?? period.value;
  }
});
</script>

<template>
  <Page :title="title">
    <ElCard shadow="never">
      <div class="grid grid-cols-1 items-center gap-4 md:grid-cols-4">
        <div class="text-sm font-medium">{{ label }}</div>
        <ElSelect v-model="period" class="!w-full" filterable>
          <ElOption
            v-for="p in periods"
            :key="p.period"
            :label="periodLabel(p)"
            :value="p.period"
          />
        </ElSelect>
        <div class="md:col-span-2"></div>
      </div>
      <div class="mt-4 flex items-center justify-between">
        <span class="text-red-500 text-sm">{{ remark }}</span>
        <ElButton :loading="submitting" type="primary" @click="handleSubmit">
          {{ tl('submit') }}
        </ElButton>
      </div>
    </ElCard>
  </Page>
</template>
