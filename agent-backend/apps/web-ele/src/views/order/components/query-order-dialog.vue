<script lang="ts" setup>
import { ref } from 'vue';

import { ElDescriptions, ElDescriptionsItem, ElDialog } from 'element-plus';

import { queryThirdPartyOrderApi } from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'QueryOrderDialog' });

const visible = ref(false);
const loading = ref(false);
const thirdPartyOrderNo = ref('');

/** 成功顯示單號，失敗直接顯示 message */
async function open(id: number | string) {
  visible.value = true;
  loading.value = true;
  thirdPartyOrderNo.value = '';
  try {
    const res = await queryThirdPartyOrderApi(id);
    thirdPartyOrderNo.value =
      Number(res.code) === 1000
        ? (res.data?.third_party_order_no ?? '')
        : res.message;
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
    width="500"
    align-center
  >
    <ElDescriptions v-loading="loading" :column="1" border>
      <ElDescriptionsItem :label="tl('third_ordernum')">
        {{ thirdPartyOrderNo || '-' }}
      </ElDescriptionsItem>
    </ElDescriptions>
  </ElDialog>
</template>
