<script lang="ts" setup>
import type { Sr02Limit5Row } from '#/api';

import { ref } from 'vue';

import { ElDialog, ElTable, ElTableColumn } from 'element-plus';

import { getSr02Limit5Api } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'Sr02Limit5Dialog' });

const visible = ref(false);
const loading = ref(false);
const list = ref<Sr02Limit5Row[]>([]);

/** 代理洗分單傳代理帳號並帶 isAgent，會員單維持傳會員帳號（target/tickets#4555） */
/**
 * 代理列用 agent_transaction_type（十一種代理類型都有翻譯），會員列用 transaction_type。
 *
 * 後端的 `type` 是把代理類型轉成會員值域的結果，只對得到開分與洗分，其餘九種（洗分退還、
 * 佔成錢包轉帳、GGR 分潤…）是 null，直接拼 key 會在畫面印出字面的「transaction_type.null」。
 * `agent_type` 是代理列才有的原始值（target/tickets#4555）。 */
function transactionTypeText(row: Sr02Limit5Row): string {
  // ?? 同時涵蓋 null（會員列，後端回的 NULL）與 undefined（舊版後端還沒有這一欄）
  const agentType = row.agent_type ?? null;

  return agentType === null
    ? tl(`transaction_type.${row.type}`)
    : tl(`agent_transaction_type.${agentType}`);
}

async function open(username: string, isAgent = false) {
  visible.value = true;
  loading.value = true;
  list.value = [];
  try {
    const res = await getSr02Limit5Api(username, isAgent);
    list.value = Number(res.code) === 1000 ? (res.data ?? []) : [];
  } finally {
    loading.value = false;
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="tl('menu_report_sr02_limit5')"
    width="1100"
    align-center
  >
    <ElTable v-loading="loading" :data="list" border size="small" stripe>
      <ElTableColumn :label="tl('num')" align="center" prop="id" width="60" />
      <ElTableColumn :label="tl('ordernum')" align="center" min-width="140">
        <template #default="{ row }">
          <div>{{ row.transaction_no }}</div>
          <div v-if="row.third_party_order_no" class="text-gray-400">
            {{ row.third_party_order_no }}
          </div>
        </template>
      </ElTableColumn>
      <ElTableColumn
        :label="tl('date')"
        align="center"
        prop="created_at"
        width="160"
      />
      <ElTableColumn
        :label="tl('agent_account')"
        align="center"
        min-width="110"
      >
        <template #default="{ row }">
          <b class="text-green-600">
            <AgentAccount :username="row.agent_username" />
          </b>
        </template>
      </ElTableColumn>
      <ElTableColumn
        :label="`${tl('deposit')}/${tl('withdrawal')}`"
        align="center"
        min-width="100"
      >
        <template #default="{ row }">
          {{ transactionTypeText(row) }}
        </template>
      </ElTableColumn>
      <ElTableColumn
        :label="tl('agent_account_to')"
        align="center"
        prop="to_agent_username"
        min-width="110"
      >
        <template #default="{ row }">
          <AgentAccount :username="row.to_agent_username" />
        </template>
      </ElTableColumn>
      <ElTableColumn
        :label="tl('member_account')"
        align="center"
        min-width="110"
      >
        <template #default="{ row }">
          <b
            :class="
              Number(row.platform) === 1 ? 'text-blue-500' : 'text-green-600'
            "
          >
            <MemberAccountLink :username="row.member_username" />
          </b>
          <div v-if="row.member_first_name">
            <b>{{ row.member_first_name }}</b>
          </div>
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('change_point')" align="right" min-width="100">
        <template #default="{ row }">{{ amountThousands(row.point) }}</template>
      </ElTableColumn>
      <ElTableColumn :label="tl('moment_point')" align="right" min-width="100">
        <template #default="{ row }">
          {{ amountThousands(row.balance) }}
        </template>
      </ElTableColumn>
      <ElTableColumn
        :label="tl('source')"
        align="center"
        prop="source"
        min-width="90"
      />
      <ElTableColumn
        :label="tl('execution_account')"
        align="center"
        prop="operator_username"
        min-width="110"
      >
        <template #default="{ row }">
          <AgentAccount :username="row.operator_username" />
        </template>
      </ElTableColumn>
      <ElTableColumn
        :label="tl('account_nickname')"
        align="center"
        prop="status"
        min-width="100"
      />
      <template #empty>{{ tl('no_record') }}</template>
    </ElTable>
  </ElDialog>
</template>
