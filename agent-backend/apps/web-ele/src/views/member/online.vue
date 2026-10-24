<script lang="ts" setup>
import type { MemberOnlineRow } from '#/api';

import { onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import dayjs from 'dayjs';
import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElMessageBox,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getMemberOnlineListApi, kickOnlineMemberApi } from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { useExcelExport } from '#/composables/use-excel-export';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'MemberOnline' });

const PER_PAGE = 25;

const filters = reactive({ ip: '', name: '', username: '' });

function buildParams() {
  const p: Record<string, string> = {};
  if (filters.username) p.username = filters.username;
  if (filters.name) p.name = filters.name;
  if (filters.ip) p.ip = filters.ip;
  return p;
}

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<MemberOnlineRow>(
    ({ page, per_page }) =>
      getMemberOnlineListApi({ ...buildParams(), page, per_page }),
    { perPage: PER_PAGE },
  );

function handleClear() {
  filters.username = '';
  filters.name = '';
  filters.ip = '';
  search();
}

/** apis 系時間為 UTC，顯示 +8（同 order_list 等頁慣例） */
function fmtTime(v: null | string | undefined) {
  return v ? dayjs(v).add(8, 'hour').format('YYYY-MM-DD HH:mm:ss') : '-';
}

/** 當前登入資訊：最後登入時間 + 登入IP */
function loginInfo(row: MemberOnlineRow) {
  if (!row.last_login) return '-';
  return `${fmtTime(row.last_login)} | ${row.last_login_ip ?? '-'}`;
}

/** 上線時長：後端回「最後活動時間 - 登入時間」秒數，格式化為 X小時Y分鐘 */
function fmtDuration(seconds: null | number | undefined) {
  if (seconds === null || seconds === undefined) return '-';
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  return `${h}${tl('hour')}${m}${tl('minute')}`;
}

// 會員詳細彈窗（點帳號開啟，同會員列表）

// 強制登出
const kickingId = ref(0);
async function handleKick(row: MemberOnlineRow) {
  try {
    await ElMessageBox.confirm(
      `${tl('force_logout_confirm')}（${row.username}）`,
      tl('force_logout'),
      {
        cancelButtonText: tl('cancel'),
        confirmButtonText: tl('confirm'),
        type: 'warning',
      },
    );
  } catch {
    return;
  }
  kickingId.value = row.member_id;
  try {
    const res = await kickOnlineMemberApi(row.member_id);
    if (Number(res.code) === 200) {
      ElMessage.success(res.message ?? '');
      loadData();
    } else {
      ElMessage.error(res.message ?? '');
    }
  } catch {
    // 失敗訊息由全域錯誤攔截器彈出
  } finally {
    kickingId.value = 0;
  }
}

// Excel 匯出
const { exporting, runExport } = useExcelExport();
function handleExport() {
  runExport(
    async () => {
      const res = await getMemberOnlineListApi({
        ...buildParams(),
        page: 1,
        per_page: 100_000,
      });
      return { code: res.code, data: res.data };
    },
    `online_member_${dayjs().format('YYYYMMDDHHmmss')}.xlsx`,
    [
      tl('username'),
      tl('real_name'),
      tl('group'),
      tl('current_login_info'),
      tl('last_activity_time'),
      tl('online_time'),
    ],
    (row) => [
      row.username,
      row.first_name ?? '-',
      row.group_name ?? '-',
      loginInfo(row),
      fmtTime(row.last_activity_at),
      fmtDuration(row.online_seconds),
    ],
    { title: tl('menu_member_online') },
  );
}

onMounted(loadData);
</script>

<template>
  <Page
    :title="`${tl('member_lower_management')} > ${tl('menu_member_online')}`"
  >
    <!-- 搜尋 -->
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-3">
          <ElFormItem :label="tl('username')">
            <ElInput
              v-model="filters.username"
              :placeholder="tl('please_enter')"
              clearable
            />
          </ElFormItem>
          <ElFormItem :label="tl('real_name')">
            <ElInput
              v-model="filters.name"
              :placeholder="tl('please_enter')"
              clearable
            />
          </ElFormItem>
          <ElFormItem :label="tl('ip')">
            <ElInput
              v-model="filters.ip"
              :placeholder="tl('please_enter')"
              clearable
            />
          </ElFormItem>
        </div>
        <div class="flex justify-end gap-2">
          <ElButton @click="handleClear">{{ tl('clear') }}</ElButton>
          <ElButton :loading="exporting" type="success" @click="handleExport">
            {{ tl('excel') }}
          </ElButton>
          <ElButton native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <!-- 列表 -->
    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn :label="tl('username')" min-width="130">
          <template #default="{ row }">
            <MemberAccountLink
              :member-id="row.member_id"
              :username="row.username"
            />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('real_name')" min-width="110">
          <template #default="{ row }">{{ row.first_name || '-' }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('group')" min-width="110">
          <template #default="{ row }">{{ row.group_name || '-' }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('current_login_info')" min-width="220">
          <template #default="{ row }">
            {{ loginInfo(row as MemberOnlineRow) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('last_activity_time')" min-width="160">
          <template #default="{ row }">
            {{ fmtTime(row.last_activity_at) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('online_time')" min-width="120">
          <template #default="{ row }">
            {{ fmtDuration(row.online_seconds) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('operate')" fixed="right" min-width="110">
          <template #default="{ row }">
            <ElButton
              :loading="kickingId === row.member_id"
              size="small"
              type="danger"
              @click="handleKick(row as MemberOnlineRow)"
            >
              {{ tl('force_logout') }}
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
