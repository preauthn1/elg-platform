<script lang="ts" setup>
import { ref } from 'vue';

import { ElDialog, ElTable, ElTableColumn } from 'element-plus';

import { getAgentDomainsApi } from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'MemberDomainDialog' });

const visible = ref(false);
const loading = ref(false);
const list = ref<Array<{ domain: string; id: number }>>([]);

async function open(agentId: number) {
  visible.value = true;
  loading.value = true;
  try {
    list.value = (await getAgentDomainsApi(agentId)) ?? [];
  } finally {
    loading.value = false;
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog v-model="visible" :title="tl('domain')" width="480" align-center>
    <ElTable v-loading="loading" :data="list" border size="small" stripe>
      <ElTableColumn :label="tl('id')" prop="id" width="100" align="center" />
      <ElTableColumn :label="tl('domain')" prop="domain" min-width="240" />
      <template #empty>{{ tl('no_record') }}</template>
    </ElTable>
  </ElDialog>
</template>
