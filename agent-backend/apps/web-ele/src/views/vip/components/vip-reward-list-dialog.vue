<script lang="ts" setup>
import type { VipRewardRow } from '#/api';

import { ref } from 'vue';

import {
  ElButton,
  ElDialog,
  ElDropdown,
  ElDropdownItem,
  ElDropdownMenu,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getVipRewardListApi } from '#/api';
import { tl } from '#/locales';

import VipEditRewardDialog from './vip-edit-reward-dialog.vue';

defineOptions({ name: 'VipRewardListDialog' });

const visible = ref(false);
const loading = ref(false);
const vipId = ref<number | string>('');
const vipName = ref('');
const list = ref<VipRewardRow[]>([]);

const editRewardRef = ref<InstanceType<typeof VipEditRewardDialog>>();

function isSet(row: VipRewardRow) {
  return Number(row.vip_content_reward_id) !== 0;
}

async function loadData() {
  loading.value = true;
  try {
    const res = await getVipRewardListApi(vipId.value);
    list.value = Number(res.code) === 1000 ? (res.data ?? []) : [];
  } finally {
    loading.value = false;
  }
}

async function open(id: number | string, name: string) {
  vipId.value = id;
  vipName.value = name;
  list.value = [];
  visible.value = true;
  loadData();
}

/** 未設定的列：第 2 欄起合併成一格 */
function spanMethod({
  row,
  columnIndex,
}: {
  columnIndex: number;
  row: VipRewardRow;
}) {
  if (isSet(row)) return;
  if (columnIndex === 1) return { colspan: 7, rowspan: 1 };
  if (columnIndex >= 2 && columnIndex <= 7) return { colspan: 0, rowspan: 0 };
}

function rewardTypeText(pointType?: number) {
  if (Number(pointType) === 1) return tl('platform_point');
  if (Number(pointType) === 2) return tl('rolling_chip');
  return '';
}

function openEdit(row: VipRewardRow) {
  editRewardRef.value?.open({
    id: row.vip_content_reward_id,
    name: row.vip_reward_name ?? '',
    vip_content_id: vipId.value,
    vip_content_name: vipName.value,
    vip_reward_id: row.vip_reward_id,
  });
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="`${tl('menu_vip_setting')} > ${tl('reward')}`"
    width="1140"
    align-center
  >
    <div class="mb-3">
      <b>{{ tl('name') }}</b
      >：{{ vipName }} ({{ vipId }})
    </div>
    <ElTable
      v-loading="loading"
      :data="list"
      :span-method="spanMethod"
      border
      size="small"
      stripe
    >
      <ElTableColumn
        :label="tl('bind_reward_name')"
        align="center"
        min-width="140"
      >
        <template #default="{ row }">
          <b>{{ row.vip_reward_name }}</b>
        </template>
      </ElTableColumn>
      <ElTableColumn
        :label="tl('event_reward_points')"
        align="center"
        min-width="120"
      >
        <template #default="{ row }">
          <span v-if="!isSet(row as VipRewardRow)" class="text-gray-400">
            <b>{{ tl('not_set') }}</b>
          </span>
          <span v-else>{{
            Number(row.vip_reward_id) === 8 ? row.condition : '___'
          }}</span>
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('reward_type')" align="center" min-width="110">
        <template #default="{ row }">
          {{ rewardTypeText(row.point_type) }}
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('reward_point')" align="center" min-width="100">
        <template #default="{ row }">
          <span v-if="Number(row.type) === 0">{{ row.point }}</span>
          <span v-else class="text-green-600">
            {{ Number(row.point_percentage) * 100 }}%
          </span>
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('max_amount')" align="center" min-width="100">
        <template #default="{ row }">
          {{ Number(row.max_point) === 0 ? tl('unlimited') : row.max_point }}
        </template>
      </ElTableColumn>
      <ElTableColumn
        :label="tl('event_jackpot_column_3')"
        align="center"
        min-width="100"
      >
        <template #default="{ row }">
          {{
            Number(row.expired_hour) === 0
              ? tl('unlimited')
              : `${row.expired_hour}${tl('hour')}`
          }}
        </template>
      </ElTableColumn>
      <ElTableColumn
        :label="tl('event_flow_limit_multiple')"
        align="center"
        min-width="100"
        prop="flow_limit_multiple"
      />
      <ElTableColumn :label="tl('status')" align="center" min-width="100">
        <template #default="{ row }">
          <div :class="Number(row.status) === 1 ? '' : 'text-red-500'">
            {{ Number(row.status) === 1 ? tl('enable') : tl('disable') }}
          </div>
          <div v-if="Number(row.is_reached) === 1" class="text-red-500">
            {{ tl('reached_target') }}
          </div>
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('operate')" align="center" width="90">
        <template #default="{ row }">
          <ElDropdown>
            <ElButton size="small">{{ tl('operate') }}</ElButton>
            <template #dropdown>
              <ElDropdownMenu>
                <ElDropdownItem @click="openEdit(row as VipRewardRow)">
                  {{ tl('edit') }}
                </ElDropdownItem>
              </ElDropdownMenu>
            </template>
          </ElDropdown>
        </template>
      </ElTableColumn>
      <template #empty>{{ tl('no_record') }}</template>
    </ElTable>

    <VipEditRewardDialog ref="editRewardRef" @saved="loadData" />
  </ElDialog>
</template>
