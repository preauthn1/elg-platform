<script lang="ts" setup>
import type { EventInviteEventLogRow } from '#/api';

import { ref } from 'vue';

import { ElDialog, ElTable, ElTableColumn } from 'element-plus';

import { getEventInviteEventLogApi } from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';

defineOptions({ name: 'EventInviteEventLogDialog' });

const PER_PAGE = 20;

const visible = ref(false);
const loading = ref(false);
const memberId = ref<number | string>(0);
const list = ref<EventInviteEventLogRow[]>([]);
const page = ref(1);
const total = ref(0);

/** 1=真實姓名 2=儲值 3=投注流水 4=領獎 */
function typeText(type: number | string | undefined) {
  switch (Number(type)) {
    case 1: {
      return tl('realname');
    }
    case 2: {
      return tl('paytype_deposit');
    }
    case 3: {
      return `${tl('valid_bet')}${tl('flow')}`;
    }
    case 4: {
      return `${tl('received')}${tl('reward')}`;
    }
    default: {
      return String(type ?? '');
    }
  }
}

async function load() {
  loading.value = true;
  try {
    const res = await getEventInviteEventLogApi({
      member_id: memberId.value,
      page: page.value,
      per_page: PER_PAGE,
    });
    list.value = Number(res.code) === 1000 ? (res.data ?? []) : [];
    total.value = res.pagination?.total ?? 0;
  } finally {
    loading.value = false;
  }
}

function open(id: number | string) {
  memberId.value = id;
  page.value = 1;
  visible.value = true;
  load();
}

function handlePage(p: number) {
  page.value = p;
  load();
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="`${tl('menu_event_invite')}${tl('log')}`"
    width="720"
    align-center
  >
    <ElTable v-loading="loading" :data="list" border size="small" stripe>
      <ElTableColumn :label="tl('time')" prop="created_at" width="180" />
      <ElTableColumn :label="tl('type')" min-width="140">
        <template #default="{ row }">{{ typeText(row.type) }}</template>
      </ElTableColumn>
      <ElTableColumn :label="tl('point')" align="right" min-width="120">
        <template #default="{ row }">
          <span
            :class="Number(row.point) >= 0 ? 'text-green-600' : 'text-red-500'"
          >
            {{ Number(row.point) >= 0 ? '+' : ''
            }}{{ amountThousands(row.point) }}
          </span>
        </template>
      </ElTableColumn>
      <template #empty>{{ tl('no_record') }}</template>
    </ElTable>
    <TablePagination
      :current-page="page"
      :page-size="PER_PAGE"
      :total="total"
      layout="total, prev, pager, next"
      @current-change="handlePage"
    />
  </ElDialog>
</template>
