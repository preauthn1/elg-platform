<script lang="ts" setup>
import type { ScoreRecordRow } from '#/api';

import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';

import { Page } from '@vben/common-ui';

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

import { getScoreRecordListApi } from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import { amountPlain, amountThousands } from '#/utils/format';
import { fromApiTime } from '#/utils/timezone-shift';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

import ScoreLogDialog from './components/score-log-dialog.vue';

/**
 * 專屬優惠 › 積分紀錄（target/tickets#4232）：每會員一列，只列有積分錢包列的會員；
 * 可用／已過期／累計獲得三欄由 apis 聚合，點「查看」開明細彈窗。
 */
defineOptions({ name: 'EventScoreRecord' });

const PER_PAGE = 20;
/** 單次匯出上限，與會員列表一致（超過提示縮小條件；apis per_page 上限同值） */
const EXPORT_MAX_ROWS = 10_000;

const filters = reactive({ agent_username: '', username: '' });
const logDialog = ref<InstanceType<typeof ScoreLogDialog>>();

function buildParams() {
  return {
    agent_username: filters.agent_username.trim() || undefined,
    username: filters.username.trim() || undefined,
  };
}

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<ScoreRecordRow>(
    ({ page, per_page }) =>
      getScoreRecordListApi({ ...buildParams(), page, per_page }),
    { perPage: PER_PAGE },
  );

function handleClear() {
  filters.agent_username = '';
  filters.username = '';
  search();
}

const fmtTime = (v: null | string) => (v ? fromApiTime(v) : '-');

const exporting = ref(false);
/** 匯出 Excel：依當前篩選條件全量撈取後產表 */
async function handleExport() {
  if (total.value > EXPORT_MAX_ROWS) {
    ElMessage.warning(tl('export_row_limit_exceeded'));
    return;
  }
  exporting.value = true;
  try {
    const params = { ...buildParams(), page: 1, per_page: EXPORT_MAX_ROWS };
    const res = await getScoreRecordListApi(params);
    const data = Number(res.code) === 200 ? res.data : [];
    const rows = data.map((r) => [
      r.username,
      r.agent_username,
      amountPlain(r.available_score, 2),
      amountPlain(r.expired_score, 2),
      amountPlain(r.granted_score, 2),
      fmtTime(r.last_changed_at),
    ]);
    await exportExcel(
      `score_record_${Date.now()}`,
      [
        tl('member_account'),
        tl('agent_account'),
        tl('available_score'),
        tl('expired_score'),
        tl('granted_score'),
        tl('last_changed_at'),
      ],
      rows,
      {
        audit: { conditions: params },
        title: tl('menu_score_record'),
      },
    );
  } finally {
    exporting.value = false;
  }
}

const title = computed(
  () => `${tl('event_exclusive_offers')} > ${tl('menu_score_record')}`,
);

// 支援帶 query 進入（會員詳情抽屜的報表捷徑，target/tickets#4438）：帶入會員帳號後查詢
const route = useRoute();
onMounted(() => {
  if (route.query.member_username) {
    filters.username = String(route.query.member_username);
  }
  loadData();
});
</script>

<template>
  <Page :title="title">
    <ElCard class="mb-3" shadow="never">
      <ElForm label-width="auto" @submit.prevent="search">
        <div
          class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2 lg:grid-cols-4"
        >
          <ElFormItem :label="tl('member_account')">
            <ElInput v-model="filters.username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('agent_account')">
            <ElInput v-model="filters.agent_username" clearable />
          </ElFormItem>
        </div>
        <div class="flex items-center justify-end gap-2">
          <ElButton @click="handleClear">{{ tl('clear') }}</ElButton>
          <ElButton :loading="exporting" type="success" @click="handleExport">
            {{ tl('excel') }}
          </ElButton>
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn :label="tl('member_account')" min-width="130">
          <template #default="{ row }">
            <MemberAccountLink :username="row.username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('agent_account')"
          prop="agent_username"
          min-width="120"
        />
        <ElTableColumn
          :label="tl('available_score')"
          align="right"
          min-width="120"
        >
          <template #default="{ row }">
            {{ amountThousands(row.available_score, 2) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('expired_score')"
          align="right"
          min-width="120"
        >
          <template #default="{ row }">
            {{ amountThousands(row.expired_score, 2) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('granted_score')"
          align="right"
          min-width="130"
        >
          <template #default="{ row }">
            {{ amountThousands(row.granted_score, 2) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('last_changed_at')" min-width="160">
          <template #default="{ row }">
            {{ fmtTime(row.last_changed_at) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('action')" fixed="right" width="90">
          <template #default="{ row }">
            <ElButton
              link
              type="primary"
              @click="logDialog?.open(row as ScoreRecordRow)"
            >
              {{ tl('view') }}
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

    <ScoreLogDialog ref="logDialog" />
  </Page>
</template>
