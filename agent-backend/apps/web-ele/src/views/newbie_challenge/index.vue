<script lang="ts" setup>
import type { NewbieChallengeConfigRow } from '#/api';

import { computed, onMounted, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElMessage,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  getNewbieChallengeConfigListApi,
  updateNewbieChallengeConfigStatusApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';

import ConfigEditDialog from './components/config-edit-dialog.vue';

defineOptions({ name: 'NewbieChallengeSetting' });

const PER_PAGE = 20;

const loading = ref(false);
const toggling = ref<number | string>('');
const page = ref(1);
const total = ref(0);
const list = ref<NewbieChallengeConfigRow[]>([]);

const editDialog = ref<InstanceType<typeof ConfigEditDialog>>();

async function loadData() {
  loading.value = true;
  try {
    const res = await getNewbieChallengeConfigListApi({
      page: page.value,
      per_page: PER_PAGE,
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

function handlePageChange(p: number) {
  page.value = p;
  loadData();
}

async function toggleStatus(row: NewbieChallengeConfigRow) {
  toggling.value = row.id;
  try {
    const res = await updateNewbieChallengeConfigStatusApi({
      config_id: row.id,
      status: Number(row.status) === 1 ? 0 : 1,
    });
    const code = String(res.code);
    if (code === '200') {
      await loadData();
      return;
    }
    ElMessage.error(
      code === '400' ? (res.message ?? '') : String(res.data ?? ''),
    );
  } finally {
    toggling.value = '';
  }
}

const title = computed(
  () =>
    `${tl('menu_newbie_challenge')} > ${tl('newbie_challenge_config_page')}`,
);

onMounted(loadData);
</script>

<template>
  <Page :title="title">
    <ElCard class="mb-3" shadow="never">
      <ElButton type="success" @click="editDialog?.open()">
        {{ tl('event_create') }}
      </ElButton>
    </ElCard>

    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn
          :label="tl('newbie_challenge_apply_agent')"
          min-width="130"
        >
          <template #default="{ row }">
            <template v-if="row.agent_id === null">
              {{ tl('newbie_challenge_whole_site') }}
            </template>
            <AgentAccount
              v-else-if="row.agent_username"
              :username="row.agent_username"
            />
            <template v-else>{{ row.agent_id }}</template>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('newbie_challenge_activity_amount')"
          align="right"
          min-width="110"
        >
          <template #default="{ row }">
            {{ amountThousands(row.activity_amount) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('newbie_challenge_challenge_flow_multiple')"
          align="right"
          min-width="130"
        >
          <template #default="{ row }">
            {{ amountThousands(row.challenge_flow_multiple) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('newbie_challenge_bonus_flow_multiple')"
          align="right"
          min-width="130"
        >
          <template #default="{ row }">
            {{ amountThousands(row.bonus_flow_multiple) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('newbie_challenge_min_unlock_amount')"
          align="right"
          min-width="120"
        >
          <template #default="{ row }">
            {{ amountThousands(row.min_unlock_amount) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('newbie_challenge_max_unlock_amount')"
          align="right"
          min-width="120"
        >
          <template #default="{ row }">
            {{ amountThousands(row.max_unlock_amount) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('newbie_challenge_sort')"
          align="right"
          min-width="110"
        >
          <template #default="{ row }">{{ row.sort ?? 0 }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('label_start_time')" min-width="160">
          <template #default="{ row }">{{ row.start_time ?? '—' }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('label_end_time')" min-width="160">
          <template #default="{ row }">{{ row.end_time ?? '—' }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('status')" min-width="90">
          <template #default="{ row }">
            <b
              :class="
                Number(row.status) === 1 ? 'text-green-600' : 'text-red-500'
              "
            >
              {{ Number(row.status) === 1 ? tl('enable') : tl('disable') }}
            </b>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('features')"
          align="center"
          fixed="right"
          width="150"
        >
          <template #default="{ row }">
            <div class="flex justify-center gap-1">
              <ElButton size="small" @click="editDialog?.open(row.id)">
                {{ tl('edit') }}
              </ElButton>
              <ElButton
                :loading="toggling === row.id"
                :type="Number(row.status) === 1 ? 'danger' : 'success'"
                size="small"
                @click="toggleStatus(row as NewbieChallengeConfigRow)"
              >
                {{ Number(row.status) === 1 ? tl('disable') : tl('enable') }}
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

    <ConfigEditDialog ref="editDialog" @saved="loadData" />
  </Page>
</template>
