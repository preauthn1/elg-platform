<script lang="ts" setup>
import type { CsFlowLimitRow } from '#/api/cs';

import { ref, watch } from 'vue';

import { usePreferences } from '@vben/preferences';

import {
  ElDialog,
  ElEmpty,
  ElMessage,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getCsFlowLimitApi } from '#/api/cs';
import { tl } from '#/locales';

defineOptions({ name: 'CsFlowLimitDialog' });

const props = defineProps<{
  memberId: number | string;
  modelValue: boolean;
  userId: number | string;
}>();

const emit = defineEmits<{ 'update:modelValue': [boolean] }>();

const { isMobile } = usePreferences();

const rows = ref<CsFlowLimitRow[]>([]);
const loading = ref(false);

async function load() {
  if (
    props.memberId === '' ||
    props.memberId === null ||
    props.memberId === undefined
  ) {
    rows.value = [];
    return;
  }
  loading.value = true;
  try {
    rows.value = await getCsFlowLimitApi({
      memberId: props.memberId,
      userId: props.userId,
    });
  } catch {
    rows.value = [];
    ElMessage.error(tl('cs.load_failed'));
  } finally {
    loading.value = false;
  }
}

watch(
  () => props.modelValue,
  (open) => {
    if (open) load();
  },
);
</script>

<template>
  <ElDialog
    :model-value="modelValue"
    :title="tl('cs.flow_limit_record')"
    :width="isMobile ? '96%' : '900'"
    @update:model-value="(v: boolean) => emit('update:modelValue', v)"
  >
    <ElTable
      v-loading="loading"
      :data="rows"
      border
      size="small"
      max-height="520"
    >
      <ElTableColumn prop="type" :label="tl('list.type')" width="120" />
      <ElTableColumn
        prop="bonus"
        :label="tl('list.depositAmount')"
        align="right"
      />
      <ElTableColumn
        prop="flow_limit"
        :label="tl('form.audit_amount')"
        align="right"
      />
      <ElTableColumn
        prop="valid_bet"
        :label="tl('list.valid_flowAmount')"
        align="right"
      />
      <ElTableColumn
        prop="status"
        :label="tl('list.status')"
        width="90"
        align="center"
      />
      <ElTableColumn
        prop="created_at"
        :label="tl('list.date')"
        width="160"
        align="center"
      />
      <ElTableColumn
        prop="updated_at"
        :label="tl('list.operation_time')"
        width="160"
        align="center"
      />
      <template #empty>
        <ElEmpty :description="tl('cs.no_records')" />
      </template>
    </ElTable>
  </ElDialog>
</template>
