<script lang="ts" setup>
import type { UserInstantBetRow } from '#/api';

import { computed, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';
import { useUserStore } from '@vben/stores';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getReportUserInstantBetsApi } from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { sumAmounts, toHalfWidth } from '#/utils/format';

defineOptions({ name: 'ReportUserInstantBets' });

const PER_PAGE = 20;

const userStore = useUserStore();

const filters = reactive({ username: '' });

const searched = ref(false);

const { handlePageChange, list, loading, page, search, total } =
  useListQuery<UserInstantBetRow>(
    ({ page, per_page }) => {
      searched.value = true;
      return getReportUserInstantBetsApi({
        page,
        per_page,
        username: toHalfWidth(filters.username),
      });
    },
    {
      guard: () => {
        if (!filters.username) {
          ElMessage.warning(tl('shareholder_account'));
          return false;
        }
        return true;
      },
      perPage: PER_PAGE,
      showError: true,
    },
  );

/** 本頁總計 */
const sumTotalBet = computed(() =>
  sumAmounts(list.value.map((r) => r.total_bet)),
);

/**
 * 帳號顯示：role 1/11/12 或本人上下層顯示原帳號，否則遮罩
 * */
function displayName(row: UserInstantBetRow) {
  const info = userStore.userInfo as any;
  const roleId = Number(info?.role_id);
  const hostId = Number(info?.host_id);
  const privileged =
    [1, 11, 12].includes(roleId) ||
    Number(row.parent) === hostId ||
    Number(row.id) === hostId;
  const name = row.username ?? '';
  if (privileged || name.length <= 2) return name;
  const head = name.slice(0, 1);
  const tail = name.slice(-1);
  return `${head}${'*'.repeat(Math.max(1, name.length - 2))}${tail}`;
}
</script>

<template>
  <Page
    :title="`${tl('menu_cj7_shareholder_report')} > ${tl('menu_cj7_user_instant_bets')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm inline @submit.prevent="search">
        <ElFormItem :label="tl('shareholder_account')">
          <ElInput v-model="filters.username" class="!w-60" clearable />
        </ElFormItem>
        <ElFormItem>
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </ElFormItem>
      </ElForm>
    </ElCard>

    <ElCard v-if="searched" shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn :label="tl('shareholder_account')" min-width="160">
          <template #default="{ row }">{{ displayName(row) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('total_bet_amount')" min-width="160">
          <template #default="{ row }">{{ row.total_bet }}</template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
        <template v-if="list.length > 0" #append>
          <div
            class="bg-muted grid grid-cols-2 px-3 py-2 text-sm font-medium text-blue-600"
          >
            <div>{{ tl('total_count') }}</div>
            <div>{{ sumTotalBet }}</div>
          </div>
        </template>
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
