<script lang="ts" setup>
import type { VipRebateRow } from '#/api';

import { computed, ref } from 'vue';

import {
  ElButton,
  ElDialog,
  ElInput,
  ElMessage,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getGameTypeListApi, getVipRebateApi, updateVipRebateApi } from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'VipEditRebateDialog' });

interface EditRow {
  game_type_id: number | string;
  game_type_name: string;
  id: number | string;
  /** 百分比字串（= proportion × 100） */
  proportion: string;
}

const visible = ref(false);
const loading = ref(false);
const saving = ref(false);
const editing = ref(false);
const vipId = ref<number | string>('');
const vipName = ref('');
const rows = ref<EditRow[]>([]);
const gameTypes = ref<Array<{ id: number; name: string }>>([]);

const addForm = ref({ game_type_id: '' as number | string, proportion: '' });

/** 尚未使用的遊戲類型 */
const availableGameTypes = computed(() => {
  const used = new Set(rows.value.map((r) => Number(r.game_type_id)));
  return gameTypes.value.filter((g) => !used.has(Number(g.id)));
});

function gameTypeName(id: number | string) {
  return (
    gameTypes.value.find((g) => Number(g.id) === Number(id))?.name ?? String(id)
  );
}

function mapRow(r: VipRebateRow): EditRow {
  return {
    game_type_id: r.game_type_id,
    game_type_name: gameTypeName(r.game_type_id),
    id: r.id,
    proportion: String(Number(r.proportion) * 100),
  };
}

async function loadData() {
  loading.value = true;
  try {
    const res = await getVipRebateApi(vipId.value);
    rows.value =
      Number(res.code) === 1000 ? (res.data ?? []).map((r) => mapRow(r)) : [];
  } finally {
    loading.value = false;
  }
}

async function open(id: number | string, name: string) {
  vipId.value = id;
  vipName.value = name;
  editing.value = false;
  rows.value = [];
  addForm.value = { game_type_id: '', proportion: '' };
  visible.value = true;
  if (gameTypes.value.length === 0) {
    gameTypes.value = (await getGameTypeListApi().catch(() => [])) ?? [];
  }
  loadData();
}

function startEdit() {
  editing.value = true;
}

function cancelEdit() {
  editing.value = false;
  addForm.value = { game_type_id: '', proportion: '' };
  loadData();
}

function addRow() {
  if (!addForm.value.game_type_id) {
    ElMessage.warning(tl('game_type'));
    return;
  }
  rows.value.push({
    game_type_id: addForm.value.game_type_id,
    game_type_name: gameTypeName(addForm.value.game_type_id),
    id: -1,
    proportion: addForm.value.proportion,
  });
  addForm.value = { game_type_id: '', proportion: '' };
}

function removeRow(index: number) {
  rows.value.splice(index, 1);
}

async function save() {
  saving.value = true;
  try {
    const res = await updateVipRebateApi({
      game_type_id: rows.value.map((r) => r.game_type_id),
      ids: rows.value.map((r) => r.id),
      proportion: rows.value.map((r) => r.proportion),
      vip_content_id: vipId.value,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      editing.value = false;
      loadData();
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    saving.value = false;
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="`${tl('edit')}${tl('rebate')}`"
    width="720"
    align-center
  >
    <div class="mb-3 flex items-center justify-between">
      <div>
        <b>{{ tl('name') }}</b
        >：{{ vipName }} ({{ vipId }})
      </div>
      <div class="flex gap-2">
        <ElButton v-if="!editing" type="primary" @click="startEdit">
          {{ tl('edit') }}
        </ElButton>
        <template v-else>
          <ElButton @click="cancelEdit">{{ tl('cancel') }}</ElButton>
          <ElButton :loading="saving" type="success" @click="save">
            {{ tl('btn_update') }}
          </ElButton>
        </template>
      </div>
    </div>

    <div v-if="editing" class="mb-3 flex gap-2">
      <ElSelect
        v-model="addForm.game_type_id"
        :placeholder="tl('game_type')"
        class="flex-1"
        filterable
      >
        <ElOption
          v-for="g in availableGameTypes"
          :key="g.id"
          :label="g.name"
          :value="g.id"
        />
      </ElSelect>
      <ElInput
        v-model="addForm.proportion"
        :placeholder="tl('proportion_setting.proportion_1')"
        class="flex-1"
      />
      <ElButton type="info" @click="addRow">+</ElButton>
    </div>

    <ElTable v-loading="loading" :data="rows" border size="small" stripe>
      <ElTableColumn :label="tl('game_type')" align="center" min-width="160">
        <template #default="{ row }">{{ row.game_type_name }}</template>
      </ElTableColumn>
      <ElTableColumn
        :label="tl('proportion_setting.proportion_1')"
        align="center"
        min-width="160"
      >
        <template #default="{ row }">
          <ElInput v-if="editing" v-model="row.proportion" />
          <span v-else>{{ row.proportion }}</span>
        </template>
      </ElTableColumn>
      <ElTableColumn v-if="editing" align="center" width="80">
        <template #default="{ $index }">
          <ElButton
            circle
            size="small"
            type="danger"
            @click="removeRow($index)"
          >
            -
          </ElButton>
        </template>
      </ElTableColumn>
      <template #empty>{{ tl('no_record') }}</template>
    </ElTable>
  </ElDialog>
</template>
