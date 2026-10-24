<script lang="ts" setup>
import type {
  VendorCustomizedGameType,
  VendorCustomizedItemRow,
  VendorCustomizedVendorOption,
} from '#/api';

import {
  computed,
  nextTick,
  onBeforeUnmount,
  onMounted,
  ref,
  watch,
} from 'vue';
import { useRoute } from 'vue-router';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElMessageBox,
  ElOption,
  ElSelect,
  ElSwitch,
} from 'element-plus';
import Sortable from 'sortablejs';

import {
  getCustomizedSettingApi,
  getVendorCustomizedGamesApi,
  getVendorCustomizedInternalTypesApi,
  getVendorCustomizedItemApi,
  getVendorCustomizedItemListApi,
  removeVendorCustomizedItemApi,
  saveVendorCustomizedItemApi,
  updateVendorCustomizedSortsApi,
} from '#/api';
import { tl } from '#/locales';
import {
  IMAGE_ACCEPT,
  ImageUploadError,
  imageUploadText,
  readImageAsDataUrl,
} from '#/utils/image-upload';

defineOptions({ name: 'AgentVendorCustomizedItemsSetting' });

const props = defineProps<{ embedGroupId?: string }>();

/** GAME_PROVIDER_FRONT_PAGE_VISIBLE 設定鍵 = 4075 */
const FRONT_PAGE_VISIBLE_KEY = 4075;

const route = useRoute();
const groupId = props.embedGroupId || (route.query.group_id as string) || '';

const loading = ref(false);
const group = ref<{ id?: number | string; name?: string }>({});
const items = ref<VendorCustomizedItemRow[]>([]);
const vendors = ref<VendorCustomizedVendorOption[]>([]);
const gameTypes = ref<VendorCustomizedGameType[]>([]);
const internalTypes = ref<Array<{ parameter: string }>>([]);
const frontPageVisible = ref(false);

const itemsPerRow = ref(3);
const sortMode = ref(false);
const saving = ref(false);

const boardRef = ref<HTMLElement | null>(null);
let sortable: null | Sortable = null;

/** 看板每列張數 → grid 欄數；寬螢幕以卡寬 150px 為上限置中，手機則自動縮小照排 */
const boardStyle = computed(() => ({
  display: 'grid',
  gridTemplateColumns: `repeat(${itemsPerRow.value}, minmax(0, 1fr))`,
  maxWidth: `${150 * itemsPerRow.value}px`,
}));

async function loadData() {
  if (!groupId) return;
  loading.value = true;
  try {
    const [listRes, internalRes] = await Promise.all([
      getVendorCustomizedItemListApi(groupId),
      getVendorCustomizedInternalTypesApi(),
    ]);
    if (Number(listRes.code) === 1000 && listRes.data) {
      group.value = listRes.data.group ?? {};
      items.value = listRes.data.items ?? [];
      vendors.value = listRes.data.vendors ?? [];
      gameTypes.value = listRes.data.game_types ?? [];
    } else {
      ElMessage.warning(listRes.message);
    }
    internalTypes.value =
      Number(internalRes.code) === 1000 ? (internalRes.data ?? []) : [];
  } finally {
    loading.value = false;
  }
}

async function loadFrontPageVisible() {
  try {
    const rows = await getCustomizedSettingApi();
    const row = (rows ?? []).find(
      (r: any) => Number(r.setting_key) === FRONT_PAGE_VISIBLE_KEY,
    );
    frontPageVisible.value = Number((row as any)?.value) === 1;
  } catch {
    frontPageVisible.value = false;
  }
}

/** 遊戲商顯示名稱（含補零 id） */
function vendorLabel(v: VendorCustomizedVendorOption) {
  const id = String(v.id).padStart(4, '0');
  return `${v.name ?? ''} (${id})`;
}

function vendorName(id?: number | string) {
  if (!id) return '';
  const v = vendors.value.find((x) => String(x.id) === String(id));
  return v?.name ?? '';
}

function vendorImage(id?: number | string) {
  if (!id) return '';
  const v = vendors.value.find((x) => String(x.id) === String(id));
  return v?.image ?? '';
}

/** 卡片顯示圖：項目自訂圖優先，否則退回遊戲商圖 */
function cardImage(item: VendorCustomizedItemRow) {
  return item.image || vendorImage(item.vendor_id);
}

function cardCaption(item: VendorCustomizedItemRow) {
  return item.name || vendorName(item.vendor_id);
}

// ---------- 排序模式 ----------
function initSortable() {
  if (sortable) {
    sortable.destroy();
    sortable = null;
  }
  if (!boardRef.value) return;
  sortable = Sortable.create(boardRef.value, {
    animation: 150,
    disabled: !sortMode.value,
    // 走模擬拖曳：原生 HTML5 拖放在彈窗的 overflow-y-auto 容器內不會自動捲動，且會吃掉滾輪事件
    forceFallback: true,
    fallbackOnBody: true,
    fallbackTolerance: 5,
    // 觸控裝置需長按才進入拖曳，避免一般滑動被判定成拖卡片
    delay: 200,
    delayOnTouchOnly: true,
    scroll: true,
    scrollSensitivity: 80,
    scrollSpeed: 15,
    bubbleScroll: true,
    onEnd: (evt) => {
      const { newIndex, oldIndex } = evt;
      if (oldIndex === undefined || newIndex === undefined) return;
      const arr = [...items.value];
      const [moved] = arr.splice(oldIndex, 1);
      if (moved) arr.splice(newIndex, 0, moved);
      items.value = arr;
    },
  });
}

watch(
  () => items.value.length,
  async (len) => {
    await nextTick();
    if (len > 0) initSortable();
  },
);

watch(sortMode, () => {
  if (sortable) sortable.option('disabled', !sortMode.value);
});

onBeforeUnmount(() => sortable?.destroy());

function enterSortMode() {
  sortMode.value = true;
}

async function saveSort() {
  saving.value = true;
  try {
    const ids = items.value.map((i) => i.id).join(',');
    const res = await updateVendorCustomizedSortsApi({
      group_id: groupId,
      ids,
    });
    if (Number(res.code) === 1000) {
      sortMode.value = false;
      ElMessage.success(tl('success'));
    } else {
      ElMessage.error(res.message || tl('failure'));
    }
  } finally {
    saving.value = false;
  }
}

function cancelSort() {
  sortMode.value = false;
  loadData();
}

// ---------- 編輯/新增彈窗 ----------
const dialogVisible = ref(false);
const dialogTitle = ref('');
const submitting = ref(false);

interface EditorForm {
  game_id: number | string;
  id: number | string;
  is_event: boolean;
  is_hot: boolean;
  is_new: boolean;
  item_type: number | string;
  name: string;
  path: string;
  status: number | string;
  type_id: number | string;
  vendor_front_page_image: string;
  vendor_id: number | string;
  vendor_image: string;
}

function emptyForm(): EditorForm {
  return {
    game_id: '',
    id: '',
    is_event: false,
    is_hot: false,
    is_new: false,
    item_type: 1,
    name: '',
    path: '',
    status: 1,
    type_id: '',
    vendor_front_page_image: '',
    vendor_id: '',
    vendor_image: '',
  };
}

const form = ref<EditorForm>(emptyForm());
const games = ref<Array<{ id: number | string; name?: string }>>([]);
const lobbyPreview = ref('');
const frontPagePreview = ref('');

/** 入口類型 → 各下拉是否可用 */
const enabled = computed(() => {
  const t = String(form.value.item_type);
  return {
    game_id: t === '2',
    path: t === '4',
    type_id: t === '3',
    vendor_id: t === '1' || t === '2' || t === '3',
  };
});

const namePlaceholder = computed(() => vendorName(form.value.vendor_id));

async function loadGamesForVendor(vendorId: number | string) {
  games.value = [];
  if (!vendorId) return;
  const res = await getVendorCustomizedGamesApi(vendorId);
  if (Number(res.code) === 1000) {
    games.value = res.games ?? [];
  }
}

async function onVendorChange() {
  const keep = form.value.game_id;
  await loadGamesForVendor(form.value.vendor_id);
  // 若新遊戲清單仍含原值則保留，否則清空
  form.value.game_id = games.value.some((g) => String(g.id) === String(keep))
    ? keep
    : '';
}

/** 讀圖成 base64 */
async function readFileAsBase64(e: Event, target: 'front' | 'lobby') {
  const input = e.target as HTMLInputElement;
  const file = input.files?.[0];
  if (!file) return;
  input.value = '';
  let result: string;
  try {
    result = await readImageAsDataUrl(file);
  } catch (error) {
    if (error instanceof ImageUploadError) {
      ElMessage.error(error.message);
      return;
    }
    throw error;
  }
  if (target === 'lobby') {
    lobbyPreview.value = result;
    form.value.vendor_image = result;
  } else {
    frontPagePreview.value = result;
    form.value.vendor_front_page_image = result;
  }
}

function openAdd() {
  form.value = emptyForm();
  games.value = [];
  lobbyPreview.value = '';
  frontPagePreview.value = '';
  dialogTitle.value = tl('add');
  dialogVisible.value = true;
}

async function openEdit(item: VendorCustomizedItemRow) {
  if (sortMode.value) return;
  loading.value = true;
  try {
    const res = await getVendorCustomizedItemApi(item.id);
    if (Number(res.code) !== 1000 || !res.data?.item) {
      ElMessage.error(res.message || tl('failure'));
      return;
    }
    const it = res.data.item;
    const vendor = res.data.vendor;
    form.value = {
      game_id: it.game_id ?? '',
      id: it.id,
      is_event: Boolean(it.is_event),
      is_hot: Boolean(it.is_hot),
      is_new: Boolean(it.is_new),
      item_type: it.item_type ?? 1,
      name: it.name ?? '',
      path: it.path ?? '',
      status: it.status ?? 1,
      type_id: it.type_id ?? '',
      vendor_front_page_image: '',
      vendor_id: it.vendor_id ?? '',
      vendor_image: '',
    };
    lobbyPreview.value = it.image || vendor?.image || '';
    frontPagePreview.value = it.front_page_image || vendor?.image || '';
    games.value = [];
    if (form.value.vendor_id) {
      await loadGamesForVendor(form.value.vendor_id);
      form.value.game_id = it.game_id ?? '';
    }
    dialogTitle.value = tl('edit');
    dialogVisible.value = true;
  } finally {
    loading.value = false;
  }
}

async function submitForm() {
  submitting.value = true;
  try {
    const payload: Parameters<typeof saveVendorCustomizedItemApi>[0] = {
      group_id: groupId,
      id: form.value.id || '',
      is_event: form.value.is_event,
      is_hot: form.value.is_hot,
      is_new: form.value.is_new,
      item_type: form.value.item_type,
      name: form.value.name,
      status: form.value.status,
      vendor_front_page_image: form.value.vendor_front_page_image,
      vendor_image: form.value.vendor_image,
    };
    // 只送該入口類型啟用的下拉
    if (enabled.value.vendor_id) payload.vendor_id = form.value.vendor_id;
    if (enabled.value.game_id) payload.game_id = form.value.game_id;
    if (enabled.value.type_id) payload.type_id = form.value.type_id;
    if (enabled.value.path) payload.path = form.value.path;

    const res = await saveVendorCustomizedItemApi(payload);
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      dialogVisible.value = false;
      loadData();
    } else if (Number(res.code) === 23_000) {
      ElMessage.error(tl('item_exists'));
    } else {
      ElMessage.error(res.message || tl('failure'));
    }
  } finally {
    submitting.value = false;
  }
}

/** 從編輯彈窗刪除目前這筆，成功後關閉彈窗 */
async function removeCurrent() {
  const label = `${form.value.name || vendorName(form.value.vendor_id)}(${form.value.id})`;
  try {
    await ElMessageBox.confirm(
      `${tl('confirm_delete')} ${label}`,
      tl('confirm'),
      { type: 'warning' },
    );
  } catch {
    return;
  }
  const res = await removeVendorCustomizedItemApi(form.value.id);
  if (Number(res.code) === 1000) {
    ElMessage.success(tl('success'));
    dialogVisible.value = false;
    loadData();
  } else {
    ElMessage.error(res.message || tl('failure'));
  }
}

onMounted(() => {
  loadData();
  loadFrontPageVisible();
});
</script>

<template>
  <Page
    :content-class="embedGroupId ? 'px-0' : ''"
    :title="`${tl('agnet_management')} > ${tl('menu_agent_group')} > ${tl('game_vendor_settings')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <div>
        <b>{{ tl('name') }}</b
        >：{{ group.name }}
        <span class="text-muted-foreground">({{ group.id ?? groupId }})</span>
      </div>
    </ElCard>

    <!-- 工具列 -->
    <ElCard class="mb-3" shadow="never">
      <div class="flex flex-wrap items-center gap-2">
        <ElButton type="primary" @click="openAdd">
          {{ tl('add') }}
        </ElButton>
        <template v-if="sortMode">
          <ElButton :loading="saving" type="info" @click="saveSort">
            {{ tl('btn_update') }}
          </ElButton>
          <ElButton @click="cancelSort">{{ tl('cancel') }}</ElButton>
        </template>
        <ElButton v-else @click="enterSortMode">
          {{ tl('draggable') }}
        </ElButton>

        <div class="ml-auto flex items-center gap-2">
          <span>{{ tl('items_per_row') }}</span>
          <ElSelect v-model="itemsPerRow" class="!w-20">
            <ElOption
              v-for="n in [1, 2, 3, 4, 5]"
              :key="n"
              :label="String(n)"
              :value="n"
            />
          </ElSelect>
        </div>
      </div>
    </ElCard>

    <!-- 看板 -->
    <ElCard v-loading="loading" shadow="never">
      <div ref="boardRef" :style="boardStyle" class="mx-auto">
        <div
          v-for="item in items"
          :key="item.id"
          :data-id="item.id"
          class="board-item p-2"
        >
          <div
            class="relative w-full"
            :class="{ 'opacity-40 grayscale': Number(item.status) === 0 }"
            :style="{ cursor: sortMode ? 'move' : 'pointer' }"
            @click="openEdit(item)"
          >
            <div
              class="flex aspect-[117/93] w-full items-center justify-center rounded"
            >
              <img
                v-if="cardImage(item)"
                :src="cardImage(item)"
                class="max-h-full max-w-full"
                draggable="false"
              />
            </div>
            <span
              v-if="item.is_new"
              class="absolute left-0 top-0 rounded bg-green-600 px-1 text-xs text-white"
            >
              NEW
            </span>
            <span
              v-if="item.is_hot"
              class="absolute right-0 top-0 rounded bg-red-600 px-1 text-xs text-white"
            >
              HOT
            </span>
            <span
              v-if="item.is_event"
              class="absolute left-1/2 top-0 -translate-x-1/2 rounded bg-amber-500 px-1 text-xs text-white"
            >
              {{ tl('event') }}
            </span>
            <span
              v-if="Number(item.status) === 2"
              class="absolute inset-x-0 bottom-0 bg-black/60 text-center text-xs text-white"
            >
              {{ tl('coming_soon') }}
            </span>
          </div>
          <div class="mt-1 truncate text-center text-sm">
            {{ cardCaption(item) }}
          </div>
        </div>
      </div>
      <div v-if="!loading && items.length === 0" class="py-8 text-center">
        {{ tl('no_record') }}
      </div>
    </ElCard>

    <!-- 編輯/新增彈窗 -->
    <ElDialog
      v-model="dialogVisible"
      :title="dialogTitle"
      align-center
      width="800"
    >
      <ElForm label-position="top" @submit.prevent="submitForm">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('game_vendor_lobby_icon')">
            <div class="w-full">
              <div
                class="mb-2 flex aspect-[117/93] items-center justify-center rounded"
              >
                <img
                  v-if="lobbyPreview"
                  :src="lobbyPreview"
                  class="max-h-full max-w-full"
                />
              </div>
              <label class="el-button el-button--info">
                {{ tl('upload') }}
                <input
                  type="file"
                  :accept="IMAGE_ACCEPT"
                  class="hidden"
                  @change="readFileAsBase64($event, 'lobby')"
                />
              </label>
              <div class="text-muted-foreground mt-1 text-xs">
                {{ imageUploadText('image_upload_hint') }}
              </div>
            </div>
          </ElFormItem>
          <ElFormItem
            v-if="frontPageVisible"
            :label="tl('game_vendor_front_page_board')"
          >
            <div class="w-full">
              <div
                class="mb-2 flex aspect-[117/93] items-center justify-center rounded"
              >
                <img
                  v-if="frontPagePreview"
                  :src="frontPagePreview"
                  class="max-h-full max-w-full"
                />
              </div>
              <label class="el-button el-button--info">
                {{ tl('upload') }}
                <input
                  type="file"
                  :accept="IMAGE_ACCEPT"
                  class="hidden"
                  @change="readFileAsBase64($event, 'front')"
                />
              </label>
              <div class="text-muted-foreground mt-1 text-xs">
                {{ imageUploadText('image_upload_hint') }}
              </div>
            </div>
          </ElFormItem>
        </div>

        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('game_vendor_entrance_type')">
            <ElSelect v-model="form.item_type" class="w-full">
              <ElOption
                v-for="t in [1, 2, 3, 4]"
                :key="t"
                :label="tl(`game_vendor_entrance_type_${t}`)"
                :value="t"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('name')">
            <ElInput v-model="form.name" :placeholder="namePlaceholder" />
          </ElFormItem>
        </div>

        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('vendor')">
            <ElSelect
              v-model="form.vendor_id"
              :disabled="!enabled.vendor_id"
              class="w-full"
              clearable
              filterable
              @change="onVendorChange"
            >
              <ElOption
                v-for="v in vendors"
                :key="v.id"
                :label="vendorLabel(v)"
                :value="v.id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('game_type')">
            <ElSelect
              v-model="form.type_id"
              :disabled="!enabled.type_id"
              class="w-full"
              clearable
              filterable
            >
              <ElOption
                v-for="gt in gameTypes"
                :key="gt.id"
                :label="gt.name"
                :value="gt.id"
              />
            </ElSelect>
          </ElFormItem>
        </div>

        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('game')">
            <ElSelect
              v-model="form.game_id"
              :disabled="!enabled.game_id"
              class="w-full"
              clearable
              filterable
            >
              <ElOption
                v-for="g in games"
                :key="g.id"
                :label="g.name"
                :value="g.id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('game_vendor_entrance_type_4')">
            <ElSelect
              v-model="form.path"
              :disabled="!enabled.path"
              class="w-full"
              clearable
            >
              <ElOption
                v-for="row in internalTypes"
                :key="row.parameter"
                :label="tl(`advertise_internal_${row.parameter.toLowerCase()}`)"
                :value="row.parameter"
              />
            </ElSelect>
          </ElFormItem>
        </div>

        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-4">
          <ElFormItem :label="tl('enable_status')">
            <ElSelect v-model="form.status" class="w-full">
              <ElOption :label="tl('disable')" :value="0" />
              <ElOption :label="tl('enable')" :value="1" />
              <ElOption :label="tl('coming_soon')" :value="2" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('is_new')">
            <ElSwitch v-model="form.is_new" />
          </ElFormItem>
          <ElFormItem :label="tl('is_hot')">
            <ElSwitch v-model="form.is_hot" />
          </ElFormItem>
          <ElFormItem :label="tl('event')">
            <ElSwitch v-model="form.is_event" />
          </ElFormItem>
        </div>
      </ElForm>

      <template #footer>
        <div class="flex items-center justify-between">
          <ElButton v-if="form.id" type="danger" @click="removeCurrent">
            {{ tl('delete') }}
          </ElButton>
          <span v-else></span>
          <div class="flex gap-2">
            <ElButton @click="dialogVisible = false">
              {{ tl('cancel') }}
            </ElButton>
            <ElButton :loading="submitting" type="primary" @click="submitForm">
              {{ tl('btn_update') }}
            </ElButton>
          </div>
        </div>
      </template>
    </ElDialog>
  </Page>
</template>
