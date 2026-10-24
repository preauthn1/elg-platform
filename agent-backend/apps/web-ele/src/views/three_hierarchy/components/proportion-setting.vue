<script lang="ts" setup>
import type { ThreeHierarchyProportionRow } from '#/api';

import { computed, onMounted, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElInput,
  ElMessage,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  getGameTypeListApi,
  getThreeHierarchyProportionApi,
  getVendorListApi,
  updateThreeHierarchyProportionApi,
} from '#/api';
import { tl } from '#/locales';

const props = defineProps<{
  /** 標題第三段 menu key */
  titleKey: string;
  /** 1=投注佔成、2=輸分佔成 */
  type: number;
}>();

interface EditRow {
  game_type_id: number | string;
  game_type_name: string;
  id: number | string;
  /** 百分比字串（= proportion × 100） */
  p1: string;
  p2: string;
  p3: string;
  vendor_id: number | string;
  vendor_name: string;
}

const loading = ref(false);
const saving = ref(false);
const editing = ref(false);
const rows = ref<EditRow[]>([]);
const vendors = ref<Array<{ id: number; name: string }>>([]);
const gameTypes = ref<Array<{ id: number; name: string }>>([]);

// 新增列表單
const addForm = ref({
  game_type_id: '' as number | string,
  p1: '',
  p2: '',
  p3: '',
  vendor_id: '' as number | string,
});

const title = computed(
  () =>
    `${tl('menu_reward_mode_management')} > ${tl('menu_three_layers_agents_mode_including_agent')} > ${tl(props.titleKey)}`,
);

/** 遊戲商 id → 名稱（vendor/list 的 name 已含「(id) 名稱」），供列表顯示帶 ID */
const vendorMap = computed(() => {
  const m = new Map<string, string>();
  for (const v of vendors.value) m.set(String(v.id), String(v.name).trim());
  return m;
});
function vendorLabel(row: EditRow) {
  return (
    vendorMap.value.get(String(row.vendor_id)) ??
    `(${row.vendor_id}) ${row.vendor_name}`
  );
}

/** 分數 → 百分比顯示字串 */
function toPct(v?: number | string) {
  const n = Number(v) * 100;
  if (!Number.isFinite(n)) return '';
  return String(Math.round(n * 10_000) / 10_000);
}

function mapRow(r: ThreeHierarchyProportionRow): EditRow {
  return {
    game_type_id: r.game_type_id,
    game_type_name: r.game_type_name ?? String(r.game_type_id),
    id: r.id,
    p1: toPct(r.proportion_1),
    p2: toPct(r.proportion_2),
    p3: toPct(r.proportion_3),
    vendor_id: r.vendor_id,
    vendor_name: r.vendor_name ?? String(r.vendor_id),
  };
}

async function loadData() {
  loading.value = true;
  try {
    const res = await getThreeHierarchyProportionApi(props.type);
    rows.value =
      Number(res.code) === 1000 ? (res.data ?? []).map((r) => mapRow(r)) : [];
  } finally {
    loading.value = false;
  }
}

function startEdit() {
  editing.value = true;
}

function cancelEdit() {
  editing.value = false;
  addForm.value = { game_type_id: '', p1: '', p2: '', p3: '', vendor_id: '' };
  loadData();
}

function addRow() {
  if (!addForm.value.vendor_id || !addForm.value.game_type_id) {
    ElMessage.warning(tl('required_column'));
    return;
  }
  const v = vendors.value.find((x) => x.id === addForm.value.vendor_id);
  const g = gameTypes.value.find((x) => x.id === addForm.value.game_type_id);
  rows.value.push({
    game_type_id: addForm.value.game_type_id,
    game_type_name: g?.name ?? '',
    id: -1,
    p1: addForm.value.p1,
    p2: addForm.value.p2,
    p3: addForm.value.p3,
    vendor_id: addForm.value.vendor_id,
    vendor_name: v?.name ?? '',
  });
  addForm.value = { game_type_id: '', p1: '', p2: '', p3: '', vendor_id: '' };
}

function removeRow(index: number) {
  rows.value.splice(index, 1);
}

async function save() {
  saving.value = true;
  try {
    const res = await updateThreeHierarchyProportionApi({
      game_types: rows.value.map((r) => r.game_type_id),
      ids: rows.value.map((r) => r.id),
      proportion_1: rows.value.map((r) => r.p1),
      proportion_2: rows.value.map((r) => r.p2),
      proportion_3: rows.value.map((r) => r.p3),
      type: props.type,
      vendors: rows.value.map((r) => r.vendor_id),
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

onMounted(async () => {
  const [v, g] = await Promise.all([
    getVendorListApi().catch(() => []),
    getGameTypeListApi().catch(() => []),
  ]);
  vendors.value = v ?? [];
  gameTypes.value = g ?? [];
  loadData();
});
</script>

<template>
  <Page :title="title">
    <ElCard shadow="never">
      <template #header>
        <div class="flex items-center justify-between">
          <span class="font-medium">{{ tl(titleKey) }}</span>
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
      </template>

      <!-- 編輯模式：新增列 -->
      <div v-if="editing" class="mb-3 grid grid-cols-2 gap-2 md:grid-cols-6">
        <ElSelect
          v-model="addForm.vendor_id"
          :placeholder="tl('vendor_announcement')"
          filterable
        >
          <ElOption
            v-for="v in vendors"
            :key="v.id"
            :label="v.name"
            :value="v.id"
          />
        </ElSelect>
        <ElSelect
          v-model="addForm.game_type_id"
          :placeholder="tl('game_type')"
          filterable
        >
          <ElOption
            v-for="g in gameTypes"
            :key="g.id"
            :label="g.name"
            :value="g.id"
          />
        </ElSelect>
        <ElInput
          v-model="addForm.p1"
          :placeholder="tl('proportion_setting.proportion_1')"
        />
        <ElInput
          v-model="addForm.p2"
          :placeholder="tl('proportion_setting.proportion_2')"
        />
        <ElInput
          v-model="addForm.p3"
          :placeholder="tl('proportion_setting.proportion_3')"
        />
        <ElButton type="primary" @click="addRow">+</ElButton>
      </div>

      <ElTable v-loading="loading" :data="rows" border size="small" stripe>
        <ElTableColumn :label="tl('vendor_announcement')" min-width="160">
          <template #default="{ row }">
            {{ vendorLabel(row as EditRow) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('game_type')" min-width="120">
          <template #default="{ row }">{{ row.game_type_name }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('proportion_setting.proportion_1')"
          min-width="120"
        >
          <template #default="{ row }">
            <ElInput v-if="editing" v-model="row.p1" />
            <span v-else>{{ row.p1 }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('proportion_setting.proportion_2')"
          min-width="120"
        >
          <template #default="{ row }">
            <ElInput v-if="editing" v-model="row.p2" />
            <span v-else>{{ row.p2 }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('proportion_setting.proportion_3')"
          min-width="120"
        >
          <template #default="{ row }">
            <ElInput v-if="editing" v-model="row.p3" />
            <span v-else>{{ row.p3 }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn v-if="editing" align="center" width="80" fixed="right">
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
    </ElCard>
  </Page>
</template>
