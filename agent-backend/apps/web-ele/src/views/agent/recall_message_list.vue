<script lang="ts" setup>
import type { RecallMessageRow } from '#/api';

import { onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElDialog,
  ElForm,
  ElFormItem,
  ElImage,
  ElInput,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getRecallMessageListApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { fromApiTime, nowInUserTz, toApiTime } from '#/utils/timezone-shift';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'AgentRecallMessageList' });

const PER_PAGE = 10;

/** 預設時間：當下 +8h 之當日 00:00 ~ 23:59 */
function defaultRange(): [string, string] {
  const base = nowInUserTz().add(8, 'hour');
  return [base.format('YYYY-MM-DD 00:00'), base.format('YYYY-MM-DD 23:59')];
}

const filters = reactive({
  agent_username: '',
  member_username: '',
  time_range: defaultRange(),
});

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<RecallMessageRow>(
    ({ page, per_page }) => {
      const [start, end] = filters.time_range ?? [];
      return getRecallMessageListApi({
        agent_username: filters.agent_username,
        end_time: toApiTime(end),
        member_username: filters.member_username,
        page,
        per_page,
        start_time: toApiTime(start),
      });
    },
    { perPage: PER_PAGE, showError: true },
  );

function handleClear() {
  filters.agent_username = '';
  filters.member_username = '';
  filters.time_range = defaultRange();
  search();
}

// 圖片預覽
const imageVisible = ref(false);
const imageSrc = ref('');
function showImage(src: string) {
  imageSrc.value = src;
  imageVisible.value = true;
}

onMounted(loadData);
</script>

<template>
  <Page
    :title="`${tl('agnet_management')} > ${tl('menu_cs_recall_message_list')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('agent_account')">
            <ElInput v-model="filters.agent_username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('member_account')">
            <ElInput v-model="filters.member_username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('search_time')" class="md:col-span-2">
            <DateRangeFields
              v-model="filters.time_range"
              format="YYYY-MM-DD HH:mm"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm"
            />
          </ElFormItem>
        </div>
        <div class="flex items-center justify-end">
          <div class="flex gap-2">
            <ElButton @click="handleClear">{{ tl('clear') }}</ElButton>
            <ElButton :loading="loading" native-type="submit" type="primary">
              {{ tl('search') }}
            </ElButton>
          </div>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn
          :label="tl('recall_time')"
          min-width="160"
          :formatter="(row: RecallMessageRow) => fromApiTime(row.recalled_at)"
        />
        <ElTableColumn
          :label="tl('agent_account')"
          min-width="140"
          prop="agent_username"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('member_account')" min-width="140">
          <template #default="{ row }">
            <MemberAccountLink :username="row.member_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('recall_content')" min-width="240">
          <template #default="{ row }">
            <ElImage
              v-if="Number(row.type) === 2"
              :src="row.content"
              class="h-20 w-28 cursor-pointer"
              fit="contain"
              @click="showImage(row.content)"
            />
            <span v-else class="whitespace-pre-wrap">{{ row.content }}</span>
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

    <ElDialog v-model="imageVisible" width="600">
      <ElImage :src="imageSrc" class="w-full" fit="contain" />
    </ElDialog>
  </Page>
</template>
