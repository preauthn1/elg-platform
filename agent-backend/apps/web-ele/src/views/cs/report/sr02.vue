<script lang="ts" setup>
import type { CsSr02Row, CsSr02Total } from '#/api/cs';

import { computed, onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';
import { usePreferences } from '@vben/preferences';

import dayjs from 'dayjs';
import {
  ElButton,
  ElCard,
  ElDatePicker,
  ElEmpty,
  ElForm,
  ElFormItem,
  ElInput,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getCsSr02Api, runCsBootstrap } from '#/api/cs';
import FixedTimezoneHint from '#/components/fixed-timezone-hint.vue';
import { tl } from '#/locales';

defineOptions({ name: 'CsReportSr02' });

const { isMobile } = usePreferences();

const form = reactive({
  end: dayjs().format('YYYY-MM-DD'),
  start: dayjs().subtract(1, 'day').format('YYYY-MM-DD'),
  username: '',
});

const loading = ref(false);
const total = ref<CsSr02Total[]>([]);
const rows = ref<CsSr02Row[]>([]);
const keyword = ref('');

function num(v: unknown): number {
  return Number(v ?? 0);
}

const filtered = computed(() => {
  const kw = keyword.value.trim().toLowerCase();
  return kw
    ? rows.value.filter((r) => (r.username ?? '').toLowerCase().includes(kw))
    : rows.value;
});

async function doSearch() {
  loading.value = true;
  try {
    const res = await getCsSr02Api({
      end_date: form.end,
      start_date: form.start,
      username: form.username,
    });
    total.value = Array.isArray(res.total) ? res.total : [];
    rows.value = Array.isArray(res.data) ? res.data : [];
  } finally {
    loading.value = false;
  }
}

onMounted(async () => {
  loading.value = true;
  try {
    await runCsBootstrap(doSearch);
  } finally {
    loading.value = false;
  }
});
</script>

<template>
  <Page :title="tl('menu.sr02')">
    <ElCard shadow="never" class="mb-4">
      <ElForm :inline="!isMobile" @submit.prevent="doSearch">
        <ElFormItem :label="tl('vendor')">
          <ElInput
            v-model="form.username"
            clearable
            :placeholder="tl('report.agent_name')"
          />
        </ElFormItem>
        <ElFormItem :label="tl('form.startDate')">
          <ElDatePicker
            v-model="form.start"
            type="date"
            value-format="YYYY-MM-DD"
          />
        </ElFormItem>
        <ElFormItem :label="tl('form.endDate')">
          <ElDatePicker
            v-model="form.end"
            type="date"
            value-format="YYYY-MM-DD"
          />
        </ElFormItem>
        <ElFormItem>
          <ElButton type="primary" :loading="loading" native-type="submit">
            {{ tl('search') }}
          </ElButton>
        </ElFormItem>
      </ElForm>
      <FixedTimezoneHint />
    </ElCard>

    <ElCard shadow="never" class="mb-4">
      <template #header>{{ tl('cs.total') }}</template>
      <ElTable :data="total" border size="small">
        <ElTableColumn :label="tl('total_deposit')">
          <template #default="{ row }">{{ num(row.total_in) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('total_withdrawl')">
          <template #default="{ row }">{{ num(row.total_out) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('deposit_net_amount')">
          <template #default="{ row }">
            {{ num(row.total_in) - num(row.total_out) }}
          </template>
        </ElTableColumn>
        <template #empty>
          <ElEmpty :description="tl('cs.no_total')" />
        </template>
      </ElTable>
    </ElCard>

    <ElCard shadow="never">
      <div class="mb-3 flex items-center">
        <h3 class="m-0 text-base font-medium">{{ tl('cs.detail') }}</h3>
        <div class="flex-1"></div>
        <ElInput
          v-model="keyword"
          :placeholder="tl('cs.search_vendor')"
          clearable
          style="max-width: 240px"
        />
      </div>
      <ElTable
        v-loading="loading"
        :data="filtered"
        border
        size="small"
        max-height="560"
      >
        <ElTableColumn prop="username" :label="tl('vendor')" />
        <ElTableColumn :label="tl('total_deposit')">
          <template #default="{ row }">{{ num(row.total_in) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('total_withdrawl')">
          <template #default="{ row }">{{ num(row.total_out) }}</template>
        </ElTableColumn>
        <template #empty>
          <ElEmpty :description="tl('no_data')" />
        </template>
      </ElTable>
    </ElCard>
  </Page>
</template>
