<script lang="ts" setup>
import type { MemberActivityFilterRow } from '#/api';

import { onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElRadio,
  ElRadioGroup,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getMemberActivityFilterApi } from '#/api';
import DateRangeFields from '#/components/date-range-fields.vue';
import FixedTimezoneHint from '#/components/fixed-timezone-hint.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'MemberActivityFilter' });

const PER_PAGE = 15;

/** 數值篩選維度 */
const NUMERIC_KEYS = [
  'total_deposit_amount',
  'deposit_count',
  'total_withdraw_amount',
  'withdraw_count',
] as const;
/** 日期篩選維度 */
const DATE_KEYS = [
  'last_login',
  'last_deposit_at',
  'last_withdraw_at',
  'last_bet_at',
] as const;

interface NumericState {
  op: 'between' | 'gt' | 'lte';
  value: string;
  value2: string;
}

function defaultFilters() {
  const numeric = (): NumericState => ({ op: 'gt', value: '', value2: '' });
  return {
    total_deposit_amount: numeric(),
    deposit_count: numeric(),
    total_withdraw_amount: numeric(),
    withdraw_count: numeric(),
    last_login: ['', ''] as string[],
    last_deposit_at: ['', ''] as string[],
    last_withdraw_at: ['', ''] as string[],
    last_bet_at: ['', ''] as string[],
  };
}

const filters = reactive(defaultFilters());
const match = ref<'and' | 'or'>('and');

/** 組出 API filters（只帶有填的維度） */
function buildParams() {
  const f: Record<string, any> = {};
  for (const key of NUMERIC_KEYS) {
    const n = filters[key];
    if (n.op === 'between') {
      if (n.value !== '' && n.value2 !== '') {
        f[key] = {
          op: 'between',
          value: Number(n.value),
          value2: Number(n.value2),
        };
      }
    } else if (n.value !== '') {
      f[key] = { op: n.op, value: Number(n.value) };
    }
  }
  for (const key of DATE_KEYS) {
    const [start, end] = filters[key];
    if (start && end) f[key] = { start, end };
  }
  return { filters: f, match: match.value };
}

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<MemberActivityFilterRow>(
    ({ page, per_page }) =>
      getMemberActivityFilterApi({ ...buildParams(), page, per_page }),
    { perPage: PER_PAGE, showError: true },
  );

function handleClear() {
  Object.assign(filters, defaultFilters());
  match.value = 'and';
  search();
}

function fmt(v: null | string) {
  return v ?? '-';
}

onMounted(loadData);
</script>

<template>
  <Page :title="tl('maf_title')">
    <!-- 篩選器 -->
    <ElCard class="mb-3" shadow="never">
      <ElForm label-position="top" @submit.prevent="search">
        <div class="grid grid-cols-1 gap-x-6 gap-y-1 md:grid-cols-2">
          <!-- 數值維度 -->
          <ElFormItem
            v-for="key in NUMERIC_KEYS"
            :key="key"
            :label="tl(`maf_${key}`)"
          >
            <div class="w-full">
              <ElRadioGroup v-model="filters[key].op" class="mb-2">
                <ElRadio value="gt">{{ tl('maf_op_gte') }}</ElRadio>
                <ElRadio value="between">{{ tl('maf_op_between') }}</ElRadio>
                <ElRadio value="lte">{{ tl('maf_op_lte') }}</ElRadio>
              </ElRadioGroup>
              <div class="flex items-center gap-2">
                <ElInput
                  v-model="filters[key].value"
                  :placeholder="tl('please_enter')"
                  class="flex-1"
                />
                <template v-if="filters[key].op === 'between'">
                  <span class="text-gray-400">-</span>
                  <ElInput
                    v-model="filters[key].value2"
                    :placeholder="tl('please_enter')"
                    class="flex-1"
                  />
                </template>
              </div>
            </div>
          </ElFormItem>

          <!-- 日期維度：純日期篩選，整頁固定 GMT+8（後端以 GMT+8 日界查、時間輸出 GMT+8，target/tickets#4492 修正原本的裸 UTC），不隨時區設定平移 -->
          <ElFormItem
            v-for="key in DATE_KEYS"
            :key="key"
            :label="tl(`maf_${key}`)"
          >
            <DateRangeFields v-model="filters[key]" :fixed-offset="8" />
          </ElFormItem>
          <FixedTimezoneHint class="md:col-span-full" />
        </div>

        <div class="mt-2 flex flex-wrap items-center justify-between gap-3">
          <ElFormItem :label="tl('maf_search_logic')" class="!mb-0">
            <ElRadioGroup v-model="match">
              <ElRadio value="and">{{ tl('maf_match_and') }}</ElRadio>
              <ElRadio value="or">{{ tl('maf_match_or') }}</ElRadio>
            </ElRadioGroup>
          </ElFormItem>
          <div class="flex gap-2">
            <ElButton @click="handleClear">{{ tl('clear') }}</ElButton>
            <ElButton type="primary" native-type="submit">
              {{ tl('search') }}
            </ElButton>
          </div>
        </div>
      </ElForm>
    </ElCard>

    <!-- 結果表 -->
    <ElCard v-loading="loading" shadow="never">
      <ElTable :data="list" border>
        <ElTableColumn :label="tl('maf_username')" min-width="130">
          <template #default="{ row }">
            <MemberAccountLink :username="row.username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('mobile')" min-width="120" prop="mobile" />
        <ElTableColumn
          :label="tl('maf_member_id')"
          prop="member_id"
          width="90"
        />

        <ElTableColumn :label="tl('maf_deposit')" align="center">
          <ElTableColumn
            :label="tl('maf_times')"
            prop="deposit_count"
            width="80"
          />
          <ElTableColumn
            :label="tl('maf_amount')"
            align="right"
            prop="deposit_amount"
            width="120"
          />
          <ElTableColumn :label="tl('maf_last_deposit_date')" width="160">
            <template #default="{ row }">
              {{ fmt(row.last_deposit_at) }}
            </template>
          </ElTableColumn>
        </ElTableColumn>

        <ElTableColumn :label="tl('maf_withdraw')" align="center">
          <ElTableColumn
            :label="tl('maf_times')"
            prop="withdraw_count"
            width="80"
          />
          <ElTableColumn
            :label="tl('maf_amount')"
            align="right"
            prop="withdraw_amount"
            width="120"
          />
          <ElTableColumn :label="tl('maf_last_withdraw_date')" width="160">
            <template #default="{ row }">
              {{ fmt(row.last_withdraw_at) }}
            </template>
          </ElTableColumn>
        </ElTableColumn>

        <ElTableColumn :label="tl('maf_last_bet_date')" width="160">
          <template #default="{ row }">{{ fmt(row.last_bet_at) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('maf_last_activity_date')" width="160">
          <template #default="{ row }">
            {{ fmt(row.last_activity_at) }}
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
  </Page>
</template>
