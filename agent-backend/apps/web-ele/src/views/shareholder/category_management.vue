<script lang="ts" setup>
import type {
  ShareholderCategoryRow,
  ShareholderCategorySettingRow,
} from '#/api';

import { computed, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElDialog,
  ElInput,
  ElMessage,
  ElOption,
  ElSelect,
  ElTabPane,
  ElTabs,
} from 'element-plus';

import {
  getShareholderCategoryListApi,
  getShareholderCategorySettingApi,
  getShareholderGameTypeListApi,
  getShareholderVendorListApi,
  updateShareholderCategoryApi,
  updateShareholderCategorySettingApi,
} from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'ShareholderCategoryManagement' });

interface SettingRow {
  game_type_id: number | string;
  game_type_name: string;
  id: number | string;
  vendor_id: number | string;
  vendor_name: string;
}

interface VendorOption {
  id: number | string;
  name: string;
}
interface GameTypeOption {
  id: number | string;
  name: string;
}

const loading = ref(false);
const categories = ref<ShareholderCategoryRow[]>([]);
const vendors = ref<VendorOption[]>([]);
const gameTypes = ref<GameTypeOption[]>([]);
const settingMap = reactive<Record<string, SettingRow[]>>({});
const editMode = reactive<Record<string, boolean>>({});
const snapshots = reactive<Record<string, SettingRow[]>>({});
const addVendor = reactive<Record<string, number | string>>({});
const addGameType = reactive<Record<string, number | string>>({});
const savingCat = ref<number | string | undefined>();

const activeTab = ref('');

const title = computed(
  () =>
    `${tl('menu_shareholder')} > ${tl('menu_shareholder_category_management')}`,
);

const gameTypeOptions = computed<GameTypeOption[]>(() => [
  { id: 0, name: tl('all') },
  ...gameTypes.value,
]);

function subName(c: ShareholderCategoryRow) {
  return `${tl('betting_turnover_bonus')}(${c.bet_ratio}) / ${tl('performance_bonus')}(${c.commission_ratio})`;
}

async function loadData() {
  loading.value = true;
  try {
    const [catRes, setRes] = await Promise.all([
      getShareholderCategoryListApi(),
      getShareholderCategorySettingApi(),
    ]);
    let cats = Number(catRes.code) === 1000 ? (catRes.data ?? []) : [];
    if (cats.length === 0) {
      cats = [
        { id: 0, name: 'Example', code: '', bet_ratio: 0, commission_ratio: 0 },
      ];
    }
    categories.value = cats;
    activeTab.value = String(cats[0]?.id ?? '');

    const setting: ShareholderCategorySettingRow[] =
      Number(setRes.code) === 1000 ? (setRes.data?.setting ?? []) : [];
    for (const c of cats) settingMap[String(c.id)] = [];
    for (const r of setting) {
      const key = String(r.category_id);
      (settingMap[key] ??= []).push({
        id: r.id,
        vendor_id: r.vendor_id,
        vendor_name: r.vendor_name ?? '',
        game_type_id: r.game_type_id,
        game_type_name: r.game_type_name ?? '',
      });
    }
  } finally {
    loading.value = false;
  }
}

async function loadDropdowns() {
  const [vRes, gRes] = await Promise.all([
    getShareholderVendorListApi(),
    getShareholderGameTypeListApi(),
  ]);
  vendors.value = Number(vRes.code) === 1000 ? (vRes.data ?? []) : [];
  gameTypes.value = Number(gRes.code) === 1000 ? (gRes.data ?? []) : [];
}

function enterEdit(catId: number | string) {
  const key = String(catId);
  // 淺拷貝每列即可（欄位皆為純值）；不可用 structuredClone，reactive proxy 會丟 DataCloneError
  snapshots[key] = (settingMap[key] ?? []).map((r) => ({ ...r }));
  editMode[key] = true;
}

function cancelEdit(catId: number | string) {
  const key = String(catId);
  settingMap[key] = snapshots[key] ?? [];
  editMode[key] = false;
}

function addRow(catId: number | string) {
  const key = String(catId);
  const vId = addVendor[key];
  if (vId === undefined || vId === '') return;
  const v = vendors.value.find((x) => String(x.id) === String(vId));
  const gId = addGameType[key] ?? 0;
  const g = gameTypeOptions.value.find((x) => String(x.id) === String(gId));
  (settingMap[key] ??= []).push({
    id: 0,
    vendor_id: vId,
    vendor_name: v?.name ?? '',
    game_type_id: gId,
    game_type_name: g?.name ?? '',
  });
}

function removeRow(catId: number | string, idx: number) {
  settingMap[String(catId)]?.splice(idx, 1);
}

async function saveSetting(catId: number | string) {
  const key = String(catId);
  const rows = settingMap[key] ?? [];
  savingCat.value = catId;
  try {
    const res = await updateShareholderCategorySettingApi({
      category_id: catId,
      category_setting_ids: rows.map((r) => r.id),
      category_content_vendors: rows.map((r) => r.vendor_id),
      category_content_game_types: rows.map((r) => r.game_type_id),
      type: catId,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      editMode[key] = false;
      await loadData();
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    savingCat.value = undefined;
  }
}

/* ── 類別清單編輯 modal ── */
interface CategoryEditRow {
  bet_ratio: number | string;
  code: string;
  commission_ratio: number | string;
  id: number | string;
  name: string;
}
const categoryDialog = ref(false);
const categoryRows = ref<CategoryEditRow[]>([]);
const savingCategory = ref(false);

function openCategoryDialog() {
  categoryRows.value = categories.value
    .filter((c) => Number(c.id) > 0)
    .map((c) => ({
      id: c.id,
      code: c.code ?? '',
      name: c.name ?? '',
      bet_ratio: c.bet_ratio ?? '',
      commission_ratio: c.commission_ratio ?? '',
    }));
  categoryDialog.value = true;
}

function addCategoryRow() {
  categoryRows.value.push({
    id: 0,
    code: '',
    name: '',
    bet_ratio: '',
    commission_ratio: '',
  });
}

function removeCategoryRow(idx: number) {
  categoryRows.value.splice(idx, 1);
}

async function saveCategory() {
  savingCategory.value = true;
  try {
    const res = await updateShareholderCategoryApi({
      ids: categoryRows.value.map((r) => r.id),
      codes: categoryRows.value.map((r) => r.code),
      names: categoryRows.value.map((r) => r.name),
      bet_ratio: categoryRows.value.map((r) => r.bet_ratio),
      commission_ratio: categoryRows.value.map((r) => r.commission_ratio),
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      categoryDialog.value = false;
      await loadData();
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    savingCategory.value = false;
  }
}

loadData();
loadDropdowns();
</script>

<template>
  <Page :title="title">
    <ElCard v-loading="loading" shadow="never">
      <div class="mb-3 flex justify-end">
        <ElButton type="success" @click="openCategoryDialog">
          {{ tl('type_name') }}
        </ElButton>
      </div>
      <ElTabs v-model="activeTab" tab-position="left">
        <ElTabPane
          v-for="c in categories"
          :key="c.id"
          :label="c.name"
          :name="String(c.id)"
        >
          <div class="rounded border p-5">
            <div class="flex items-start justify-between">
              <div>
                <div class="text-2xl font-bold">{{ c.name }}</div>
                <div class="mt-1 text-base font-bold">{{ subName(c) }}</div>
              </div>
              <div v-if="Number(c.id) > 0" class="flex gap-2">
                <template v-if="editMode[String(c.id)]">
                  <ElButton
                    :loading="savingCat === c.id"
                    type="primary"
                    @click="saveSetting(c.id)"
                  >
                    {{ tl('btn_update') }}
                  </ElButton>
                  <ElButton @click="cancelEdit(c.id)">
                    {{ tl('cancel') }}
                  </ElButton>
                </template>
                <ElButton v-else type="success" @click="enterEdit(c.id)">
                  {{ tl('edit') }}
                </ElButton>
              </div>
            </div>

            <div class="mt-5">
              <div class="grid grid-cols-12 gap-2 font-medium">
                <div class="col-span-5">{{ tl('vendor_announcement') }}</div>
                <div class="col-span-5">{{ tl('game_type') }}</div>
                <div class="col-span-2"></div>
              </div>

              <div
                v-if="editMode[String(c.id)]"
                class="mt-2 grid grid-cols-12 items-center gap-2"
              >
                <div class="col-span-5">
                  <ElSelect
                    v-model="addVendor[String(c.id)]"
                    class="w-full"
                    filterable
                    :placeholder="tl('vendor_announcement')"
                  >
                    <ElOption
                      v-for="v in vendors"
                      :key="v.id"
                      :label="v.name"
                      :value="v.id"
                    />
                  </ElSelect>
                </div>
                <div class="col-span-5">
                  <ElSelect
                    v-model="addGameType[String(c.id)]"
                    class="w-full"
                    filterable
                  >
                    <ElOption
                      v-for="g in gameTypeOptions"
                      :key="g.id"
                      :label="g.name"
                      :value="g.id"
                    />
                  </ElSelect>
                </div>
                <div class="col-span-2">
                  <ElButton type="info" @click="addRow(c.id)">
                    {{ tl('add') }}
                  </ElButton>
                </div>
              </div>

              <div
                v-for="(row, idx) in settingMap[String(c.id)] ?? []"
                :key="idx"
                class="mt-2 grid grid-cols-12 items-center gap-2"
              >
                <div class="col-span-5">
                  <ElInput :model-value="row.vendor_name" disabled />
                </div>
                <div class="col-span-5">
                  <ElInput :model-value="row.game_type_name" disabled />
                </div>
                <div class="col-span-2">
                  <ElButton
                    v-if="editMode[String(c.id)]"
                    type="danger"
                    @click="removeRow(c.id, idx)"
                  >
                    {{ tl('delete') }}
                  </ElButton>
                </div>
              </div>
            </div>
          </div>
        </ElTabPane>
      </ElTabs>
    </ElCard>

    <ElDialog v-model="categoryDialog" :title="tl('type_name')" width="760px">
      <div class="grid grid-cols-12 gap-2 font-medium">
        <div class="col-span-3">{{ tl('name') }}</div>
        <div class="col-span-3">{{ tl('betting_turnover_bonus') }}</div>
        <div class="col-span-3">{{ tl('performance_bonus') }}</div>
        <div class="col-span-3">
          <ElButton type="primary" @click="addCategoryRow">
            {{ tl('add') }}
          </ElButton>
        </div>
      </div>
      <div
        v-for="(row, idx) in categoryRows"
        :key="idx"
        class="mt-2 grid grid-cols-12 items-center gap-2"
      >
        <div class="col-span-3"><ElInput v-model="row.name" /></div>
        <div class="col-span-3"><ElInput v-model="row.bet_ratio" /></div>
        <div class="col-span-3"><ElInput v-model="row.commission_ratio" /></div>
        <div class="col-span-3">
          <ElButton type="danger" @click="removeCategoryRow(idx)">
            {{ tl('delete') }}
          </ElButton>
        </div>
      </div>
      <template #footer>
        <ElButton @click="categoryDialog = false">{{ tl('cancel') }}</ElButton>
        <ElButton
          :loading="savingCategory"
          type="primary"
          @click="saveCategory"
        >
          {{ tl('btn_update') }}
        </ElButton>
      </template>
    </ElDialog>
  </Page>
</template>
