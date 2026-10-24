<script lang="ts" setup>
import type { AgentBankAccountRow } from '#/api';

import { ref } from 'vue';

import { ElDialog, ElMessage, ElTable, ElTableColumn } from 'element-plus';

import { getAgentBankAccountApi } from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'AgentBankAccountDialog' });

const visible = ref(false);
const loading = ref(false);
const username = ref('');
const list = ref<AgentBankAccountRow[]>([]);

async function open(id: number, name: string) {
  username.value = name;
  list.value = [];
  visible.value = true;
  loading.value = true;
  try {
    const rows = await getAgentBankAccountApi(id);
    list.value = Array.isArray(rows) ? rows : [];
  } catch {
    ElMessage.error(tl('failure'));
  } finally {
    loading.value = false;
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog v-model="visible" :title="username" width="600" align-center>
    <ElTable v-loading="loading" :data="list" border size="small" stripe>
      <ElTableColumn :label="tl('bank_name')" prop="bank_name" />
      <ElTableColumn :label="tl('account_no')" prop="account_no" />
      <ElTableColumn :label="tl('currency_name')" prop="currency_code" />
      <template #empty>{{ tl('no_record') }}</template>
    </ElTable>
  </ElDialog>
</template>
