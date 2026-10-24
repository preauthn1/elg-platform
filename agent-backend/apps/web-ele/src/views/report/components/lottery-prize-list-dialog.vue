<script lang="ts" setup>
import { ref } from 'vue';

import { ElDialog, ElTable, ElTableColumn } from 'element-plus';

import { tl } from '#/locales';

const visible = ref(false);
/** 五個獎項對應的中獎號碼 */
const prizes = ref<string[]>([]);

const rows = () =>
  prizes.value.map((number, i) => ({
    name: tl(`sr17_award_name_${i + 1}`),
    number,
  }));

function open(list: string[]) {
  prizes.value = list.map((x) => x ?? '');
  visible.value = true;
}

defineExpose({ open });
</script>

<template>
  <ElDialog v-model="visible" :title="tl('winning_list')" width="500">
    <ElTable :data="rows()" border size="small" stripe>
      <ElTableColumn :label="tl('awards')" prop="name" />
      <ElTableColumn :label="tl('winning_numbers')" prop="number" />
    </ElTable>
  </ElDialog>
</template>
