<script lang="ts" setup>
import type { EchartsUIType } from '@vben/plugins/echarts';

import type { SiteDashboardSite, SiteDashboardTrends } from '#/api';

import { onMounted, ref } from 'vue';

import { Page } from '@vben/common-ui';
import { EchartsUI, useEcharts } from '@vben/plugins/echarts';

import { ElButton, ElCard, ElDatePicker } from 'element-plus';

import {
  getSiteDashboardSitesApi,
  getSiteDashboardSummaryApi,
  getSiteDashboardTrendsApi,
} from '#/api';
import { tl } from '#/locales';
import { sixTypesQuickRange } from '#/utils/quick-range';
import { currentTzOffset } from '#/utils/timezone-shift';

defineOptions({ name: 'SiteDashboard' });

const loading = ref(false);

// ── 站點按鈕（admin 有 ALL＋全部總代；其他人只有自己） ──
const sites = ref<SiteDashboardSite[]>([]);
const activeSite = ref<number>(0);

// ── 週期：預設本週；快捷 本週／本月／上個月（日結 D-1，今日以後不可選） ──
type QuickKind = 'last_month' | 'this_month' | 'this_week';
const activeQuick = ref<'' | QuickKind>('this_week');
const startDate = ref('');
const endDate = ref('');

function applyQuick(kind: QuickKind) {
  activeQuick.value = kind;
  const [s, e] = sixTypesQuickRange(kind);
  startDate.value = s.slice(0, 10);
  endDate.value = e.slice(0, 10);
  loadData();
}

/** 日結 D-1：今日（前端時區）與未來不可選 */
function disabledDate(date: Date) {
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  return date.getTime() >= today.getTime();
}

// ── 數字卡 ──
const newMembers = ref(0);
const totalMembers = ref(0);
const totalAgents = ref(0);

// ── 三張圖 ──
const registrationsRef = ref<EchartsUIType>();
const moneyRef = ref<EchartsUIType>();
const betRef = ref<EchartsUIType>();
const { renderEcharts: renderRegistrations } = useEcharts(registrationsRef);
const { renderEcharts: renderMoney } = useEcharts(moneyRef);
const { renderEcharts: renderBet } = useEcharts(betRef);

function query() {
  return {
    site: activeSite.value,
    start_time: `${startDate.value} 00:00:00`,
    end_time: `${endDate.value} 23:59:59`,
    tz_offset: currentTzOffset(),
  };
}

async function loadData() {
  if (!startDate.value || !endDate.value) return;
  loading.value = true;
  try {
    const [sum, trends] = await Promise.all([
      getSiteDashboardSummaryApi(query()),
      getSiteDashboardTrendsApi(query()),
    ]);
    if (Number(sum.code) === 200 && sum.data) {
      newMembers.value = sum.data.new_members;
      totalMembers.value = sum.data.total_members;
      totalAgents.value = sum.data.total_agents;
    }
    if (Number(trends.code) === 200 && trends.data) {
      renderCharts(trends.data);
    }
  } finally {
    loading.value = false;
  }
}

function switchSite(id: number) {
  activeSite.value = id;
  loadData();
}

function onManualSearch() {
  activeQuick.value = '';
  loadData();
}

function renderCharts(t: SiteDashboardTrends) {
  const axis = {
    type: 'category' as const,
    data: t.dates,
    axisLabel: { formatter: (v: string) => v.slice(5) },
  };
  // legend 一律錨定頂部置中、grid 讓開，不留給 ECharts 自動布局（曾跑位壓到 x 軸）
  const legendTop = { left: 'center' as const, top: 0 };
  const grid = { bottom: 28, containLabel: true, left: 16, right: 16, top: 36 };

  // 新註冊柱狀（綠）：單一序列，卡片標題已說明，不放 legend
  renderRegistrations({
    grid: { bottom: 24, containLabel: true, left: 8, right: 8, top: 12 },
    series: [
      {
        barMaxWidth: 28,
        color: '#2ec7a6',
        data: t.registrations,
        name: tl('sd_registrations'),
        type: 'bar',
      },
    ],
    tooltip: { trigger: 'axis' },
    xAxis: axis,
    yAxis: { minInterval: 1, type: 'value' },
  });

  // 充值（綠）／提款（紅）雙柱
  renderMoney({
    grid,
    legend: { ...legendTop, data: [tl('sd_deposit'), tl('sd_withdrawal')] },
    series: [
      {
        barMaxWidth: 36,
        color: '#67c23a',
        data: t.deposit.map(Number),
        name: tl('sd_deposit'),
        type: 'bar',
      },
      {
        barMaxWidth: 36,
        color: '#e23b2e',
        data: t.withdrawal.map(Number),
        name: tl('sd_withdrawal'),
        type: 'bar',
      },
    ],
    tooltip: { trigger: 'axis' },
    xAxis: axis,
    yAxis: { type: 'value' },
  });

  // 投注／公司總輸贏／反水及活動獎勵 三折線（smooth 給小值：資料點少時 true 會彎成誇張弧線）
  renderBet({
    grid,
    legend: {
      ...legendTop,
      data: [tl('sd_bet'), tl('sd_company_win'), tl('sd_rewards')],
    },
    series: [
      {
        color: '#2ec7a6',
        data: t.bet.map(Number),
        name: tl('sd_bet'),
        smooth: 0.2,
        type: 'line',
      },
      {
        color: '#f0a020',
        data: t.company_win.map(Number),
        name: tl('sd_company_win'),
        smooth: 0.2,
        type: 'line',
      },
      {
        color: '#7b6cf0',
        data: t.rewards.map(Number),
        name: tl('sd_rewards'),
        smooth: 0.2,
        type: 'line',
      },
    ],
    tooltip: { trigger: 'axis' },
    xAxis: axis,
    yAxis: { type: 'value' },
  });
}

onMounted(async () => {
  const res = await getSiteDashboardSitesApi().catch(() => null);
  if (res && Number(res.code) === 200) {
    sites.value = res.data ?? [];
    // admin 預設 ALL(0)；其他人預設自己那顆（清單僅一筆）
    activeSite.value = sites.value[0]?.id ?? 0;
  }
  applyQuick('this_week');
});
</script>

<template>
  <Page :title="tl('menu_site_dashboard')">
    <!-- 站點切換 -->
    <ElCard class="mb-3" shadow="never">
      <!-- 站點按鈕列：僅 admin 有內容；非 admin 後端回空，整列不渲染（只看自己以下） -->
      <div v-if="sites.length > 0" class="flex flex-wrap items-center gap-2">
        <ElButton
          v-for="s in sites"
          :key="s.id"
          :type="activeSite === s.id ? 'primary' : 'default'"
          @click="switchSite(s.id)"
        >
          {{ s.name }}
        </ElButton>
      </div>
      <div class="mt-3 flex flex-wrap items-center gap-2">
        <ElButton
          :type="activeQuick === 'this_week' ? 'primary' : 'default'"
          size="small"
          @click="applyQuick('this_week')"
        >
          {{ tl('sd_this_week') }}
        </ElButton>
        <ElButton
          :type="activeQuick === 'this_month' ? 'primary' : 'default'"
          size="small"
          @click="applyQuick('this_month')"
        >
          {{ tl('sd_this_month') }}
        </ElButton>
        <ElButton
          :type="activeQuick === 'last_month' ? 'primary' : 'default'"
          size="small"
          @click="applyQuick('last_month')"
        >
          {{ tl('sd_last_month') }}
        </ElButton>
        <span class="mx-2 opacity-40">|</span>
        <ElDatePicker
          v-model="startDate"
          :clearable="false"
          :disabled-date="disabledDate"
          type="date"
          value-format="YYYY-MM-DD"
        />
        <span>~</span>
        <ElDatePicker
          v-model="endDate"
          :clearable="false"
          :disabled-date="disabledDate"
          type="date"
          value-format="YYYY-MM-DD"
        />
        <ElButton :loading="loading" type="success" @click="onManualSearch">
          {{ tl('search') }}
        </ElButton>
        <span class="text-xs opacity-50">{{ tl('sd_d1_hint') }}</span>
      </div>
    </ElCard>

    <div class="grid grid-cols-1 gap-3 xl:grid-cols-4">
      <!-- 左欄：新註冊柱狀＋數字卡 -->
      <div class="flex flex-col gap-3">
        <ElCard shadow="never">
          <template #header>
            <span class="font-medium">{{ tl('sd_registrations') }}</span>
          </template>
          <EchartsUI ref="registrationsRef" height="220px" />
        </ElCard>
        <ElCard shadow="never">
          <div class="flex items-center justify-between">
            <span>{{ tl('sd_new_members') }}</span>
            <span class="text-xl font-bold">{{ newMembers }}</span>
          </div>
        </ElCard>
        <ElCard shadow="never">
          <div class="flex items-center justify-between">
            <span>{{ tl('sd_total_members') }}</span>
            <span class="text-xl font-bold">{{ totalMembers }}</span>
          </div>
        </ElCard>
        <ElCard shadow="never">
          <div class="flex items-center justify-between">
            <span>{{ tl('sd_total_agents') }}</span>
            <span class="text-xl font-bold">{{ totalAgents }}</span>
          </div>
        </ElCard>
      </div>

      <!-- 右欄：充提雙柱＋三線折線 -->
      <div class="flex flex-col gap-3 xl:col-span-3">
        <ElCard shadow="never">
          <template #header>
            <span class="font-medium">{{ tl('sd_money_title') }}</span>
          </template>
          <EchartsUI ref="moneyRef" height="300px" />
        </ElCard>
        <ElCard shadow="never">
          <template #header>
            <span class="font-medium">{{ tl('sd_bet_title') }}</span>
          </template>
          <EchartsUI ref="betRef" height="300px" />
        </ElCard>
      </div>
    </div>
  </Page>
</template>
