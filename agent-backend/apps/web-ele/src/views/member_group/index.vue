<script lang="ts" setup>
import type { MemberGroupRow } from '#/api';

import { ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElFormItem,
  ElInput,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
  ElTag,
} from 'element-plus';

import { getMemberGroupListApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import { useAgentOptions } from '#/composables/use-agent-options';
import { tl } from '#/locales';

import MemberGroupEditDialog from './components/member-group-edit-dialog.vue';

defineOptions({ name: 'MemberGroupIndex' });

const editDialogRef = ref<InstanceType<typeof MemberGroupEditDialog>>();

const loading = ref(false);
const list = ref<MemberGroupRow[]>([]);

const keyword = ref('');
const filterStatus = ref<number | string>('');
const filterAgentId = ref<number | undefined>(undefined);
const {
  loading: agentSearching,
  options: agentOptions,
  search: searchAgents,
} = useAgentOptions();

async function loadData() {
  loading.value = true;
  try {
    const res = await getMemberGroupListApi({
      page: 1,
      per_page: 100,
      ...(keyword.value.trim() ? { keyword: keyword.value.trim() } : {}),
      ...(filterStatus.value === '' ? {} : { status: filterStatus.value }),
      ...(filterAgentId.value === undefined
        ? {}
        : { agent_id: filterAgentId.value }),
    });
    list.value = Number(res.code) === 200 ? (res.data?.items ?? []) : [];
  } finally {
    loading.value = false;
  }
}

/** -1 = 無限制 */
function limitText(v?: number | string) {
  if (v === undefined || v === null || v === '') return '';
  return Number(v) < 0 ? tl('member_group_no_limit') : String(v);
}

searchAgents('');
loadData();
</script>

<template>
  <Page
    :title="`${tl('member_lower_management')} > ${tl('menu_member_group')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
        <ElFormItem :label="tl('agent_account')" class="!mb-0">
          <ElSelect
            v-model="filterAgentId"
            :debounce="500"
            :loading="agentSearching"
            :placeholder="tl('please_choose')"
            :remote-method="searchAgents"
            class="w-full"
            clearable
            filterable
            remote
          >
            <ElOption
              v-for="a in agentOptions"
              :key="a.id"
              :label="a.username"
              :value="a.id"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="tl('status')" class="!mb-0">
          <ElSelect
            v-model="filterStatus"
            :placeholder="tl('please_choose')"
            class="w-full"
          >
            <ElOption :label="tl('all')" value="" />
            <ElOption :label="tl('enable')" :value="1" />
            <ElOption :label="tl('disable')" :value="0" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="tl('name')" class="!mb-0">
          <ElInput v-model="keyword" clearable @keyup.enter="loadData" />
        </ElFormItem>
      </div>
      <div class="mt-3 flex justify-end">
        <ElButton type="primary" @click="loadData">{{ tl('search') }}</ElButton>
      </div>
    </ElCard>

    <ElCard shadow="never">
      <template #header>
        <div class="flex items-center justify-between">
          <span class="font-medium">{{ tl('menu_member_group') }}</span>
          <ElButton type="success" @click="editDialogRef?.open()">
            {{ tl('add') }}
          </ElButton>
        </div>
      </template>

      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn
          :label="tl('name')"
          align="center"
          prop="name"
          min-width="120"
          sortable
        />
        <ElTableColumn
          :label="tl('agent_account')"
          align="center"
          prop="agent_name"
          min-width="120"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_name" />
          </template>
        </ElTableColumn>
        <ElTableColumn align="center" min-width="100">
          <template #header>
            <div>{{ tl('member_group_min_deposit') }}</div>
            <div>{{ tl('member_group_max_deposit') }}</div>
          </template>
          <template #default="{ row }">
            <div>{{ limitText(row.deposit_min) }}</div>
            <div>{{ limitText(row.deposit_max) }}</div>
          </template>
        </ElTableColumn>
        <ElTableColumn align="center" min-width="100">
          <template #header>
            <div>{{ tl('member_group_min_withdrawal') }}</div>
            <div>{{ tl('member_group_max_withdrawal') }}</div>
          </template>
          <template #default="{ row }">
            <div>{{ limitText(row.withdraw_min) }}</div>
            <div>{{ limitText(row.withdraw_max) }}</div>
          </template>
        </ElTableColumn>
        <ElTableColumn align="center" min-width="100">
          <template #header>
            <div>{{ tl('member_group_daily_withdrawal_count') }}</div>
            <div>{{ tl('daily_withdrawal_amount_limit') }}</div>
          </template>
          <template #default="{ row }">
            <div>{{ limitText(row.withdraw_daily_count) }}</div>
            <div>{{ limitText(row.withdraw_daily_amount) }}</div>
          </template>
        </ElTableColumn>
        <ElTableColumn align="center" min-width="100">
          <template #header>
            <div>{{ tl('member_group_transfer_min') }}</div>
            <div>{{ tl('member_group_transfer_max') }}</div>
          </template>
          <template #default="{ row }">
            <div>{{ limitText(row.transfer_min) }}</div>
            <div>{{ limitText(row.transfer_max) }}</div>
          </template>
        </ElTableColumn>
        <ElTableColumn align="center" min-width="100">
          <template #header>
            <div>{{ tl('member_group_daily_transfer_count') }}</div>
            <div>{{ tl('member_group_daily_transfer_amount') }}</div>
          </template>
          <template #default="{ row }">
            <div>{{ limitText(row.transfer_daily_count) }}</div>
            <div>{{ limitText(row.transfer_daily_amount) }}</div>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('member_group_member_count')"
          align="center"
          prop="member_count"
          width="90"
        />
        <ElTableColumn
          :label="tl('member_group_default')"
          align="center"
          width="90"
        >
          <template #default="{ row }">
            {{
              Number(row.default_setting) === 1
                ? tl('option_yes')
                : tl('option_no')
            }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('status')" align="center" width="90">
          <template #default="{ row }">
            <ElTag :type="Number(row.status) === 1 ? 'success' : 'danger'">
              {{ Number(row.status) === 1 ? tl('enable') : tl('disable') }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn align="center" width="90" fixed="right">
          <template #default="{ row }">
            <ElButton
              size="small"
              type="primary"
              @click="editDialogRef?.open(row.id)"
            >
              {{ tl('edit') }}
            </ElButton>
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
    </ElCard>

    <MemberGroupEditDialog ref="editDialogRef" @saved="loadData" />
  </Page>
</template>
