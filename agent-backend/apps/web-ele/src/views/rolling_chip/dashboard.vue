<script lang="ts" setup>
import type { RollingChipDashboard } from '#/api';

import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';

import { Page } from '@vben/common-ui';

import dayjs from 'dayjs';
import { ElButton, ElCard } from 'element-plus';

import { getRollingChipDashboardApi } from '#/api';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';

defineOptions({ name: 'RollingChipDashboard' });

const router = useRouter();
const loading = ref(false);
const data = ref<RollingChipDashboard>({});

const fmt = (v?: null | number | string) => amountThousands(v, 4);

/** 6 列統計（label key + 值） */
const rows = computed(() => [
  { key: 'total_pay_rolling_chip', value: data.value.total_pay },
  { isBetLink: true, key: 'yesterday_bet', value: data.value.yesterday_bet },
  { key: 'today_pay_rolling_chip', value: data.value.today_pay },
  { key: 'total_use_rolling_chip', value: data.value.total_use },
  { key: 'member_rolling_chip', value: data.value.member_rolling_chip },
  { key: 'withdrawal_rolling_chip', value: data.value.member_withdrawable },
]);

/** 昨日投注時段（6 點後算昨日，否則今日） */
function betRange() {
  const base = dayjs().hour() >= 6 ? dayjs().subtract(1, 'day') : dayjs();
  return [base.format('YYYY-MM-DD 00:00'), base.format('YYYY-MM-DD 23:59')];
}

/** 連到對帳報表（newsr11，帶昨日時段） */
function goBetReport() {
  const [start, end] = betRange();
  const route = router.resolve({
    path: '/report/newsr11',
    query: { end_date: end, start_date: start, type: 2 },
  });
  window.open(route.href, '_blank');
}

onMounted(async () => {
  loading.value = true;
  try {
    const res = await getRollingChipDashboardApi();
    if (Number(res.code) === 1000) data.value = res.data ?? {};
  } finally {
    loading.value = false;
  }
});
</script>

<template>
  <Page
    :title="`${tl('menu_rolling_chip')} > ${tl('menu_rolling_chip_dashboard')}`"
  >
    <ElCard v-loading="loading" class="max-w-xl" shadow="never">
      <div
        v-for="r in rows"
        :key="r.key"
        class="bg-muted/40 mb-2 flex items-center justify-between rounded px-4 py-3"
      >
        <span class="font-medium">
          {{ tl(r.key) }}
          <ElButton
            v-if="r.isBetLink"
            class="ml-1"
            link
            type="primary"
            @click="goBetReport"
          >
            {{ tl('yesterday_total_bet') }}
          </ElButton>
        </span>
        <span class="text-right">{{ fmt(r.value) }}</span>
      </div>
    </ElCard>
  </Page>
</template>
