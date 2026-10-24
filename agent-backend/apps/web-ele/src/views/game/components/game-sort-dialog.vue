<script lang="ts" setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';

import {
  ElButton,
  ElDialog,
  ElEmpty,
  ElMessage,
  ElMessageBox,
  ElOption,
  ElPopover,
  ElRadio,
  ElRadioGroup,
  ElSelect,
} from 'element-plus';
import Sortable from 'sortablejs';

import {
  getGameListByTagApi,
  getGameTagDropdownApi,
  getGameTypeManageListApi,
  getVendorDropdownByTypeApi,
  getVendorInfoGameListApi,
  getVendorInfoListApi,
  updateGameTagSortApi,
  updateVendorInfoGameSortApi,
} from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'GameSortDialog' });

interface Card {
  id: number | string;
  image: string;
  name: string;
  typeName: string;
}

const visible = ref(false);
const staticLoaded = ref(false);

const mode = ref<'tags' | 'vendor'>('vendor');

// vendor 模式
const vendors = ref<Array<{ id: number; name: string }>>([]);
const vendorInfos = ref<Array<{ id: number | string; name: string }>>([]);
const vendorId = ref<number | string>('');
const vendorInfoId = ref<number | string>('');

// tags 模式
const tags = ref<Array<{ id: number; tag_name: string }>>([]);
const tagId = ref<number | string>('');

// 遊戲類別 type_id => 類別名稱，供排序卡片顯示類別 target/tickets#4098
const gameTypeNames = ref<Record<string, string>>({});

const cards = ref<Card[]>([]);
const loading = ref(false);
const boardRef = ref<HTMLElement>();
const scrollAreaRef = ref<HTMLElement>();
let sortable: null | Sortable = null;

const DEFAULT_ITEMS_PER_ROW = 3;
const MAX_ITEMS_PER_ROW = 9;
const itemsPerRow = ref(DEFAULT_ITEMS_PER_ROW);

async function open() {
  mode.value = 'vendor';
  vendorId.value = '';
  vendorInfoId.value = '';
  vendorInfos.value = [];
  tagId.value = '';
  cards.value = [];
  itemsPerRow.value = DEFAULT_ITEMS_PER_ROW;
  visible.value = true;
  if (!staticLoaded.value) {
    const [vendorList, tagList, gameTypeList] = await Promise.all([
      getVendorDropdownByTypeApi(1).then((v) => (v as any) ?? []),
      getGameTagDropdownApi().then((v) => (v as any) ?? []),
      getGameTypeManageListApi().then((v) => v ?? []),
    ]);
    vendors.value = vendorList;
    tags.value = tagList;
    gameTypeNames.value = Object.fromEntries(
      gameTypeList.map((t) => [String(t.id), t.name ?? '']),
    );
    staticLoaded.value = true;
  }
}
defineExpose({ open });

function initSortable() {
  if (sortable) {
    sortable.destroy();
    sortable = null;
  }
  if (!boardRef.value) return;
  sortable = Sortable.create(boardRef.value, {
    animation: 150,
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
      const arr = [...cards.value];
      const [moved] = arr.splice(oldIndex, 1);
      if (moved) arr.splice(newIndex, 0, moved);
      cards.value = arr;
    },
  });
}

watch(
  () => cards.value.length,
  async (len) => {
    await nextTick();
    if (len > 0) initSortable();
  },
);

// 卡片右鍵選單：置頂 target/tickets#4171
// sortablejs 只處理左鍵拖曳，右鍵不會誤觸拖曳
const menuVisible = ref(false);
const menuIndex = ref(-1);
const menuPosition = ref({ left: 0, top: 0 });
// 每次改座標都回傳新物件，讓 popper 重新定位（選單開著時右鍵另一張卡片也要跟著移動）
const menuVirtualRef = computed(() => ({
  getBoundingClientRect: () =>
    new DOMRect(menuPosition.value.left, example.invalid, 0, 0),
}));

function closeCardMenu() {
  menuVisible.value = false;
  menuIndex.value = -1;
}

function handleMenuKeydown(event: KeyboardEvent) {
  if (event.key !== 'Escape') return;
  // 先關選單，不讓 Escape 一路傳到彈窗把整個排序視窗關掉
  event.stopPropagation();
  closeCardMenu();
}

function openCardMenu(event: MouseEvent, index: number) {
  menuIndex.value = index;
  menuPosition.value = { left: event.clientX, top: event.clientY };
  if (!menuVisible.value) {
    menuVisible.value = true;
    return;
  }
  // 選單已開著又右鍵別張卡片：先關再開，逼 popper 依新座標重新定位
  menuVisible.value = false;
  nextTick(() => {
    menuVisible.value = true;
  });
}

// 只調整本地順序，仍需按「更新儲存」才會寫回後端（與拖拉排序一致）
function moveCardToTop() {
  const index = menuIndex.value;
  closeCardMenu();
  if (index <= 0) return;
  const arr = [...cards.value];
  const [moved] = arr.splice(index, 1);
  if (moved) arr.unshift(moved);
  cards.value = arr;
  scrollAreaRef.value?.scrollTo({ behavior: 'smooth', top: 0 });
}

function bindMenuCloseListeners(active: boolean) {
  const toggle = active
    ? document.addEventListener.bind(document)
    : document.removeEventListener.bind(document);
  toggle('click', closeCardMenu);
  toggle('contextmenu', closeCardMenu);
  toggle('keydown', handleMenuKeydown, true);
  // capture 才收得到卡片牆容器自身的捲動
  toggle('scroll', closeCardMenu, true);
}

watch(menuVisible, bindMenuCloseListeners);
watch(visible, (opened) => {
  if (!opened) closeCardMenu();
});

onBeforeUnmount(() => {
  sortable?.destroy();
  bindMenuCloseListeners(false);
});

function resolveTypeName(typeId: unknown) {
  return gameTypeNames.value[String(typeId ?? '')] || '-';
}

async function loadVendorInfos() {
  vendorInfoId.value = '';
  vendorInfos.value = [];
  if (!vendorId.value) return;
  const res = await getVendorInfoListApi({
    page: 1,
    per_page: 100,
    status: '',
    vendor_id: vendorId.value,
    vendor_info_id: '',
  });
  if (Number(res.code) === 1000) {
    vendorInfos.value = (res.data ?? []).map((v: any) => ({
      id: v.id,
      name: v.name,
    }));
    if (vendorInfos.value.length > 0) {
      vendorInfoId.value = vendorInfos.value[0]?.id ?? vendorInfoId.value;
    }
  }
}

async function loadVendorGames() {
  if (!vendorId.value || !vendorInfoId.value) {
    ElMessage.warning(tl('vendor_info_name'));
    return;
  }
  loading.value = true;
  try {
    const list = await getVendorInfoGameListApi({
      status: 1,
      vendor_id: vendorId.value,
      vendor_info_id: vendorInfoId.value,
    });
    cards.value = (list ?? []).map((g: any) => ({
      id: g.id,
      image: g.game_image ?? '',
      name: g.game_name ?? '',
      typeName: resolveTypeName(g.type_id),
    }));
  } finally {
    loading.value = false;
  }
}

async function loadTagGames() {
  if (!tagId.value) {
    ElMessage.warning(tl('game_tags'));
    return;
  }
  loading.value = true;
  try {
    const list = await getGameListByTagApi({
      page: 1,
      per_page: 5000,
      status: 1,
      tag_id: tagId.value,
    });
    // 依 aggt_sort 由大到小（同值 id 大者在前）
    const sorted = [...(list ?? [])].toSorted((a: any, b: any) => {
      if ((a.aggt_sort ?? 0) === (b.aggt_sort ?? 0)) {
        return (b.id ?? 0) - (a.id ?? 0);
      }
      return (b.aggt_sort ?? 0) - (a.aggt_sort ?? 0);
    });
    cards.value = sorted.map((g: any) => ({
      id: g.aggt_id,
      image: g.image ?? '',
      name: g.name ?? '',
      typeName: resolveTypeName(g.type_id),
    }));
  } finally {
    loading.value = false;
  }
}

function handleSearch() {
  closeCardMenu();
  cards.value = [];
  if (mode.value === 'vendor') loadVendorGames();
  else loadTagGames();
}

async function handleUpdate(isDefault = false) {
  if (cards.value.length === 0 && !isDefault) return;
  const ids = isDefault ? '' : cards.value.map((c) => c.id).join(',');
  try {
    await ElMessageBox.confirm(
      isDefault ? tl('default') : tl('btn_update'),
      tl('confirm'),
    );
  } catch {
    return;
  }
  const res =
    mode.value === 'vendor'
      ? await updateVendorInfoGameSortApi({
          ids,
          vendor_info_id: vendorInfoId.value,
        })
      : await updateGameTagSortApi({ ids, tag_id: tagId.value });
  if (Number(res.code) === 1000) {
    ElMessage.success(tl('success'));
    handleSearch();
  } else {
    ElMessage.error(res.message || tl('failure'));
  }
}

watch(vendorId, loadVendorInfos);
</script>

<template>
  <ElDialog v-model="visible" :title="tl('sorts')" width="1000" align-center>
    <div ref="scrollAreaRef" class="max-h-[74vh] overflow-y-auto">
      <ElRadioGroup v-model="mode" class="mb-3">
        <ElRadio value="vendor">{{ tl('label_vendor') }}</ElRadio>
        <ElRadio value="tags">{{ tl('game_tags') }}</ElRadio>
      </ElRadioGroup>

      <div
        v-if="mode === 'vendor'"
        class="mb-4 grid grid-cols-1 gap-4 md:grid-cols-3"
      >
        <div>
          <div class="mb-1 text-sm">{{ tl('label_vendor') }}</div>
          <ElSelect v-model="vendorId" class="w-full" filterable>
            <ElOption
              v-for="v in vendors"
              :key="v.id"
              :label="v.name"
              :value="v.id"
            />
          </ElSelect>
        </div>
        <div>
          <div class="mb-1 text-sm">{{ tl('vendor_info_name') }}</div>
          <ElSelect v-model="vendorInfoId" class="w-full" filterable>
            <ElOption
              v-for="vi in vendorInfos"
              :key="vi.id"
              :label="vi.name"
              :value="vi.id"
            />
          </ElSelect>
        </div>
        <div class="flex items-end">
          <ElButton :loading="loading" type="primary" @click="handleSearch">
            {{ tl('search') }}
          </ElButton>
        </div>
      </div>

      <div v-else class="mb-4 grid grid-cols-1 gap-4 md:grid-cols-3">
        <div class="md:col-span-2">
          <div class="mb-1 text-sm">{{ tl('game_tags') }}</div>
          <ElSelect v-model="tagId" class="w-full" filterable>
            <ElOption
              v-for="t in tags"
              :key="t.id"
              :label="t.tag_name"
              :value="t.id"
            />
          </ElSelect>
        </div>
        <div class="flex items-end">
          <ElButton :loading="loading" type="primary" @click="handleSearch">
            {{ tl('search') }}
          </ElButton>
        </div>
      </div>

      <template v-if="cards.length > 0">
        <div class="mb-3 flex flex-wrap items-center gap-2">
          <h3 class="mr-auto text-base font-medium">{{ tl('draggable') }}</h3>
          <span class="text-sm">{{ tl('items_per_row') }}</span>
          <ElSelect v-model="itemsPerRow" class="!w-20">
            <ElOption
              v-for="n in MAX_ITEMS_PER_ROW"
              :key="n"
              :label="String(n)"
              :value="n"
            />
          </ElSelect>
          <ElButton @click="handleUpdate(true)">{{ tl('default') }}</ElButton>
          <ElButton type="primary" @click="handleUpdate(false)">
            {{ tl('btn_update') }}
          </ElButton>
        </div>
        <div
          ref="boardRef"
          :style="{
            gridTemplateColumns: `repeat(${itemsPerRow}, minmax(0, 1fr))`,
          }"
          class="grid gap-3"
        >
          <div
            v-for="(card, index) in cards"
            :key="card.id"
            class="w-full cursor-move overflow-hidden rounded border bg-white dark:bg-transparent"
            @contextmenu.prevent.stop="openCardMenu($event, index)"
          >
            <img
              :src="card.image"
              style="width: 100%; height: 120px; object-fit: contain"
              draggable="false"
            />
            <div class="px-1 pt-1 text-center text-xs">{{ card.name }}</div>
            <div
              class="text-muted-foreground px-1 pb-1 text-center text-[11px]"
            >
              {{ card.typeName }}
            </div>
          </div>
        </div>
      </template>
      <ElEmpty v-else :description="tl('no_record')" />
    </div>

    <ElPopover
      :show-arrow="false"
      :virtual-ref="menuVirtualRef"
      :visible="menuVisible"
      placement="bottom-start"
      popper-class="!p-1 !min-w-0"
      virtual-triggering
      width="auto"
    >
      <ElButton
        class="!px-3 !py-2 !text-xl"
        link
        type="primary"
        @click.stop="moveCardToTop"
      >
        {{ tl('is_top') }}
      </ElButton>
    </ElPopover>
  </ElDialog>
</template>
