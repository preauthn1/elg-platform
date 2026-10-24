<script lang="ts" setup>
import type { MemberGameRestrictionRow } from '#/api';

import { reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
  ElTag,
} from 'element-plus';

import {
  getMemberGameRestrictionListApi,
  getMemberGroupDropdownApi,
  MEMBER_RESTRICT_SCOPE_MEMBER,
  MEMBER_RESTRICT_TYPE_VENDOR,
} from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';
import { fromApiTime } from '#/utils/timezone-shift';

import GameRestrictionEditDialog from './components/game-restriction-edit-dialog.vue';

defineOptions({ name: 'RiskGameRestriction' });

const PER_PAGE = 20;

const editRef = ref<InstanceType<typeof GameRestrictionEditDialog>>();

const loading = ref(false);
const list = ref<MemberGameRestrictionRow[]>([]);
const page = ref(1);
const total = ref(0);

const filters = reactive({
  member_group_id: '' as number | string,
  restrict_type: '' as number | string,
  status: '' as number | string,
  target_scope: '' as number | string,
  username: '',
});

/** 會員群組下拉（數量少，開頁載一次） */
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

async function loadData() {
  loading.value = true;
  try {
    const res = await getMemberGameRestrictionListApi({
      page: page.value,
      per_page: PER_PAGE,
      ...(filters.status === '' ? {} : { status: filters.status }),
      ...(filters.restrict_type === ''
        ? {}
        : { restrict_type: filters.restrict_type }),
      ...(filters.target_scope === ''
        ? {}
        : { target_scope: filters.target_scope }),
      ...(filters.username.trim() ? { username: filters.username.trim() } : {}),
      ...(filters.member_group_id === ''
        ? {}
        : { member_group_id: filters.member_group_id }),
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
  filters.status = '';
  filters.restrict_type = '';
  filters.target_scope = '';
  filters.username = '';
  filters.member_group_id = '';
  search();
}

function handlePageChange(next: number) {
  page.value = next;
  loadData();
}

/** 限制類型文字：遊戲商／遊戲 */
function restrictTypeText(row: MemberGameRestrictionRow): string {
  return Number(row.restrict_type) === MEMBER_RESTRICT_TYPE_VENDOR
    ? tl('game_restriction_type_vendor')
    : tl('game_restriction_type_game');
}

/** 目標用戶類型文字：指定會員／會員群組 */
function scopeText(row: MemberGameRestrictionRow): string {
  return Number(row.target_scope) === MEMBER_RESTRICT_SCOPE_MEMBER
    ? tl('game_restriction_scope_member')
    : tl('game_restriction_scope_group');
}

/** 表格內先顯示幾個，其餘收進「+N」由彈窗展開 */
const PREVIEW_COUNT = 3;

/** 受限對象清單：指定會員為帳號、會員群組為群組名 */
function targetItems(row: MemberGameRestrictionRow): string[] {
  if (Number(row.target_scope) === MEMBER_RESTRICT_SCOPE_MEMBER) {
    return (row.members ?? []).map((m) => m.username);
  }
  return (row.member_group_ids ?? []).map(
    (id) => groupOptions.value.find((g) => g.id === id)?.name ?? `#${id}`,
  );
}

/**
 * 受限標的清單。遊戲類型冠上所屬遊戲商，
 * 因各商常有同名遊戲（例如都叫「百家樂」），只列遊戲名分不出是哪一家的。
 */
function restrictItems(row: MemberGameRestrictionRow): string[] {
  return (row.target_names ?? []).map((t) =>
    t.vendor_name ? `(${t.vendor_name}) ${t.name}` : t.name,
  );
}

/** 標的以顏色區分類型：遊戲商藍、遊戲橙，掃列表時一眼看出這張單管哪一種 */
function restrictTagType(row: MemberGameRestrictionRow): 'primary' | 'success' {
  return Number(row.restrict_type) === MEMBER_RESTRICT_TYPE_VENDOR
    ? 'primary'
    : 'success';
}

// ── 完整清單彈窗（數量多時表格只放前幾個，其餘在此展開）──
const detailVisible = ref(false);
const detailTitle = ref('');
const detailItems = ref<string[]>([]);
const detailTagType = ref<'info' | 'primary' | 'success'>('info');

function openDetail(
  title: string,
  items: string[],
  tagType: 'info' | 'primary' | 'success',
) {
  detailTitle.value = title;
  detailItems.value = items;
  detailTagType.value = tagType;
  detailVisible.value = true;
}

loadGroups();
loadData();
</script>

<template>
  <Page
    :title="`${tl('menu_risk_management')} > ${tl('menu_risk_game_restriction')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-4">
          <ElFormItem :label="tl('game_restriction_restrict_type')">
            <ElSelect v-model="filters.restrict_type" class="w-full" clearable>
              <ElOption :label="tl('all')" value="" />
              <ElOption
                :label="tl('game_restriction_type_vendor')"
                :value="1"
              />
              <ElOption :label="tl('game_restriction_type_game')" :value="2" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('status')">
            <ElSelect v-model="filters.status" class="w-full" clearable>
              <ElOption :label="tl('all')" value="" />
              <ElOption :label="tl('enable')" :value="1" />
              <ElOption :label="tl('disable')" :value="0" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('game_restriction_scope')">
            <ElSelect v-model="filters.target_scope" class="w-full" clearable>
              <ElOption :label="tl('all')" value="" />
              <ElOption
                :label="tl('game_restriction_scope_member')"
                :value="1"
              />
              <ElOption
                :label="tl('game_restriction_scope_group')"
                :value="2"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('username')">
            <ElInput v-model="filters.username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('game_restriction_scope_group')">
            <ElSelect
              v-model="filters.member_group_id"
              class="w-full"
              clearable
              filterable
            >
              <ElOption
                v-for="g in groupOptions"
                :key="g.id"
                :label="g.name"
                :value="g.id"
              />
            </ElSelect>
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
          width="80"
        />
        <ElTableColumn
          :label="tl('game_restriction_restrict_type')"
          align="center"
          width="110"
        >
          <template #default="{ row }">
            {{ restrictTypeText(row as MemberGameRestrictionRow) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('game_restriction_scope')"
          align="center"
          width="110"
        >
          <template #default="{ row }">
            {{ scopeText(row as MemberGameRestrictionRow) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('game_restriction_members')"
          align="center"
          min-width="220"
        >
          <template #default="{ row }">
            <div class="flex flex-wrap items-center justify-center gap-1">
              <ElTag
                v-for="name in targetItems(
                  row as MemberGameRestrictionRow,
                ).slice(0, PREVIEW_COUNT)"
                :key="name"
                size="small"
                type="info"
              >
                {{ name }}
              </ElTag>
              <ElButton
                v-if="
                  targetItems(row as MemberGameRestrictionRow).length >
                  PREVIEW_COUNT
                "
                link
                size="small"
                type="primary"
                @click="
                  openDetail(
                    tl('game_restriction_members'),
                    targetItems(row as MemberGameRestrictionRow),
                    'info',
                  )
                "
              >
                +{{
                  targetItems(row as MemberGameRestrictionRow).length -
                  PREVIEW_COUNT
                }}
              </ElButton>
              <span
                v-if="targetItems(row as MemberGameRestrictionRow).length === 0"
              >
                -
              </span>
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('game_restriction_targets')"
          align="center"
          min-width="260"
        >
          <template #default="{ row }">
            <div class="flex flex-wrap items-center justify-center gap-1">
              <ElTag
                v-for="name in restrictItems(
                  row as MemberGameRestrictionRow,
                ).slice(0, PREVIEW_COUNT)"
                :key="name"
                :type="restrictTagType(row as MemberGameRestrictionRow)"
                size="small"
              >
                {{ name }}
              </ElTag>
              <ElButton
                v-if="
                  restrictItems(row as MemberGameRestrictionRow).length >
                  PREVIEW_COUNT
                "
                link
                size="small"
                type="primary"
                @click="
                  openDetail(
                    tl('game_restriction_targets'),
                    restrictItems(row as MemberGameRestrictionRow),
                    restrictTagType(row as MemberGameRestrictionRow),
                  )
                "
              >
                +{{
                  restrictItems(row as MemberGameRestrictionRow).length -
                  PREVIEW_COUNT
                }}
              </ElButton>
              <span
                v-if="
                  restrictItems(row as MemberGameRestrictionRow).length === 0
                "
              >
                -
              </span>
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('memo')" align="center" min-width="140">
          <template #default="{ row }">
            <div :title="row.memo" class="truncate" style="max-width: 160px">
              {{ row.memo || '-' }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('status')" align="center" width="90">
          <template #default="{ row }">
            <ElTag :type="Number(row.status) === 1 ? 'success' : 'info'">
              {{ Number(row.status) === 1 ? tl('enable') : tl('disable') }}
            </ElTag>
          </template>
        </ElTableColumn>
        <!-- 後端輸出 GMT+8，依所選時區平移顯示（target/tickets#4492） -->
        <ElTableColumn
          :formatter="
            (row: MemberGameRestrictionRow) => fromApiTime(row.created_at)
          "
          :label="tl('created_time')"
          align="center"
          prop="created_at"
          width="160"
        />
        <ElTableColumn
          :label="tl('features')"
          align="center"
          fixed="right"
          width="90"
        >
          <template #default="{ row }">
            <ElButton size="small" @click="editRef?.open(row.id)">
              {{ tl('edit') }}
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

    <!-- 完整清單：表格內只放前幾個，數量多時由此展開 -->
    <ElDialog v-model="detailVisible" :title="detailTitle" width="620px">
      <div class="flex max-h-96 flex-wrap gap-1 overflow-y-auto">
        <ElTag
          v-for="name in detailItems"
          :key="name"
          :type="detailTagType"
          size="small"
        >
          {{ name }}
        </ElTag>
      </div>
      <template #footer>
        <ElButton @click="detailVisible = false">{{ tl('close') }}</ElButton>
      </template>
    </ElDialog>

    <GameRestrictionEditDialog ref="editRef" @success="loadData" />
  </Page>
</template>
