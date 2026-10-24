<script lang="ts" setup>
import type { EchartsUIType } from '@vben/plugins/echarts';

import { onMounted, ref, watch } from 'vue';

import { EchartsUI, useEcharts } from '@vben/plugins/echarts';

import { ElCard } from 'element-plus';

defineOptions({ name: 'MemberPieCard' });

const props = defineProps<{
  data: Array<{ name: string; value: number }>;
  title: string;
}>();

const COLORS = ['#0f9aee', '#ff3c7e', '#228B22', '#FF4500', '#9932CC'];

const chartRef = ref<EchartsUIType>();
const { renderEcharts } = useEcharts(chartRef);

function render() {
  renderEcharts({
    color: COLORS,
    legend: { bottom: '2%', left: 'center', type: 'scroll' },
    series: [
      {
        data: props.data,
        name: props.title,
        radius: '60%',
        type: 'pie',
      },
    ],
    tooltip: {
      // 工單4274：圖表在抽屜內捲動時提示框不可刺出容器（confine 關在圖表範圍內）
      confine: true,
      formatter: '{b}<br/>{d}%',
      trigger: 'item',
    },
  });
}

onMounted(render);
watch(() => props.data, render, { deep: true });
</script>

<template>
  <ElCard shadow="never">
    <template #header>
      <span class="font-medium">{{ title }}</span>
    </template>
    <div class="relative">
      <EchartsUI ref="chartRef" height="300px" />
      <div
        v-if="!data || data.length === 0"
        class="text-muted-foreground absolute inset-0 flex items-center justify-center font-bold"
      >
        No Data Available.
      </div>
    </div>
  </ElCard>
</template>
