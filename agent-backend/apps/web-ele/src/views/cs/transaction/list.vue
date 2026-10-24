<script lang="ts" setup>
import type { CsRedEnvelopeRow, CsTransactionRow } from '#/api/cs';

import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';

import { Page } from '@vben/common-ui';
import { usePreferences } from '@vben/preferences';

import dayjs from 'dayjs';
import {
  ElButton,
  ElCard,
  ElDatePicker,
  ElEmpty,
  ElForm,
  ElFormItem,
  ElInput,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  getCsRedEnvelopeListApi,
  getCsTransactionListApi,
  runCsBootstrap,
} from '#/api/cs';
import AgentAccount from '#/components/agent-account.vue';
import { tl } from '#/locales';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'CsTransactionLog' });

const { isMobile } = usePreferences();
const route = useRoute();

const form = reactive({
  end: dayjs().format('YYYY-MM-DD HH:mm'),
  start: dayjs().subtract(1, 'day').format('YYYY-MM-DD HH:mm'),
  type: 1 as 1 | 2, // 1=開洗分 2=紅包
  username: (route.query.username as string) ?? '',
});

const loading = ref(false);
const bootstrapError = ref(false);
const listType = ref<'redenv' | 'transaction'>('transaction');
const txRows = ref<CsTransactionRow[]>([]);
const reRows = ref<CsRedEnvelopeRow[]>([]);
const keyword = ref('');

// label 在 txTypeText/txStatusText/reTypeText 渲染時以 tl() 解析（避免模組載入凍結）
// 開洗分紀錄的類型顯示「開分/洗分」，比照原本客服（非通用的轉入/轉出用詞）
const TX_TYPE_KEY: Record<number, string> = {
  1: 'list.transfer_in',
  2: 'list.transfer_out',
  3: 'list.transfer_gift',
};
const TX_STATUS_KEY: Record<number, string> = {
  0: 'list.failed',
  1: 'list.success',
  2: 'reject',
  3: 'expired',
  4: 'pending',
};
const RE_TYPE_KEY: Record<number, string> = {
  1: 'cs.send_redenvelope',
  2: 'list.receive_redEnvelope',
};
function txTypeText(t: number) {
  const k = TX_TYPE_KEY[t];
  return k ? tl(k) : t;
}
function txStatusText(s: number) {
  const k = TX_STATUS_KEY[s];
  return k ? tl(k) : s;
}
function reTypeText(t: number) {
  const k = RE_TYPE_KEY[t];
  return k ? tl(k) : t;
}

function matchKeyword(row: Record<string, any>): boolean {
  const kw = keyword.value.trim().toLowerCase();
  if (!kw) return true;
  return Object.values(row).some((v) =>
    String(v ?? '')
      .toLowerCase()
      .includes(kw),
  );
}
const txFiltered = computed(() => txRows.value.filter((r) => matchKeyword(r)));
const reFiltered = computed(() => reRows.value.filter((r) => matchKeyword(r)));

/** 不吞錯誤的原始版本，bootstrap() 才需要知道有沒有真的失敗（供重試判斷） */
async function fetchSearch() {
  const payload = {
    end_time: `${form.end}:00`,
    start_time: `${form.start}:00`,
    username: form.username,
  };
  if (form.type === 1) {
    txRows.value = await getCsTransactionListApi(payload);
    listType.value = 'transaction';
  } else {
    reRows.value = await getCsRedEnvelopeListApi(payload);
    listType.value = 'redenv';
  }
}
async function doSearch() {
  loading.value = true;
  try {
    await fetchSearch();
  } catch {
    txRows.value = [];
    reRows.value = [];
  } finally {
    loading.value = false;
  }
}

async function bootstrap() {
  loading.value = true;
  bootstrapError.value = false;
  try {
    await runCsBootstrap(fetchSearch);
  } catch {
    bootstrapError.value = true;
  } finally {
    loading.value = false;
  }
}
onMounted(bootstrap);
</script>

<template>
  <Page :title="tl('menu.transaction_log')">
    <ElCard shadow="never" class="mb-4">
      <ElForm :inline="!isMobile" @submit.prevent="doSearch">
        <ElFormItem :label="tl('form.username')">
          <ElInput
            v-model="form.username"
            clearable
            :placeholder="tl('member_account')"
          />
        </ElFormItem>
        <ElFormItem :label="tl('type')">
          <ElSelect v-model="form.type" style="width: 140px">
            <ElOption :value="1" :label="tl('list.transaction')" />
            <ElOption :value="2" :label="tl('redEnvelope.redEnvelope')" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="tl('cs.start')">
          <ElDatePicker
            v-model="form.start"
            type="datetime"
            format="YYYY-MM-DD HH:mm"
            value-format="YYYY-MM-DD HH:mm"
          />
        </ElFormItem>
        <ElFormItem :label="tl('cs.end')">
          <ElDatePicker
            v-model="form.end"
            type="datetime"
            format="YYYY-MM-DD HH:mm"
            value-format="YYYY-MM-DD HH:mm"
          />
        </ElFormItem>
        <ElFormItem>
          <ElButton type="primary" :loading="loading" native-type="submit">
            {{ tl('search') }}
          </ElButton>
        </ElFormItem>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <div class="mb-3 flex items-center">
        <h3 class="m-0 text-base font-medium">
          {{
            listType === 'transaction'
              ? tl('cs.wash_record')
              : tl('cs.redenvelope_record')
          }}
        </h3>
        <div class="flex-1"></div>
        <ElInput
          v-model="keyword"
          :placeholder="tl('cs.search_in_table')"
          clearable
          style="max-width: 240px"
        />
      </div>

      <div v-if="bootstrapError" class="flex flex-col items-center gap-3 py-16">
        <span class="text-muted-foreground">{{ tl('cs.load_failed') }}</span>
        <ElButton type="primary" @click="bootstrap">
          {{ tl('retry') }}
        </ElButton>
      </div>
      <!-- 開洗分紀錄 -->
      <ElTable
        v-else-if="listType === 'transaction'"
        v-loading="loading"
        :data="txFiltered"
        border
        size="small"
        max-height="560"
      >
        <ElTableColumn
          prop="created_at"
          :label="tl('list.date')"
          width="160"
          sortable
        />
        <ElTableColumn prop="id" :label="tl('list.transfer_id')" width="100" />
        <ElTableColumn :label="tl('type')" width="80">
          <template #default="{ row }">
            <span :class="{ 'text-red-500': row.type === 3 }">
              {{ txTypeText(row.type) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('form.username')">
          <template #default="{ row }">
            <MemberAccountLink :username="row.member_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn prop="member_nickname" :label="tl('form.nickname')" />
        <ElTableColumn
          prop="amount"
          :label="tl('list.amount/point')"
          width="110"
        />
        <ElTableColumn :label="tl('list.status')" width="90">
          <template #default="{ row }">{{ txStatusText(row.status) }}</template>
        </ElTableColumn>
        <ElTableColumn
          prop="user_username"
          :label="tl('operate_agent_username')"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.user_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          prop="remark"
          :label="tl('form.remarks')"
          show-overflow-tooltip
        />
        <template #empty>
          <ElEmpty :description="tl('no_data')" />
        </template>
      </ElTable>

      <!-- 紅包紀錄 -->
      <ElTable
        v-else
        v-loading="loading"
        :data="reFiltered"
        border
        size="small"
        max-height="560"
      >
        <ElTableColumn
          prop="created_at"
          :label="tl('list.date')"
          width="160"
          sortable
        />
        <ElTableColumn :label="tl('type')" width="90">
          <template #default="{ row }">{{ reTypeText(row.type) }}</template>
        </ElTableColumn>
        <!-- 工單4342：會員帳號欄改掛 MemberAccountLink（可點開會員詳情＋複製 icon） -->
        <ElTableColumn prop="self_username" :label="tl('form.username')">
          <template #default="{ row }">
            <MemberAccountLink :username="row.self_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          prop="other_username"
          :label="tl('cs.counterparty_account')"
        >
          <template #default="{ row }">
            <MemberAccountLink :username="row.other_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          prop="amount"
          :label="tl('list.amount/point')"
          width="110"
        />
        <template #empty>
          <ElEmpty :description="tl('no_data')" />
        </template>
      </ElTable>
    </ElCard>
  </Page>
</template>
