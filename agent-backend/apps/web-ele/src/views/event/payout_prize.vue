<script lang="ts" setup>
import type { EventDropdownRow, EventPayoutCancelRow } from '#/api';

import { onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElMessageBox,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  getEventDropdownApi,
  getEventPayoutCancelListApi,
  payoutCancelPrizeApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { fromApiTime, nowInUserTz, toApiTime } from '#/utils/timezone-shift';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'EventPayoutPrize' });

const PER_PAGE = 20;

const events = ref<EventDropdownRow[]>([]);
const filters = reactive({
  type: 1 as number | string,
  username: '',
  event_id: 0 as number | string,
  date_range: [
    nowInUserTz().subtract(1, 'day').format('YYYY-MM-DD'),
    nowInUserTz().format('YYYY-MM-DD'),
  ] as [string, string],
});

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<EventPayoutCancelRow>(
    ({ page, per_page }) => {
      const [start, end] = filters.date_range ?? [];
      return getEventPayoutCancelListApi({
        end_time: toApiTime(`${end} 23:59:59`),
        event_id: filters.event_id,
        page,
        per_page,
        start_time: toApiTime(`${start} 00:00:00`),
        type: filters.type,
        username: filters.username,
      });
    },
    { perPage: PER_PAGE, showError: true },
  );

/** 整數 → IP 字串 */
function long2ip(v: number | string): string {
  const n = Number(v);
  if (!n || n <= 0) return '-';
  return [(n >>> 24) & 255, (n >>> 16) & 255, (n >>> 8) & 255, n & 255].join(
    '.',
  );
}

async function onPayout(row: EventPayoutCancelRow) {
  try {
    const { value } = await ElMessageBox.prompt(
      tl('enter_memo'),
      tl('disburse_award'),
      { inputType: 'textarea', inputPlaceholder: tl('enter_memo') },
    );
    const res = await payoutCancelPrizeApi(
      row.event_payout_cancel_id,
      value ?? '',
    );
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      loadData();
    } else {
      ElMessage.error(res.message);
    }
  } catch {
    // 取消
  }
}

onMounted(async () => {
  events.value = (await getEventDropdownApi().catch(() => [])) ?? [];
  loadData();
});
</script>

<template>
  <Page :title="`${tl('menu_event')} > ${tl('menu_payout_prize')}`">
    <ElCard class="mb-3" shadow="never">
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2">
          <ElFormItem>
            <template #label>
              <ElSelect v-model="filters.type" class="!w-36">
                <ElOption :label="tl('agent_account')" :value="1" />
                <ElOption :label="tl('member_account')" :value="2" />
              </ElSelect>
            </template>
            <ElInput v-model="filters.username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('event_name')">
            <ElSelect v-model="filters.event_id" class="!w-full" filterable>
              <ElOption :label="tl('all')" :value="0" />
              <ElOption
                v-for="e in events"
                :key="e.id"
                :label="`${e.id} - ${e.title}`"
                :value="e.id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('time_date')" class="md:col-span-2">
            <DateRangeFields v-model="filters.date_range" />
          </ElFormItem>
        </div>
        <div class="flex items-center justify-end">
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn
          :label="tl('event_name')"
          min-width="140"
          prop="event_title"
        />
        <ElTableColumn
          :formatter="
            (row: EventPayoutCancelRow) => fromApiTime(row.created_at)
          "
          :label="tl('time')"
          min-width="150"
        />
        <ElTableColumn
          :label="tl('agent_account')"
          min-width="120"
          prop="agent_username"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('member_account')" min-width="120">
          <template #default="{ row }">
            <MemberAccountLink :username="row.member_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('mobile')" min-width="110" prop="mobile" />
        <ElTableColumn
          :label="tl('realname')"
          min-width="110"
          prop="first_name"
        />
        <ElTableColumn
          :label="`${tl('register_ip')} / ${tl('last_ip')}`"
          min-width="140"
        >
          <template #default="{ row }">
            <div>{{ long2ip(row.register_ip) }}</div>
            <div>{{ long2ip(row.last_login_ip) }}</div>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('cancel')}${tl('category')}`"
          min-width="120"
        >
          <template #default="{ row }">
            {{ tl(`payout_cancel_type_${row.type}`) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('memo')" min-width="140" prop="remark" />
        <ElTableColumn :label="tl('operator')" min-width="110" prop="operator">
          <template #default="{ row }">
            <AgentAccount :username="row.operator" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('status')" min-width="100">
          <template #default="{ row }">
            {{ Number(row.status) === 0 ? tl('cancel') : tl('distributed') }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('action')"
          align="center"
          width="120"
          fixed="right"
        >
          <template #default="{ row }">
            <ElButton
              v-if="Number(row.status) === 0"
              size="small"
              type="primary"
              @click="onPayout(row as EventPayoutCancelRow)"
            >
              {{ tl('disburse_award') }}
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
  </Page>
</template>
