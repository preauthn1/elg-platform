<script lang="ts" setup>
import type { ProfitSharePaymentFee, ProfitShareVendorFee } from '#/api';

import { computed, onMounted, ref } from 'vue';

import { Page } from '@vben/common-ui';
import { useUserStore } from '@vben/stores';

import {
  ElButton,
  ElCard,
  ElInput,
  ElMessage,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { batchCostSettingApi, getProfitShareFeeListApi } from '#/api';
import { tl } from '#/locales';
import { hasMenuCode } from '#/router/agent-menu';

defineOptions({ name: 'ProfitShareFeeList' });

const userStore = useUserStore();
/** role_id 1/11/12 才顯示 id 欄 */
const showId = computed(() =>
  [1, 11, 12].includes(Number((userStore.userInfo as any)?.role_id)),
);

/** 編輯權限（獨立權限，預設關閉） */
const canEdit = hasMenuCode('profit_share_fee_list_edit_permission');

const loading = ref(false);
const vendors = ref<ProfitShareVendorFee[]>([]);
const payments = ref<ProfitSharePaymentFee[]>([]);

const vendorEditing = ref(false);
const paymentEditing = ref(false);
const saving = ref(false);
/** 取消時還原用的原始費用快照 */
let vendorSnapshot: Array<number | string | undefined> = [];
let paymentSnapshot: Array<number | string | undefined> = [];

async function loadData() {
  loading.value = true;
  try {
    const res = await getProfitShareFeeListApi();
    if (Number(res.code) === 1000) {
      vendors.value = res.data?.vendor ?? [];
      payments.value = res.data?.payment ?? [];
    } else {
      vendors.value = [];
      payments.value = [];
    }
  } finally {
    loading.value = false;
  }
}

function startVendorEdit() {
  vendorSnapshot = vendors.value.map((r) => r.vendor_fee);
  vendorEditing.value = true;
}
function cancelVendorEdit() {
  vendors.value.forEach((r, i) => {
    r.vendor_fee = vendorSnapshot[i];
  });
  vendorEditing.value = false;
}
function startPaymentEdit() {
  paymentSnapshot = payments.value.map((r) => r.acf_fee);
  paymentEditing.value = true;
}
function cancelPaymentEdit() {
  payments.value.forEach((r, i) => {
    r.acf_fee = paymentSnapshot[i];
  });
  paymentEditing.value = false;
}

/** 批次儲存成本：type 1=遊戲商 2=支付商 */
async function save(which: 'payment' | 'vendor') {
  const isVendor = which === 'vendor';
  const rows = isVendor ? vendors.value : payments.value;
  const ids = rows.map((r) =>
    isVendor
      ? ((r as ProfitShareVendorFee).vendor_info_id ?? '')
      : ((r as ProfitSharePaymentFee).acf_id ?? ''),
  );
  const costs = rows.map((r) =>
    isVendor
      ? ((r as ProfitShareVendorFee).vendor_fee ?? '')
      : ((r as ProfitSharePaymentFee).acf_fee ?? ''),
  );
  if (ids.length === 0) return;
  saving.value = true;
  try {
    const res = await batchCostSettingApi({
      costs,
      ids,
      type: isVendor ? 1 : 2,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      if (isVendor) vendorEditing.value = false;
      else paymentEditing.value = false;
      loadData();
    } else {
      ElMessage.error(res.message || tl('failure'));
    }
  } finally {
    saving.value = false;
  }
}

onMounted(loadData);
</script>

<template>
  <Page
    :title="`${tl('menu_agent_profit_sharing_management')} > ${tl('menu_profit_share_fee_list')}`"
  >
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
      <ElCard shadow="never">
        <template #header>
          <div class="flex items-center justify-between">
            <span class="font-medium text-blue-500">{{
              tl('label_vendor')
            }}</span>
            <div v-if="canEdit" class="flex gap-2">
              <ElButton
                v-if="!vendorEditing"
                size="small"
                type="primary"
                @click="startVendorEdit"
              >
                {{ tl('edit') }}
              </ElButton>
              <template v-else>
                <ElButton
                  :loading="saving"
                  size="small"
                  type="success"
                  @click="save('vendor')"
                >
                  {{ tl('btn_update') }}
                </ElButton>
                <ElButton size="small" @click="cancelVendorEdit">
                  {{ tl('cancel') }}
                </ElButton>
              </template>
            </div>
          </div>
        </template>
        <ElTable v-loading="loading" :data="vendors" border size="small" stripe>
          <ElTableColumn
            v-if="showId"
            :label="tl('id')"
            prop="vendor_id"
            width="80"
          />
          <ElTableColumn :label="tl('game_names')" prop="vendor_name" />
          <ElTableColumn :label="tl('vendor_fee')">
            <template #default="{ row }">
              <ElInput
                v-if="vendorEditing"
                v-model="(row as ProfitShareVendorFee).vendor_fee"
                class="!w-28"
                size="small"
              >
                <template #append>%</template>
              </ElInput>
              <span v-else
                >{{ (row as ProfitShareVendorFee).vendor_fee }}%</span
              >
            </template>
          </ElTableColumn>
          <template #empty>{{ tl('no_record') }}</template>
        </ElTable>
      </ElCard>

      <ElCard shadow="never">
        <template #header>
          <div class="flex items-center justify-between">
            <span class="font-medium" style="color: #b88700">
              {{ tl('payment') }}
            </span>
            <div v-if="canEdit" class="flex gap-2">
              <ElButton
                v-if="!paymentEditing"
                size="small"
                type="primary"
                @click="startPaymentEdit"
              >
                {{ tl('edit') }}
              </ElButton>
              <template v-else>
                <ElButton
                  :loading="saving"
                  size="small"
                  type="success"
                  @click="save('payment')"
                >
                  {{ tl('btn_update') }}
                </ElButton>
                <ElButton size="small" @click="cancelPaymentEdit">
                  {{ tl('cancel') }}
                </ElButton>
              </template>
            </div>
          </div>
        </template>
        <ElTable
          v-loading="loading"
          :data="payments"
          border
          size="small"
          stripe
        >
          <ElTableColumn
            v-if="showId"
            :label="tl('id')"
            prop="acf_id"
            width="80"
          />
          <ElTableColumn :label="tl('type')">
            <template #default="{ row }">
              {{
                Number((row as ProfitSharePaymentFee).cf_type) === 1
                  ? tl('deposit')
                  : tl('withdrawal')
              }}
            </template>
          </ElTableColumn>
          <ElTableColumn :label="tl('payment_name')" prop="cf_name" />
          <ElTableColumn :label="tl('payment_fee')">
            <template #default="{ row }">
              <ElInput
                v-if="paymentEditing"
                v-model="(row as ProfitSharePaymentFee).acf_fee"
                class="!w-28"
                size="small"
              >
                <template #append>%</template>
              </ElInput>
              <span v-else>{{ (row as ProfitSharePaymentFee).acf_fee }}%</span>
            </template>
          </ElTableColumn>
          <template #empty>{{ tl('no_record') }}</template>
        </ElTable>
      </ElCard>
    </div>
  </Page>
</template>
