<script lang="ts" setup>
import type { UnlockEventModeRow } from '#/api';

import { onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElOption,
  ElPopover,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getUnlockEventModeListApi } from '#/api';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { amountThousands, toHalfWidth } from '#/utils/format';
import { fromApiTime, nowInUserTz, toApiTime } from '#/utils/timezone-shift';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

import UnlockEventDialog from './components/unlock-event-dialog.vue';

defineOptions({ name: 'MemberUnlockEventMode' });

const PER_PAGE = 20;

const reviewRef = ref<InstanceType<typeof UnlockEventDialog>>();
const route = useRoute();

const filters = reactive({
  member_username: '',
  // 由通知鈴鐺進來時帶 status（待審核=0），否則預設全部（target/tickets#4193）
  status:
    route.query.status === undefined
      ? (-1 as number | string)
      : Number(route.query.status),
  time_range: [
    nowInUserTz().format('YYYY-MM-DD 00:00'),
    nowInUserTz().format('YYYY-MM-DD 23:59'),
  ] as string[],
});

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<UnlockEventModeRow>(
    ({ page, per_page }) => {
      const [start, end] = filters.time_range ?? [];
      return getUnlockEventModeListApi({
        end_time: toApiTime(end ?? ''),
        member_username: toHalfWidth(filters.member_username),
        page,
        per_page,
        start_time: toApiTime(start ?? ''),
        status: filters.status,
      });
    },
    {
      guard: () => {
        const [start, end] = filters.time_range ?? [];
        if (!start || !end) {
          ElMessage.warning(tl('between'));
          return false;
        }
        return true;
      },
      perPage: PER_PAGE,
      showError: true,
    },
  );

const fmtAmount = (v?: null | number | string) => amountThousands(v, 2, '-');

function statusText(s?: number) {
  if (Number(s) === 0) return tl('pending_review');
  if (Number(s) === 1) return tl('pass');
  return tl('rejected');
}

// 頁面載入即查詢（server-rendered）
onMounted(loadData);
</script>

<template>
  <Page
    :title="`${tl('member_lower_management')} > ${tl('menu_unlock_event_lock')}`"
  >
    <!-- 查詢 -->
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('member_account')">
            <ElInput
              v-model="filters.member_username"
              :placeholder="tl('required_column')"
              clearable
            />
          </ElFormItem>
          <ElFormItem :label="tl('between')">
            <DateRangeFields
              v-model="filters.time_range"
              format="YYYY-MM-DD HH:mm"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm"
            />
          </ElFormItem>
          <ElFormItem :label="tl('status')">
            <ElSelect v-model="filters.status" class="w-full">
              <ElOption :label="tl('all')" :value="-1" />
              <ElOption :label="tl('pending_review')" :value="0" />
              <ElOption :label="tl('pass')" :value="1" />
              <ElOption :label="tl('rejected')" :value="2" />
            </ElSelect>
          </ElFormItem>
        </div>
        <div class="flex flex-wrap items-center justify-end gap-2">
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <!-- 列表 -->
    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn
          :formatter="(row: UnlockEventModeRow) => fromApiTime(row.created_at)"
          :label="tl('created_time')"
          width="160"
        />
        <ElTableColumn :label="tl('num')" prop="id" width="90" />
        <ElTableColumn :label="tl('member_account')" min-width="110">
          <template #default="{ row }">
            <MemberAccountLink :username="row.member_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('balance')" align="right" width="120">
          <template #default="{ row }">
            <!-- 餘額可點擊展開拆分明細；多錢包開關只決定是否多顯示遊戲錢包一項（target/tickets#4290） -->
            <ElPopover
              :title="tl('balance_detail')"
              placement="top"
              trigger="click"
              width="200"
            >
              <template #reference>
                <span
                  class="cursor-pointer border-b border-dashed border-current"
                >
                  {{ fmtAmount(row.balance) }}
                </span>
              </template>
              <div class="space-y-1 text-sm">
                <div class="flex justify-between">
                  <span>{{ tl('main_wallet') }}</span>
                  <span>{{ fmtAmount(row.main_wallet) }}</span>
                </div>
                <div
                  v-if="Number(row.multiple_wallet_switch) === 1"
                  class="flex justify-between"
                >
                  <span>{{ tl('game_wallet') }}</span>
                  <span>{{ fmtAmount(row.game_wallet) }}</span>
                </div>
                <div class="flex justify-between">
                  <span>{{ tl('rolling_chip') }}</span>
                  <span>{{ fmtAmount(row.rolling_chip) }}</span>
                </div>
                <div class="flex justify-between border-t pt-1 font-medium">
                  <span>{{ tl('cs.total') }}</span>
                  <span>{{ fmtAmount(row.balance) }}</span>
                </div>
              </div>
            </ElPopover>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('memo')" prop="remark" min-width="150" />
        <ElTableColumn :label="tl('status')" width="110">
          <template #default="{ row }">{{ statusText(row.status) }}</template>
        </ElTableColumn>
        <ElTableColumn align="center" width="100" fixed="right">
          <template #default="{ row }">
            <ElButton
              v-if="Number(row.status) === 0"
              size="small"
              type="primary"
              @click="reviewRef?.open(row.id, row.status)"
            >
              {{ tl('review') }}
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

    <UnlockEventDialog ref="reviewRef" @saved="loadData" />
  </Page>
</template>
