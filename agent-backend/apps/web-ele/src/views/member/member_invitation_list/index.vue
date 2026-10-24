<script lang="ts" setup>
import type { MemberInvitationRow } from '#/api/member-invitation-list';

import { reactive, ref } from 'vue';

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

import { getMemberInvitationListApi } from '#/api/member-invitation-list';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'MemberInvitationList' });

const PER_PAGE = 30;

const filters = reactive({
  username: '',
});

/** 32 位長整數轉點分十進位 IPv4，undefined/0 一律顯示空字串 */
function long2ip(n?: number) {
  if (!n) return '';
  return [(n >>> 24) & 255, (n >>> 16) & 255, (n >>> 8) & 255, n & 255].join(
    '.',
  );
}

const { handlePageChange, list, loading, page, search, total } =
  useListQuery<MemberInvitationRow>(
    ({ page, per_page }) =>
      getMemberInvitationListApi({
        page,
        per_page,
        username: filters.username,
      }),
    {
      guard: () => {
        if (!filters.username) {
          ElMessage.warning(tl('required_column'));
          return false;
        }
        return true;
      },
      perPage: PER_PAGE,
    },
  );

const exporting = ref(false);
async function handleExport() {
  if (!filters.username) {
    ElMessage.warning(tl('required_column'));
    return;
  }
  exporting.value = true;
  try {
    const params = {
      page: 1,
      per_page: 100_000,
      username: filters.username,
    };
    const res = await getMemberInvitationListApi(params);
    const rows = Number(res.code) === 1000 ? (res.data ?? []) : [];
    await exportExcel(
      'member_invitation_list',
      [
        tl('register_time'),
        tl('username'),
        tl('mobile'),
        tl('real_name'),
        tl('register_ip'),
        tl('last_ip'),
      ],
      rows.map((r) => [
        r.created_at,
        r.username,
        r.mobile,
        r.first_name,
        long2ip(r.register_ip),
        long2ip(r.last_login_ip),
      ]),
      {
        audit: { conditions: params },
        title: tl('menu_member_invitation_list'),
      },
    );
  } finally {
    exporting.value = false;
  }
}
</script>

<template>
  <Page
    :title="`${tl('member_lower_management')} > ${tl('menu_member_invitation_list')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-3">
          <ElFormItem :label="tl('member_account')" required>
            <ElInput v-model="filters.username" clearable />
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
          :label="tl('register_time')"
          min-width="150"
          prop="created_at"
        />
        <ElTableColumn :label="tl('username')" min-width="120">
          <template #default="{ row }">
            <MemberAccountLink :username="row.username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('mobile')" min-width="120" prop="mobile" />
        <ElTableColumn
          :label="tl('real_name')"
          min-width="120"
          prop="first_name"
        />
        <ElTableColumn :label="tl('register_ip')" min-width="130">
          <template #default="{ row }">
            {{ long2ip(row.register_ip) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('last_ip')" min-width="130">
          <template #default="{ row }">
            {{ long2ip(row.last_login_ip) }}
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
