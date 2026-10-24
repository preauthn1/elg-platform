<script lang="ts" setup>
import type { MemberMailRow } from '#/api';

import { reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElMessageBox,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
  ElTag,
} from 'element-plus';

import {
  deleteMemberMailApi,
  getMemberGroupDropdownApi,
  getMemberMailListApi,
  MAIL_LANGUAGE_EN,
  MAIL_SEND_TYPE_AGENT,
  MAIL_SEND_TYPE_GROUP,
  MAIL_SEND_TYPE_MEMBER,
  MAIL_SEND_TYPE_ORGANIZATION,
} from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';

import MemberMailEditDialog from './components/member-mail-edit-dialog.vue';

defineOptions({ name: 'MemberMailManagement' });

const PER_PAGE = 20;
/** 後台介面顯示標題時優先取繁中 */
const LANGUAGE_ZH_TW = 2;

const editRef = ref<InstanceType<typeof MemberMailEditDialog>>();

const loading = ref(false);
const list = ref<MemberMailRow[]>([]);
const page = ref(1);
const total = ref(0);

const filters = reactive({
  keyword: '',
  send_type: '' as number | string,
  status: '' as number | string,
});

async function loadData() {
  loading.value = true;
  try {
    const res = await getMemberMailListApi({
      page: page.value,
      per_page: PER_PAGE,
      ...(filters.send_type === '' ? {} : { send_type: filters.send_type }),
      ...(filters.status === '' ? {} : { status: filters.status }),
      ...(filters.keyword.trim() ? { keyword: filters.keyword.trim() } : {}),
    });
    const ok = Number(res.code) === 200;
    list.value = ok ? res.data : [];
    total.value = ok ? (res.pagination?.total ?? 0) : 0;
  } finally {
    loading.value = false;
  }
}

function search() {
  page.value = 1;
  loadData();
}

function clear() {
  filters.send_type = '';
  filters.status = '';
  filters.keyword = '';
  search();
}

function handlePageChange(next: number) {
  page.value = next;
  loadData();
}

function sendTypeText(row: MemberMailRow): string {
  switch (Number(row.send_type)) {
    case MAIL_SEND_TYPE_AGENT: {
      return tl('member_mail_type_agent');
    }
    case MAIL_SEND_TYPE_GROUP: {
      return tl('member_mail_type_group');
    }
    case MAIL_SEND_TYPE_MEMBER: {
      return tl('member_mail_type_member');
    }
    case MAIL_SEND_TYPE_ORGANIZATION: {
      return tl('member_mail_type_organization');
    }
    default: {
      return '-';
    }
  }
}

/** 表格內先顯示幾個對象，其餘收進「+N」由彈窗展開 */
const PREVIEW_COUNT = 3;

/** 會員群組下拉（數量少，開頁載一次，供群組 id 對照名稱） */
const groupOptions = ref<Array<{ id: number; name: string }>>([]);

async function loadGroups() {
  const res = await getMemberGroupDropdownApi().catch(() => null);
  groupOptions.value =
    res && Number(res.code) === 200
      ? (res.data ?? []).map((g) => ({
          id: Number(g.id),
          name: String(g.name ?? ''),
        }))
      : [];
}

/** 發送對象清單：依類型列會員帳號／群組名／全平臺／代理帳號 */
function targetItems(row: MemberMailRow): string[] {
  switch (Number(row.send_type)) {
    case MAIL_SEND_TYPE_AGENT: {
      return [row.hierarchy_agent_username || `#${row.hierarchy_agent_id}`];
    }
    case MAIL_SEND_TYPE_GROUP: {
      return (row.member_group_ids ?? []).map(
        (id) => groupOptions.value.find((g) => g.id === id)?.name ?? `#${id}`,
      );
    }
    case MAIL_SEND_TYPE_MEMBER: {
      return (row.members ?? []).map((m) => m.username);
    }
    default: {
      return [row.organization_name || tl('member_mail_type_organization')];
    }
  }
}

// ── 對象完整清單彈窗：數量多時表格只放前幾個，其餘在此展開 ──
const detailVisible = ref(false);
const detailItems = ref<string[]>([]);

function openDetail(row: MemberMailRow) {
  detailItems.value = targetItems(row);
  detailVisible.value = true;
}

/** 列表標題：優先繁中，未填則英文（英文必填一定有） */
function titleText(row: MemberMailRow): string {
  const zh = row.languages.find((l) => l.language_id === LANGUAGE_ZH_TW);
  const en = row.languages.find((l) => l.language_id === MAIL_LANGUAGE_EN);
  return zh?.title || en?.title || '-';
}

async function handleDelete(row: MemberMailRow) {
  const ok = await ElMessageBox.confirm(tl('are_you_sure')).catch(() => false);
  if (!ok) return;
  const res = await deleteMemberMailApi(row.id);
  if (Number(res.code) === 200) {
    ElMessage.success(tl('operation_success'));
    loadData();
  } else {
    ElMessage.error(res.message);
  }
}

loadGroups();
loadData();
</script>

<template>
  <Page
    :title="`${tl('menu_agent_management')} > ${tl('menu_member_mail_management')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-4">
          <ElFormItem :label="tl('member_mail_send_type')">
            <ElSelect v-model="filters.send_type" class="w-full" clearable>
              <ElOption :label="tl('all')" value="" />
              <ElOption :label="tl('member_mail_type_member')" :value="1" />
              <ElOption :label="tl('member_mail_type_group')" :value="2" />
              <ElOption
                :label="tl('member_mail_type_organization')"
                :value="3"
              />
              <ElOption :label="tl('member_mail_type_agent')" :value="4" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('status')">
            <ElSelect v-model="filters.status" class="w-full" clearable>
              <ElOption :label="tl('all')" value="" />
              <ElOption :label="tl('enable')" :value="1" />
              <ElOption :label="tl('disable')" :value="0" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('member_mail_title')">
            <ElInput v-model="filters.keyword" clearable />
          </ElFormItem>
        </div>
        <div class="flex justify-end gap-2">
          <ElButton @click="clear">{{ tl('clear') }}</ElButton>
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <div class="mb-3 flex justify-end">
        <ElButton type="primary" @click="editRef?.open()">
          {{ tl('add') }}
        </ElButton>
      </div>
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn
          :label="tl('number')"
          align="center"
          prop="id"
          width="70"
        />
        <ElTableColumn
          :label="tl('member_mail_send_type')"
          align="center"
          width="130"
        >
          <template #default="{ row }">
            {{ sendTypeText(row as MemberMailRow) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('member_mail_title')"
          align="center"
          min-width="200"
        >
          <template #default="{ row }">
            <div
              :title="titleText(row as MemberMailRow)"
              class="truncate"
              style="max-width: 240px"
            >
              {{ titleText(row as MemberMailRow) }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('member_mail_target')"
          align="center"
          min-width="220"
        >
          <template #default="{ row }">
            <div class="flex flex-wrap items-center justify-center gap-1">
              <ElTag
                v-for="name in targetItems(row as MemberMailRow).slice(
                  0,
                  PREVIEW_COUNT,
                )"
                :key="name"
                size="small"
                type="info"
              >
                {{ name }}
              </ElTag>
              <ElButton
                v-if="targetItems(row as MemberMailRow).length > PREVIEW_COUNT"
                link
                size="small"
                type="primary"
                @click="openDetail(row as MemberMailRow)"
              >
                +{{ targetItems(row as MemberMailRow).length - PREVIEW_COUNT }}
              </ElButton>
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('member_mail_publish_time')"
          align="center"
          prop="publish_time"
          width="160"
        />
        <ElTableColumn
          :label="tl('member_mail_reach')"
          align="center"
          width="110"
        >
          <template #default="{ row }">
            {{ (row as MemberMailRow).read_count }} /
            {{ (row as MemberMailRow).recipient_count }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('status')" align="center" width="80">
          <template #default="{ row }">
            <ElTag :type="row.status === 1 ? 'success' : 'danger'">
              {{ row.status === 1 ? tl('enable') : tl('disable') }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('memo')" align="center" min-width="120">
          <template #default="{ row }">
            {{ row.memo || '-' }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('created_at')"
          align="center"
          prop="created_at"
          width="160"
        />
        <ElTableColumn :label="tl('operate')" align="center" width="150">
          <template #default="{ row }">
            <!-- 已發布僅能查看：信寄出去就不能收回或竄改 -->
            <ElButton
              link
              size="small"
              type="primary"
              @click="editRef?.open(row.id)"
            >
              {{
                (row as MemberMailRow).is_published ? tl('view') : tl('edit')
              }}
            </ElButton>
            <ElButton
              v-if="!(row as MemberMailRow).is_published"
              link
              size="small"
              type="danger"
              @click="handleDelete(row as MemberMailRow)"
            >
              {{ tl('delete') }}
            </ElButton>
          </template>
        </ElTableColumn>
      </ElTable>
      <TablePagination
        :current-page="page"
        :page-size="PER_PAGE"
        :total="total"
        @current-change="handlePageChange"
      />
    </ElCard>

    <!-- 發送對象完整清單（表格內只放前幾個） -->
    <ElDialog
      v-model="detailVisible"
      :title="tl('member_mail_target')"
      width="560px"
    >
      <div class="flex max-h-96 flex-wrap gap-1 overflow-y-auto">
        <ElTag v-for="name in detailItems" :key="name" size="small" type="info">
          {{ name }}
        </ElTag>
      </div>
      <template #footer>
        <ElButton @click="detailVisible = false">{{ tl('close') }}</ElButton>
      </template>
    </ElDialog>

    <MemberMailEditDialog ref="editRef" @success="loadData" />
  </Page>
</template>
