<script lang="ts" setup>
import { ref } from 'vue';

import {
  ElButton,
  ElDescriptions,
  ElDescriptionsItem,
  ElDialog,
  ElInput,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { findVendorInfoByAgentApi } from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'AgentVendorInfoDialog' });

const visible = ref(false);
const loading = ref(false);
const searched = ref(false);
const username = ref('');
const errorMsg = ref('');
const info = ref<any>(null);

function vendorInfoTypeName(type?: number | string) {
  switch (String(type)) {
    case '0': {
      return tl('testing');
    }
    case '1': {
      return tl('production');
    }
    case '2': {
      return tl('event');
    }
    default: {
      return 'unknown';
    }
  }
}

async function search() {
  if (!username.value) return;
  loading.value = true;
  searched.value = true;
  errorMsg.value = '';
  info.value = null;
  try {
    const res = await findVendorInfoByAgentApi(username.value);
    if (Number(res.code) === 1000) {
      info.value = res.data ?? null;
    } else {
      errorMsg.value = res.message;
    }
  } finally {
    loading.value = false;
  }
}

function open() {
  visible.value = true;
  username.value = '';
  searched.value = false;
  errorMsg.value = '';
  info.value = null;
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="tl('query_agent_vendor_info')"
    align-center
    width="860"
  >
    <div class="mb-4 flex gap-2">
      <ElInput
        v-model="username"
        :placeholder="tl('enter_agent_account')"
        class="!w-64"
        @keyup.enter="search"
      />
      <ElButton :loading="loading" type="primary" @click="search">
        {{ tl('search') }}
      </ElButton>
    </div>

    <div v-if="errorMsg" class="text-red-500">{{ errorMsg }}</div>

    <div v-if="info" v-loading="loading">
      <ElDescriptions class="mb-4" :column="2" border>
        <ElDescriptionsItem :label="tl('agent_id')">
          {{ info.agent_id }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('agent_account')">
          {{ info.agent_username }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('hierarchy_path')">
          {{ info.hierarchy_path }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('agent_setting')">
          <span
            v-for="(v, i) in info.vendor_info_source_agents ?? []"
            :key="i"
            class="mr-1"
          >
            {{ v }}
          </span>
        </ElDescriptionsItem>
      </ElDescriptions>

      <h4 class="mb-2 font-bold">
        {{ tl('corresponding_to_game_provider') }}
      </h4>
      <ElTable :data="info.vendor_info_list ?? []" border size="small" stripe>
        <ElTableColumn
          :label="tl('game_id')"
          align="center"
          prop="vendor_id"
          width="90"
        />
        <ElTableColumn
          :label="tl('game_names')"
          align="center"
          prop="vendor_name"
          min-width="120"
        />
        <ElTableColumn
          :label="tl('vendor_info_id')"
          align="center"
          prop="vendor_info_id"
          width="110"
        />
        <ElTableColumn
          :label="tl('vendor_info_name')"
          align="center"
          prop="vendor_info_name"
          min-width="160"
        />
        <ElTableColumn :label="tl('type')" align="center" width="100">
          <template #default="{ row }">
            {{ vendorInfoTypeName(row.vendor_info_type) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('cost')"
          align="right"
          prop="cost"
          width="100"
        />
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
    </div>
  </ElDialog>
</template>
