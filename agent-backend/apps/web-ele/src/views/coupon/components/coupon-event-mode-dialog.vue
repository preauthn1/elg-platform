<script lang="ts" setup>
import { computed, ref } from 'vue';

import {
  ElButton,
  ElDialog,
  ElFormItem,
  ElMessage,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  getBindRewardGameTypesApi,
  getCouponEventModeApi,
  getVendorListApi,
  saveCouponEventModeApi,
} from '#/api';
import { tl } from '#/locales';

const emit = defineEmits<{ success: [] }>();

type RestrictionRow = {
  game_type_id: number | string;
  vendor_id: number | string;
};

const visible = ref(false);
const saving = ref(false);
const couponId = ref<number | string>('');
const batchCode = ref('');

const vendors = ref<Array<{ id: number; name: string }>>([]);
const gameTypes = ref<Array<{ id: number; name: string }>>([]);
const restrictions = ref<RestrictionRow[]>([]);

const rowInput = ref({
  game_type_id: 0 as number | string,
  vendor_id: 0 as number | string,
});

/** 遊戲商與遊戲類別不可同時為無限制，否則是無意義的空限制 */
const canAddRestriction = computed(
  () =>
    Number(rowInput.value.vendor_id) !== 0 ||
    Number(rowInput.value.game_type_id) !== 0,
);

function vendorName(id: number | string) {
  if (Number(id) === 0) return tl('unlimited');
  return vendors.value.find((v) => Number(v.id) === Number(id))?.name ?? id;
}
function gameTypeName(id: number | string) {
  if (Number(id) === 0) return tl('unlimited');
  return gameTypes.value.find((g) => Number(g.id) === Number(id))?.name ?? id;
}

function addRestriction() {
  restrictions.value.push({
    game_type_id: rowInput.value.game_type_id,
    vendor_id: rowInput.value.vendor_id,
  });
  rowInput.value.vendor_id = 0;
  rowInput.value.game_type_id = 0;
}

function removeRestriction(idx: number) {
  restrictions.value.splice(idx, 1);
}

async function handleSave() {
  saving.value = true;
  try {
    const res = await saveCouponEventModeApi({
      game_type_id_restriction: restrictions.value.map((r) => r.game_type_id),
      id: couponId.value,
      vendor_id_restriction: restrictions.value.map((r) => r.vendor_id),
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      visible.value = false;
      emit('success');
    } else {
      ElMessage.error(res.message || tl('failure'));
    }
  } finally {
    saving.value = false;
  }
}

async function open(id: number | string, code?: string) {
  couponId.value = id;
  batchCode.value = code ?? '';
  restrictions.value = [];
  rowInput.value = { game_type_id: 0, vendor_id: 0 };
  visible.value = true;

  if (vendors.value.length === 0) {
    const list = await getVendorListApi();
    vendors.value = Array.isArray(list) ? list : [];
  }
  if (gameTypes.value.length === 0) {
    const list = await getBindRewardGameTypesApi();
    gameTypes.value = Array.isArray(list) ? list : [];
  }

  const res = await getCouponEventModeApi(id);
  if (Number(res.code) === 1000) {
    restrictions.value = (res.data ?? []).map((r) => ({
      game_type_id: r.game_type_id,
      vendor_id: r.vendor_id,
    }));
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog v-model="visible" :title="tl('event_mode')" width="700">
    <div class="mb-3 text-sm">
      <span class="font-medium">{{ tl('batch_code') }}:</span>
      {{ batchCode }} (#{{ couponId }})
    </div>

    <div class="flex items-end gap-3">
      <ElFormItem :label="tl('vendor_announcement')" class="!mb-0 flex-1">
        <ElSelect v-model="rowInput.vendor_id" class="w-full">
          <ElOption :label="tl('unlimited')" :value="0" />
          <ElOption
            v-for="v in vendors"
            :key="v.id"
            :label="v.name"
            :value="v.id"
          />
        </ElSelect>
      </ElFormItem>
      <ElFormItem :label="tl('game_type')" class="!mb-0 flex-1">
        <ElSelect v-model="rowInput.game_type_id" class="w-full">
          <ElOption :label="tl('unlimited')" :value="0" />
          <ElOption
            v-for="g in gameTypes"
            :key="g.id"
            :label="g.name"
            :value="g.id"
          />
        </ElSelect>
      </ElFormItem>
      <ElButton
        :disabled="!canAddRestriction"
        type="primary"
        @click="addRestriction"
      >
        +
      </ElButton>
    </div>

    <ElTable :data="restrictions" border class="mt-3" size="small">
      <ElTableColumn :label="tl('vendor_announcement')">
        <template #default="{ row }">
          {{ vendorName(row.vendor_id) }}
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('game_type')">
        <template #default="{ row }">
          {{ gameTypeName(row.game_type_id) }}
        </template>
      </ElTableColumn>
      <ElTableColumn width="70">
        <template #default="{ $index }">
          <ElButton
            size="small"
            type="danger"
            @click="removeRestriction($index)"
          >
            -
          </ElButton>
        </template>
      </ElTableColumn>
      <template #empty>{{ tl('unlimited') }}</template>
    </ElTable>

    <template #footer>
      <ElButton @click="visible = false">{{ tl('cancel') }}</ElButton>
      <ElButton :loading="saving" type="success" @click="handleSave">
        {{ tl('btn_update') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
