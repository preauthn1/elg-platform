<script lang="ts" setup>
import type { TagMemberRow, TagRow } from '#/api';

import { computed, onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getTagListApi, getTagMemberListApi, saveMemberTagsApi } from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

import TagSettingDialog from './components/tag-setting-dialog.vue';

defineOptions({ name: 'MemberTagIndex' });

const PER_PAGE = 10;

const settingRef = ref<InstanceType<typeof TagSettingDialog>>();

const tags = ref<TagRow[]>([]);
const tagMap = computed(
  () => new Map(tags.value.map((t) => [String(t.id), t])),
);

const filters = reactive({
  tag_id: [] as number[],
  username: '',
});

const searched = ref(false);

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<TagMemberRow>(
    ({ page, per_page }) => {
      searched.value = true;
      return getTagMemberListApi({
        page,
        per_page,
        tag_id: filters.tag_id,
        username: filters.username,
      });
    },
    { perPage: PER_PAGE, showError: true },
  );

/** 將 tag_ids（CSV）轉成標籤物件 */
function memberTags(row: TagMemberRow) {
  return String(row.tag_ids ?? '')
    .split(',')
    .map((s) => tagMap.value.get(s.trim()))
    .filter(Boolean) as TagRow[];
}

function handleClear() {
  filters.username = '';
  filters.tag_id = [];
  list.value = [];
  total.value = 0;
  searched.value = false;
}

/* ---- 編輯會員標籤 ---- */
const editVisible = ref(false);
const saving = ref(false);
const editMemberId = ref(0);
const editUsername = ref('');
const editTags = ref<number[]>([]);

function openEdit(row: TagMemberRow) {
  editMemberId.value = row.id;
  editUsername.value = row.username ?? '';
  editTags.value = String(row.tag_ids ?? '')
    .split(',')
    .map((s) => Number(s.trim()))
    .filter((n) => Number.isFinite(n) && n > 0);
  editVisible.value = true;
}

async function handleSave() {
  saving.value = true;
  try {
    const res = await saveMemberTagsApi({
      member_id: editMemberId.value,
      tags: editTags.value,
    });
    if (Number(res.code) === 1000) {
      editVisible.value = false;
      loadData();
    } else {
      // 2002 顯示 data，其餘顯示 message
      ElMessage.error(
        Number(res.code) === 2002
          ? String(res.data ?? res.message)
          : res.message,
      );
    }
  } finally {
    saving.value = false;
  }
}

// 不自動查詢（act=1 後才查），先載入啟用標籤清單
function loadTags() {
  getTagListApi({ status: 1 })
    .then((d) => {
      tags.value = d ?? [];
    })
    .catch(() => {});
}
onMounted(loadTags);
</script>

<template>
  <Page :title="`${tl('member_lower_management')} > ${tl('menu_member_tag')}`">
    <div class="mb-3">
      <ElButton type="info" @click="settingRef?.open()">
        {{ tl('tag_setting') }}
      </ElButton>
    </div>

    <!-- 查詢 -->
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('member_account')">
            <ElInput v-model="filters.username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('tag')">
            <ElSelect v-model="filters.tag_id" class="w-full" multiple>
              <ElOption
                v-for="t in tags"
                :key="t.id"
                :label="t.name"
                :value="t.id"
              />
            </ElSelect>
          </ElFormItem>
        </div>
        <div class="flex justify-end gap-2">
          <ElButton @click="handleClear">{{ tl('clear') }}</ElButton>
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
          :label="tl('member_account')"
          align="center"
          min-width="120"
        >
          <template #default="{ row }">
            <MemberAccountLink :username="row.username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('current_tags')"
          align="center"
          min-width="200"
        >
          <template #default="{ row }">
            <template v-if="memberTags(row as TagMemberRow).length > 0">
              <span
                v-for="(t, i) in memberTags(row as TagMemberRow)"
                :key="i"
                :style="{
                  backgroundColor: t.background_color,
                  color: t.text_color,
                }"
                class="mr-1 rounded px-1"
              >
                {{ t.name }}
              </span>
            </template>
            <template v-else>-</template>
          </template>
        </ElTableColumn>
        <ElTableColumn align="center" width="100" fixed="right">
          <template #default="{ row }">
            <ElButton
              size="small"
              type="info"
              @click="openEdit(row as TagMemberRow)"
            >
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

    <!-- 編輯會員標籤 -->
    <ElDialog
      v-model="editVisible"
      :title="tl('tag_setting')"
      width="560"
      align-center
    >
      <ElForm label-width="auto" @submit.prevent="handleSave">
        <ElFormItem :label="tl('member_account')">
          <span>{{ editUsername }}</span>
        </ElFormItem>
        <ElFormItem :label="tl('tag')">
          <ElSelect v-model="editTags" class="w-full" multiple>
            <ElOption
              v-for="t in tags"
              :key="t.id"
              :label="t.name"
              :value="t.id"
            />
          </ElSelect>
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton :loading="saving" type="primary" @click="handleSave">
          {{ tl('btn_update') }}
        </ElButton>
      </template>
    </ElDialog>

    <!-- 標籤設定（標籤 CRUD 清單彈窗；關閉後刷新標籤下拉） -->
    <TagSettingDialog ref="settingRef" @closed="loadTags" />
  </Page>
</template>
