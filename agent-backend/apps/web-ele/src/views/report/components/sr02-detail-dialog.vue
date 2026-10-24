<script lang="ts" setup>
import type { TransactionLog } from '#/api';

import { computed, ref } from 'vue';

import { useMediaQuery } from '@vueuse/core';
import { ElDescriptions, ElDescriptionsItem, ElDialog } from 'element-plus';

import { getTransactionLogApi } from '#/api';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';
import { fromApiTime } from '#/utils/timezone-shift';

defineOptions({ name: 'Sr02DetailDialog' });

const visible = ref(false);
const loading = ref(false);
const record = ref<TransactionLog>({});

/** 手機寬度下兩欄會把每格壓到只剩幾個字，單號一類的長值改成一欄一列才讀得完 */
const isMobile = useMediaQuery('(max-width: 768px)');
const columns = computed(() => (isMobile.value ? 1 : 2));

/** type==4 且無上層代理 → 顯示存款金流/訂單類型/真實姓名 */
const showDepositInfo = computed(
  () => record.value.type === 4 && !record.value.to_agent_username,
);

const fmt = (v?: number | string) => amountThousands(v, 2, '-');

async function open(id: number | string, type: number) {
  visible.value = true;
  loading.value = true;
  record.value = {};
  try {
    const res = await getTransactionLogApi({ id, type });
    if (Number(res.code) === 1000) {
      record.value = res.data ?? {};
    }
  } finally {
    loading.value = false;
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="tl('detailinfo')"
    width="820"
    align-center
  >
    <ElDescriptions v-loading="loading" :column="columns" border>
      <ElDescriptionsItem :label="tl('num')">
        {{ record.id ?? '-' }}
      </ElDescriptionsItem>
      <ElDescriptionsItem :label="tl('date')">
        {{ fromApiTime(record.created_at) || '-' }}
      </ElDescriptionsItem>
      <ElDescriptionsItem :label="tl('type')">
        {{ record.type == null ? '-' : tl(`transaction_type.${record.type}`) }}
      </ElDescriptionsItem>
      <ElDescriptionsItem :label="tl('account_nickname')">
        {{ record.bank_nickname || '-' }}
      </ElDescriptionsItem>
      <ElDescriptionsItem :label="tl('ordernum')">
        {{ record.transaction_no || '-' }}
      </ElDescriptionsItem>
      <ElDescriptionsItem :label="tl('third_ordernum')">
        {{ record.third_party_order_no || '-' }}
      </ElDescriptionsItem>

      <ElDescriptionsItem
        v-if="showDepositInfo"
        :label="tl('deposit_vendor_name')"
      >
        {{ record.cf_name || '-' }}
      </ElDescriptionsItem>
      <ElDescriptionsItem
        v-if="showDepositInfo"
        :label="tl('deposit_order_type_name')"
      >
        {{ record.order_type_name || '-' }}
      </ElDescriptionsItem>
      <ElDescriptionsItem
        v-if="showDepositInfo"
        :label="tl('real_name')"
        :span="columns"
      >
        {{ record.from_account_name || '-' }}
      </ElDescriptionsItem>

      <ElDescriptionsItem :label="tl('agent_account')">
        {{ record.agent_username || '-' }}
      </ElDescriptionsItem>
      <ElDescriptionsItem
        v-if="record.to_agent_username"
        :label="tl('agent_account_to')"
      >
        {{ record.to_agent_username }}
      </ElDescriptionsItem>
      <ElDescriptionsItem v-else :label="tl('member_account')">
        {{ record.member_username || '-' }}
      </ElDescriptionsItem>

      <ElDescriptionsItem :label="tl('change_point')">
        {{ fmt(record.point) }}
      </ElDescriptionsItem>
      <ElDescriptionsItem :label="tl('moment_point')">
        {{ fmt(record.balance) }}
      </ElDescriptionsItem>
      <ElDescriptionsItem :label="tl('source')">
        {{ record.source || '-' }}
      </ElDescriptionsItem>
      <ElDescriptionsItem :label="tl('execution_account')">
        {{ record.operator_username || '-' }}
      </ElDescriptionsItem>
      <ElDescriptionsItem :label="tl('order_status')">
        {{ record.status || '-' }}
      </ElDescriptionsItem>
    </ElDescriptions>

    <div class="mt-4">
      <div class="text-muted-foreground mb-1 text-xs">{{ tl('memo') }}</div>
      <div class="rounded border p-3 text-sm whitespace-pre-wrap">
        {{ record.remark || '-' }}
      </div>
    </div>
  </ElDialog>
</template>
