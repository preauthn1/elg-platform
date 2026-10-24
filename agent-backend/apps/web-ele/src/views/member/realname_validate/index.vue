<script lang="ts" setup>
import type { RealnameValidateRow } from '#/api';

import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  getCustomizedSettingApi,
  getMemberRealnameValidateListApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useExcelExport } from '#/composables/use-excel-export';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { fromApiTime, nowInUserTz, toApiTime } from '#/utils/timezone-shift';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';
import { refreshMemberDetail } from '#/views/member/components/member-detail-drawer-state';

import ReviewDialog from './components/review-dialog.vue';

defineOptions({ name: 'MemberRealnameValidate' });

const PER_PAGE = 30;

const route = useRoute();

/**
 * 由會員詳情抽屜跳入時帶 request_id（target/tickets#4274）：直接開該筆審核彈窗，
 * 背景列表預填帳號並把期間拉寬到近 90 天（申請多半不是當天送的，維持「今日」會撈不到）。
 */
const jumpRequestId = Number(route.query.request_id ?? 0);

const filters = reactive({
  bank_account: '',
  // 由通知鈴鐺進來時帶 status（待審核=0），否則預設全部（target/tickets#4193）
  status: (route.query.status as string) ?? '-1',
  time_range: [
    nowInUserTz()
      .subtract(jumpRequestId ? 90 : 0, 'day')
      .format('YYYY-MM-DD 00:00:00'),
    nowInUserTz().format('YYYY-MM-DD 23:59:59'),
  ] as string[],
  username: (route.query.username as string) ?? '',
});

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<RealnameValidateRow>(
    ({ page, per_page }) => {
      const [start, end] = filters.time_range ?? [];
      return getMemberRealnameValidateListApi({
        bank_account: filters.bank_account,
        end_time: toApiTime(end ?? ''),
        page,
        per_page,
        start_time: toApiTime(start ?? ''),
        status: filters.status,
        username: filters.username,
      });
    },
    { perPage: PER_PAGE, showError: true },
  );

/** 代理設定：4011 啟用 BSB Code、3015 BSB 別名 */
const enableBsb = ref(false);
const bsbAlias = ref('BSB Code');

const reviewRef = ref<InstanceType<typeof ReviewDialog>>();

function statusText(s?: number) {
  if (Number(s) === 0) return tl('pending');
  if (Number(s) === 1) return tl('pass');
  if (Number(s) === 2) return tl('no_pass');
  return '-';
}

/** 申請即通過的件沒有審核人員（validator_id=0），顯示為系統 */
function operatorText(row: RealnameValidateRow) {
  if (row.operator) return row.operator;
  if (Number(row.status) !== 0 && Number(row.validator_id) === 0) {
    return tl('system');
  }
  return '-';
}

const accountNoLabel = computed(() =>
  enableBsb.value
    ? `${bsbAlias.value} / ${tl('account_no')}`
    : tl('account_no'),
);

const { exporting, runExport } = useExcelExport();
/** 匯出 Excel：條件同查詢（無必填限制） */
async function handleExport() {
  const [start, end] = filters.time_range ?? [];
  const params = {
    bank_account: filters.bank_account,
    end_time: toApiTime(end ?? ''),
    page: 1,
    per_page: 100_000,
    start_time: toApiTime(start ?? ''),
    status: filters.status,
    username: filters.username,
  };
  await runExport(
    () => getMemberRealnameValidateListApi(params),
    `realname_validate_${Date.now()}`,
    [
      tl('created_time'),
      tl('num'),
      tl('username'),
      tl('realname'),
      tl('account_name'),
      accountNoLabel.value,
      tl('id_number'),
      tl('birthday'),
      tl('id_front_image'),
      tl('id_back_image'),
      tl('memo'),
      tl('status'),
    ],
    (r) => [
      fromApiTime(r.created_at),
      r.id,
      r.username,
      r.realname,
      r.bank_name,
      enableBsb.value
        ? `${r.bsb_code ?? ''} ${r.bank_account ?? ''}`.trim()
        : r.bank_account,
      r.id_number,
      r.birthday,
      r.id_front_image,
      r.id_back_image,
      r.remark,
      statusText(r.status),
    ],
    start && end
      ? {
          audit: { conditions: params },
          dateRange: [start, end],
          title: tl('menu_realname_validate_request'),
        }
      : {
          audit: { conditions: params },
          title: tl('menu_realname_validate_request'),
        },
  );
}

/** 審核成功：重載列表，並通知會員詳情抽屜（若開著）刷新身份認證狀態 */
function onReviewSuccess() {
  loadData();
  refreshMemberDetail();
}

onMounted(() => {
  loadData();
  if (jumpRequestId) reviewRef.value?.open(jumpRequestId);
  getCustomizedSettingApi()
    .then((settings) => {
      const map = new Map(
        settings.map((s) => [Number(s.setting_key), s.value]),
      );
      enableBsb.value = Number(map.get(4011)) === 1;
      bsbAlias.value = (map.get(3015) as string) || 'BSB Code';
    })
    .catch(() => {});
});
</script>

<template>
  <Page
    :title="`${tl('member_lower_management')} > ${tl('menu_realname_validate_request')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm :model="filters" label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('member_account')">
            <ElInput v-model="filters.username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('account_no')">
            <ElInput v-model="filters.bank_account" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('status')">
            <ElSelect v-model="filters.status" class="w-full">
              <ElOption :label="tl('all')" value="-1" />
              <ElOption :label="tl('pending_review')" value="0" />
              <ElOption :label="tl('pass')" value="1" />
              <ElOption :label="tl('rejected')" value="2" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('between')">
            <DateRangeFields
              v-model="filters.time_range"
              format="YYYY-MM-DD HH:mm:ss"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm:ss"
            />
          </ElFormItem>
        </div>
        <div class="flex justify-end gap-2">
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
        <ElTableColumn
          :formatter="(row: RealnameValidateRow) => fromApiTime(row.created_at)"
          :label="tl('created_time')"
          width="160"
        />
        <ElTableColumn :label="tl('num')" prop="id" width="90" />
        <ElTableColumn :label="tl('username')" min-width="110">
          <template #default="{ row }">
            <MemberAccountLink :username="row.username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('realname')"
          prop="realname"
          min-width="100"
        />
        <ElTableColumn
          :label="tl('account_name')"
          prop="bank_name"
          min-width="110"
        />
        <ElTableColumn :label="accountNoLabel" min-width="130">
          <template #default="{ row }">
            <div v-if="enableBsb">{{ row.bsb_code }}</div>
            <div>{{ row.bank_account }}</div>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('operating_time')" width="160">
          <template #default="{ row }">
            {{ Number(row.status) === 0 ? '-' : row.updated_at }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('memo')" prop="remark" min-width="120" />
        <ElTableColumn :label="tl('status')" width="100">
          <template #default="{ row }">{{ statusText(row.status) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('operator')" min-width="110">
          <template #default="{ row }">
            <AgentAccount v-if="row.operator" :username="row.operator" />
            <template v-else>
              {{ operatorText(row as RealnameValidateRow) }}
            </template>
          </template>
        </ElTableColumn>
        <ElTableColumn
          align="center"
          width="90"
          :label="tl('action')"
          fixed="right"
        >
          <template #default="{ row }">
            <ElButton
              size="small"
              type="primary"
              @click="reviewRef?.open(row.id)"
            >
              {{ Number(row.status) === 0 ? tl('review') : tl('view') }}
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

    <ReviewDialog
      ref="reviewRef"
      :bsb-alias="bsbAlias"
      :enable-bsb="enableBsb"
      @success="onReviewSuccess"
    />
  </Page>
</template>
