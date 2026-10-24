<script lang="ts" setup>
import type { NewbieChallengeBlockRow } from '#/api';

import { computed, onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  AGENT_SEARCH_LIMIT,
  allowNewbieChallengeJoinApi,
  getNewbieChallengeBlockListApi,
  searchAgentOptionsApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import FixedTimezoneHint from '#/components/fixed-timezone-hint.vue';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';
import { nowInUserTz } from '#/utils/timezone-shift';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

import BlockDetailDialog from './components/block-detail-dialog.vue';
import { blockStatusClass, blockStatusText, blockTypeText } from './stage-map';

defineOptions({ name: 'NewbieChallengeBlock' });

const PER_PAGE = 10;
/** 頁面口徑固定 GMT+8，時間原樣送出不平移 */
const FIXED_OFFSET = 8;

const filters = reactive({
  agent_username: '',
  member_username: '',
  status: '' as number | string,
  time_range: [
    nowInUserTz(FIXED_OFFSET).format('YYYY-MM-DD 00:00'),
    nowInUserTz(FIXED_OFFSET).format('YYYY-MM-DD 23:59'),
  ] as string[],
});

const loading = ref(false);
const allowing = ref<number | string>('');
const page = ref(1);
const total = ref(0);
const list = ref<NewbieChallengeBlockRow[]>([]);
const agentOptions = ref<{ id: number; username: string }[]>([]);
const agentSearching = ref(false);

const detailDialog = ref<InstanceType<typeof BlockDetailDialog>>();

async function loadData() {
  const [start, end] = filters.time_range ?? [];
  loading.value = true;
  try {
    const res = await getNewbieChallengeBlockListApi({
      agent_username: filters.agent_username,
      end_time: end ?? '',
      member_username: filters.member_username,
      page: page.value,
      per_page: PER_PAGE,
      start_time: start ?? '',
      ...(filters.status === '' ? {} : { status: filters.status }),
    });
    if (Number(res.code) === 200) {
      list.value = res.data?.items ?? [];
      total.value = res.data?.pagination?.total ?? 0;
    } else {
      list.value = [];
      total.value = 0;
      ElMessage.error(res.message ?? '');
    }
  } finally {
    loading.value = false;
  }
}

function search() {
  page.value = 1;
  loadData();
}

function handlePageChange(p: number) {
  page.value = p;
  loadData();
}

async function searchAgents(keyword: string) {
  agentSearching.value = true;
  try {
    const res = await searchAgentOptionsApi(keyword, AGENT_SEARCH_LIMIT);
    agentOptions.value = res.data ?? [];
  } finally {
    agentSearching.value = false;
  }
}

async function handleAllow(row: NewbieChallengeBlockRow) {
  allowing.value = row.id;
  try {
    const res = await allowNewbieChallengeJoinApi(row.id);
    if (Number(res.code) === 200) {
      await loadData();
      return;
    }
    ElMessage.error(res.message ?? '');
  } finally {
    allowing.value = '';
  }
}

const title = computed(
  () => `${tl('menu_newbie_challenge')} > ${tl('newbie_challenge_block_page')}`,
);

onMounted(loadData);
</script>

<template>
  <Page :title="title">
    <ElCard class="mb-3" shadow="never">
      <ElForm label-width="auto" @submit.prevent="search">
        <div
          class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2 lg:grid-cols-4"
        >
          <ElFormItem :label="tl('agent_account')">
            <ElSelect
              v-model="filters.agent_username"
              :loading="agentSearching"
              :remote-method="searchAgents"
              class="!w-full"
              clearable
              filterable
              remote
              remote-show-suffix
            >
              <ElOption
                v-for="o in agentOptions"
                :key="o.id"
                :label="o.username"
                :value="o.username"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('member_account')">
            <ElInput v-model="filters.member_username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('status')">
            <ElSelect v-model="filters.status" class="!w-full">
              <ElOption :label="tl('please_choose')" value="" />
              <ElOption :label="tl('reject_join')" :value="1" />
              <ElOption :label="tl('allow_join')" :value="2" />
              <ElOption :label="tl('joined')" :value="3" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('between')" class="lg:col-span-2">
            <DateRangeFields
              v-model="filters.time_range"
              :fixed-offset="FIXED_OFFSET"
              format="YYYY-MM-DD HH:mm"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm"
            />
            <FixedTimezoneHint text-key="fixed_tz_hint_no_shift" />
          </ElFormItem>
        </div>
        <div class="flex items-center justify-end">
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn
          :label="tl('agent_name')"
          prop="agent_username"
          min-width="120"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('member_name')" min-width="120">
          <template #default="{ row }">
            <MemberAccountLink :username="row.member_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('reject_reason')" min-width="160">
          <template #default="{ row }">
            {{ blockTypeText(row.block_type) }}
          </template>
        </ElTableColumn>
        <ElTableColumn label="IP" prop="ip" min-width="130" />
        <ElTableColumn :label="tl('reject_time')" min-width="170">
          <template #default="{ row }">
            {{ row.block_updated_at ?? '—' }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('allow_time')" min-width="170">
          <template #default="{ row }">{{ row.allowed_time ?? '—' }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('status')" min-width="100">
          <template #default="{ row }">
            <b :class="blockStatusClass(row.status)">
              {{ blockStatusText(row.status) }}
            </b>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('operate')"
          align="center"
          fixed="right"
          width="190"
        >
          <template #default="{ row }">
            <div class="flex justify-center gap-1">
              <ElButton
                v-if="Number(row.status) === 1"
                :loading="allowing === row.id"
                size="small"
                type="primary"
                @click="handleAllow(row as NewbieChallengeBlockRow)"
              >
                {{ tl('allow_join') }}
              </ElButton>
              <ElButton size="small" @click="detailDialog?.open(row.id)">
                {{ tl('detail_record') }}
              </ElButton>
            </div>
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

    <BlockDetailDialog ref="detailDialog" />
  </Page>
</template>
