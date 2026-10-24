<script lang="ts" setup>
import type { EventProgressiveJackpotMemberDetail } from '#/api';

import { ref } from 'vue';

import { ElDialog, ElTable, ElTableColumn } from 'element-plus';

import { getEventProgressiveJackpotMemberDetailApi } from '#/api';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';

defineOptions({ name: 'EventProgressiveJackpotMemberDetailDialog' });

const visible = ref(false);
const loading = ref(false);
const list = ref<EventProgressiveJackpotMemberDetail[]>([]);

async function open(id: number | string) {
  visible.value = true;
  loading.value = true;
  list.value = [];
  try {
    const res = await getEventProgressiveJackpotMemberDetailApi(id);
    list.value = Number(res.code) === 1000 && res.data ? [res.data] : [];
  } finally {
    loading.value = false;
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog v-model="visible" :title="tl('member')" width="620" align-center>
    <ElTable v-loading="loading" :data="list" border size="small" stripe>
      <ElTableColumn
        :label="tl('event_progressive_jackpot_first_deposit_amount')"
        align="right"
        min-width="130"
      >
        <template #default="{ row }">
          {{ amountThousands(row.first_deposit ?? 0, 2) }}
        </template>
      </ElTableColumn>
      <ElTableColumn
        :label="`${tl('accumulated')}${tl('amount')}`"
        align="right"
        min-width="130"
      >
        <template #default="{ row }">
          {{ amountThousands(row.total_deposit_point ?? 0, 2) }}
        </template>
      </ElTableColumn>
      <ElTableColumn
        :label="`IP${tl('event_repeat')}`"
        align="center"
        min-width="130"
      >
        <template #default="{ row }">
          <span v-if="row.duplicate_ip" class="text-red-500">
            {{ row.duplicate_ip }}
          </span>
          <span v-else class="text-muted-foreground">✕</span>
        </template>
      </ElTableColumn>
      <ElTableColumn
        :label="`${tl('mobile')}${tl('event_repeat')}`"
        align="center"
        min-width="130"
      >
        <template #default="{ row }">
          <span v-if="row.duplicate_mobile" class="text-red-500">
            {{ row.duplicate_mobile }}
          </span>
          <span v-else class="text-muted-foreground">✕</span>
        </template>
      </ElTableColumn>
      <ElTableColumn
        :label="`${tl('realname')}${tl('event_repeat')}`"
        align="center"
        min-width="130"
      >
        <template #default="{ row }">
          <span v-if="row.duplicate_realname" class="text-red-500">
            {{ row.duplicate_realname }}
          </span>
          <span v-else class="text-muted-foreground">✕</span>
        </template>
      </ElTableColumn>
      <template #empty>{{ tl('no_record') }}</template>
    </ElTable>
  </ElDialog>
</template>
