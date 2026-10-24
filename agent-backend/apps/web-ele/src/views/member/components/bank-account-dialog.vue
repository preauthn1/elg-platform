<script lang="ts" setup>
import type { MemberBankAccountRow } from '#/api';

import { ref } from 'vue';

import { ElDialog, ElMessage, ElTable, ElTableColumn } from 'element-plus';

import { getMemberBankAccountApi } from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'MemberBankAccountDialog' });

const visible = ref(false);
const loading = ref(false);
const username = ref('');
const list = ref<MemberBankAccountRow[]>([]);

async function open(id: number, name: string) {
  username.value = name;
  list.value = [];
  visible.value = true;
  loading.value = true;
  try {
    const rows = await getMemberBankAccountApi(id);
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
