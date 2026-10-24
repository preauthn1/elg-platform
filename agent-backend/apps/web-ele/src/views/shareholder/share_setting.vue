<script lang="ts" setup>
import type { ShareholderAgentRow } from '#/api';

import { computed, onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';
import { useUserStore } from '@vben/stores';

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
  ElTag,
} from 'element-plus';

import { getShareholderAgentApi, setShareholderPointShareApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import CopyIcon from '#/components/copy-icon.vue';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';

defineOptions({ name: 'ShareholderShareSetting' });

const userStore = useUserStore();
const isHost = computed(
  () => Number((userStore.userInfo as any)?.host_id) === 1,
);

const PER_PAGE = 50;
const filters = reactive({
  agent_username: '',
  all_agent: 0 as number | string,
  previous_agent_username: '',
});

const loading = ref(false);
const rows = ref<ShareholderAgentRow[]>([]);
const total = ref(0);
const page = ref(1);
const meta = reactive({
  general_agent: 0 as number | string,
  modify: 0 as number | string,
  my_shares: '',
  my_superior: '',
});
/** 各列目前輸入的股份值（key = agent_id） */
const draft = reactive<Record<string, number | string>>({});

const showAllAgentFilter = computed(
  () => Number(meta.general_agent) === 1 || isHost.value,
);
const canModify = computed(() => Number(meta.modify) === 1);

async function loadData() {
  loading.value = true;
  try {
    const res = await getShareholderAgentApi({
      agent_username: filters.agent_username.trim(),
      all_agent: filters.all_agent,
      page: page.value,
      per_page: PER_PAGE,
      previous_agent_username: filters.previous_agent_username.trim(),
    });
    if (Number(res.code) === 1000 && res.data) {
      rows.value = res.data.data ?? [];
      meta.modify = Number(res.data.modify ?? 0);
      meta.my_superior = res.data.my_superior ?? '';
      meta.my_shares = String(res.data.my_shares ?? '');
      meta.general_agent = Number(res.data.general_agent ?? 0);
      total.value = res.pagination?.total ?? rows.value.length;
      for (const r of rows.value)
        draft[String(r.agent_id)] = r.point_share ?? '';
    } else {
      rows.value = [];
      total.value = 0;
      if (res.message) ElMessage.warning(res.message);
    }
  } finally {
    loading.value = false;
  }
}

function handleSearch() {
  page.value = 1;
  loadData();
}
function handlePage(p: number) {
  page.value = p;
  loadData();
}
function handleClear() {
  filters.previous_agent_username = '';
  filters.agent_username = '';
  filters.all_agent = 0;
  handleSearch();
}

/** host 點下線帳號 → 以該帳號當上層往下查 */
function drillDown(username?: string) {
  if (!isHost.value || !username) return;
  filters.previous_agent_username = username;
  filters.agent_username = '';
  handleSearch();
}

function isChanged(r: ShareholderAgentRow) {
  return (
    String(draft[String(r.agent_id)] ?? '') !== String(r.point_share ?? '')
  );
}

/** 股份只允許整數（op_tickets#3650） */
const onlyInt = (v: number | string) => String(v).replaceAll(/\D/g, '');

async function save(r: ShareholderAgentRow) {
  const share = Number(draft[String(r.agent_id)]);
  if (share < Number(r.lower_limit)) {
    ElMessage.warning(tl('below_the_lower_limit'));
    return;
  }
  if (share > Number(r.upper_limit)) {
    ElMessage.warning(tl('below_the_upper_limit'));
    return;
  }
  const res = await setShareholderPointShareApi({
    agent_id: r.agent_id,
    id: r.id ?? '',
    share,
  });
  if (Number(res.code) === 1000) {
    ElMessage.success(tl('success'));
    r.point_share = share;
  } else {
    ElMessage.error(res.message);
  }
}

onMounted(loadData);
</script>

<template>
  <Page
    :title="`${tl('menu_shareholder')} > ${tl('shareholder_share_setting')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="handleSearch">
        <div class="flex flex-wrap items-center gap-4">
          <ElFormItem v-if="isHost" :label="tl('agent_previous')" class="!mb-0">
            <ElInput v-model="filters.previous_agent_username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('agent_account')" class="!mb-0">
            <ElInput v-model="filters.agent_username" clearable />
          </ElFormItem>
          <ElFormItem
            v-if="showAllAgentFilter"
            :label="tl('show_all_agent')"
            class="!mb-0"
          >
            <ElSelect v-model="filters.all_agent" class="!w-32">
              <ElOption :label="tl('option_yes')" :value="1" />
              <ElOption :label="tl('option_no')" :value="0" />
            </ElSelect>
          </ElFormItem>
          <ElButton @click="handleClear">{{ tl('clear') }}</ElButton>
          <ElButton :loading="loading" type="primary" @click="handleSearch">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <div v-if="!isHost && (meta.my_superior || meta.my_shares)" class="mb-3">
        <span class="mr-6">
          {{ tl('my_superior') }}:
          <ElTag type="info">{{ meta.my_superior }}</ElTag>
        </span>
        <span>
          {{ tl('my_shares') }}:
          <ElTag type="info">{{ meta.my_shares }}</ElTag>
        </span>
      </div>

      <ElTable v-loading="loading" :data="rows" border size="small" stripe>
        <ElTableColumn
          :label="tl('upper')"
          min-width="120"
          prop="upper_username"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.upper_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('agent_account')" min-width="160">
          <template #default="{ row }">
            <span>
              <a
                v-if="isHost"
                class="text-primary cursor-pointer"
                @click="drillDown(row.username)"
              >
                {{ row.username }}
              </a>
              <span v-else>{{ row.username }}</span>
              <CopyIcon :text="row.username" />
            </span>
            <span class="text-muted-foreground ml-1 text-xs">
              {{ tl('id') }} {{ row.agent_id }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('bucket_ratio')" min-width="200">
          <template #default="{ row }">
            <div class="flex items-center gap-2">
              <ElInput
                v-model="draft[String(row.agent_id)]"
                :disabled="!canModify"
                class="!w-24"
                :formatter="onlyInt"
                :parser="onlyInt"
              />
              <span class="text-muted-foreground text-xs">
                {{ row.lower_limit }} ~ {{ row.upper_limit }}
              </span>
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('features')"
          align="center"
          width="120"
          fixed="right"
        >
          <template #default="{ row }">
            <ElButton
              v-if="canModify && isChanged(row as ShareholderAgentRow)"
              size="small"
              type="success"
              @click="save(row as ShareholderAgentRow)"
            >
              {{ tl('btn_update') }}
            </ElButton>
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
      <TablePagination
        :current-page="page"
        :page-size="PER_PAGE"
        :total="total"
        @current-change="handlePage"
      />
    </ElCard>
  </Page>
</template>
