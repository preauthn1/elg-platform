<script lang="ts" setup>
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue';

import { createIconifyIcon } from '@vben/icons';

import { ElButton, ElCheckbox, ElDialog } from 'element-plus';
import Sortable from 'sortablejs';

import { tl } from '#/locales';

/**
 * 工單4306：帳號管理表格的欄位設定——勾選顯示與拖曳調整順序。勾選狀態與順序由
 * 父層（member/index.vue）持久化到 localStorage，本元件只負責畫面。
 *
 * - 勾選與拖曳期間只改本元件自己的 local（表格不會跟著動）；關閉視窗（X／ESC／
 *   點遮罩）時才一次性 emit 最終狀態，表格只重新渲染一次。
 * - locked 欄位（帳號、功能）固定在表格最左與最右，不可取消、不可拖曳，畫面上
 *   分別釘在清單頭尾，不放進可拖曳區。
 * - 清單由上到下＝表格由左到右。
 */
defineOptions({ name: 'MemberColumnSettingsDialog' });

const props = defineProps<{
  /** 依目前順序排列（locked 在頭尾） */
  columns: ColumnDef[];
  /** 預設順序（不含 locked），給「恢復預設順序」用 */
  defaultOrder: string[];
}>();

const emit = defineEmits<{
  change: [{ order: string[]; visible: Record<string, boolean> }];
}>();

interface ColumnDef {
  key: string;
  label: string;
  locked?: boolean;
}

const GripIcon = createIconifyIcon('lucide:grip-vertical');

const visible = ref(false);
const local = reactive<Record<string, boolean>>({});
/** 可拖曳欄位的順序（不含 locked） */
const order = ref<string[]>([]);
/** 開啟當下的狀態，用來判斷關閉時是否真的有變更，沒變更就不用多 emit 一次 */
let openedWith = '';

const labelOf = computed(
  () => new Map(props.columns.map((c) => [c.key, c.label])),
);
const lockedHead = computed(() =>
  props.columns.filter((c, i) => c.locked && i === 0),
);
const lockedTail = computed(() =>
  props.columns.filter((c, i) => c.locked && i !== 0),
);

function snapshot() {
  return JSON.stringify({ order: order.value, visible: local });
}

function open(current: Record<string, boolean>) {
  for (const key of Object.keys(local)) delete local[key];
  for (const c of props.columns) {
    local[c.key] = c.locked ? true : (current[c.key] ?? true);
  }
  order.value = props.columns.filter((c) => !c.locked).map((c) => c.key);
  openedWith = snapshot();
  visible.value = true;
}

function toggle(key: string) {
  local[key] = !local[key];
}

function selectAll() {
  for (const c of props.columns) local[c.key] = true;
}

function deselectAll() {
  for (const c of props.columns) local[c.key] = Boolean(c.locked);
}

/** 只重排順序，勾選狀態不動；預設順序裡沒有、但目前清單有的欄位不會被丟掉 */
function resetOrder() {
  const current = new Set(order.value);
  order.value = props.defaultOrder.filter((k) => current.has(k));
}

/* ---- 拖曳排序 ---- */
const listRef = ref<HTMLElement>();
let sortable: null | Sortable = null;

function initSortable() {
  sortable?.destroy();
  sortable = null;
  if (!listRef.value) return;
  sortable = Sortable.create(listRef.value, {
    animation: 150,
    handle: '.drag-handle',
    // 走模擬拖曳：原生 HTML5 拖放在彈窗的 overflow-y-auto 容器內不會自動捲動
    forceFallback: true,
    fallbackOnBody: true,
    fallbackTolerance: 5,
    scroll: true,
    scrollSensitivity: 60,
    scrollSpeed: 12,
    bubbleScroll: true,
    onEnd: (evt) => {
      const { from, item, newIndex, oldIndex } = evt;
      if (oldIndex === undefined || newIndex === undefined) return;
      if (oldIndex === newIndex) return;
      // sortablejs 已經搬過 DOM：先搬回原位，再改 order 交給 Vue 重排，
      // 否則 Vue 依舊的 DOM 位置做 diff，清單會錯位
      item.remove();
      from.insertBefore(item, from.children[oldIndex] ?? null);
      const arr = [...order.value];
      const [moved] = arr.splice(oldIndex, 1);
      if (moved) arr.splice(newIndex, 0, moved);
      order.value = arr;
    },
  });
}

/** 關閉時（X／ESC／點遮罩）才一次性套用，表格只重新渲染這一次 */
watch(visible, async (isVisible) => {
  if (isVisible) {
    await nextTick();
    initSortable();
    return;
  }
  sortable?.destroy();
  sortable = null;
  if (snapshot() !== openedWith) {
    emit('change', { order: [...order.value], visible: { ...local } });
  }
});

onBeforeUnmount(() => sortable?.destroy());

defineExpose({ open });
</script>

<template>
  <ElDialog v-model="visible" :title="tl('column_settings')" width="460">
    <div class="mb-2 flex flex-wrap gap-2">
      <ElButton size="small" @click="selectAll">
        {{ tl('select_all') }}
      </ElButton>
      <ElButton size="small" @click="deselectAll">
        {{ tl('deselect_all') }}
      </ElButton>
      <ElButton size="small" @click="resetOrder">
        {{ tl('column_order_reset') }}
      </ElButton>
    </div>
    <div class="mb-2 text-xs text-gray-400">
      {{ tl('column_settings_drag_hint') }}
    </div>

    <div class="max-h-[60vh] overflow-y-auto rounded border px-2 py-1">
      <div
        v-for="col in lockedHead"
        :key="col.key"
        class="flex h-8 items-center gap-2"
      >
        <span class="w-4 shrink-0"></span>
        <ElCheckbox :model-value="true" disabled>{{ col.label }}</ElCheckbox>
      </div>

      <div ref="listRef">
        <div
          v-for="key in order"
          :key="key"
          class="flex h-8 items-center gap-2 rounded hover:bg-gray-100 dark:hover:bg-gray-800"
        >
          <span class="drag-handle flex shrink-0 cursor-move text-gray-400">
            <GripIcon class="size-4" />
          </span>
          <ElCheckbox :model-value="local[key] ?? true" @change="toggle(key)">
            {{ labelOf.get(key) }}
          </ElCheckbox>
        </div>
      </div>

      <div
        v-for="col in lockedTail"
        :key="col.key"
        class="flex h-8 items-center gap-2"
      >
        <span class="w-4 shrink-0"></span>
        <ElCheckbox :model-value="true" disabled>{{ col.label }}</ElCheckbox>
      </div>
    </div>
  </ElDialog>
</template>
