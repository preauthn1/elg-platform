import type { Ref } from 'vue';

import { onBeforeUnmount } from 'vue';

/** ElTable 是泛型元件，InstanceType<typeof ElTable> 在這個版本推不出型別；只需要 $el 就夠 */
export type DragPanTableRef = Ref<undefined | { $el: HTMLElement }>;

const DRAG_THRESHOLD_PX = 3;

/** 在這些元素上按下不啟動拖曳，保留它們原本的點擊行為 */
const INTERACTIVE_SELECTOR =
  'button, a, input, .el-checkbox, .el-dropdown, .el-tag';

/**
 * 表格拖曳平移（工單4306 後續回饋，target/tickets#4362 抽成共用）：欄位多、單頁筆數大時，
 * 水平捲軸在表格最底部，要先滑到底才找得到。改成在表格任意空白處按住拖曳即可左右平移。
 *
 * - 只在非互動元素（按鈕／連結／勾選框／下拉／標籤）上按下才啟動，避免蓋掉既有點擊行為。
 * - 真的有拖曳位移時攔掉緊接著的 click，避免放開時誤觸下方按鈕。
 * - 游標提示由使用端自己加 CSS（`.el-table__body-wrapper .cell { cursor: grab }`），
 *   拖曳中這裡會切成 grabbing。
 *
 * 用法：
 *   const tableRef: DragPanTableRef = ref();
 *   const { onTableDragStart } = useTableDragPan(tableRef);
 *   <ElTable ref="tableRef" @mousedown="onTableDragStart">
 */
export function useTableDragPan(tableRef: DragPanTableRef) {
  let dragState: null | {
    moved: boolean;
    startScrollLeft: number;
    startX: number;
  } = null;

  /**
   * 實際會水平捲動的元素。ElTable 的 body-wrapper 本身 overflow-x: hidden、寬度等於可視寬，
   * 設它的 scrollLeft 不會動；捲動的是裡面 el-scrollbar 的 wrap（捲它時表頭會跟著同步）。
   * 工單4455 回饋「新版拖不動」即因原本設錯這一層。
   */
  function scrollWrap(): HTMLElement | null {
    return (
      tableRef.value?.$el.querySelector(
        '.el-table__body-wrapper .el-scrollbar__wrap',
      ) ?? null
    );
  }

  function suppressClickOnce(e: MouseEvent) {
    e.stopPropagation();
    e.preventDefault();
  }

  function onMove(e: MouseEvent) {
    if (!dragState) return;
    const wrap = scrollWrap();
    if (!wrap) return;
    const delta = e.clientX - dragState.startX;
    if (dragState.moved || Math.abs(delta) > DRAG_THRESHOLD_PX) {
      dragState.moved = true;
      wrap.scrollLeft = dragState.startScrollLeft - delta;
      wrap.style.cursor = 'grabbing';
      document.body.style.userSelect = 'none';
    }
  }

  function stop() {
    const wrap = scrollWrap();
    if (wrap) wrap.style.cursor = '';
    document.body.style.userSelect = '';
    dragState = null;
    window.removeEventListener('mousemove', onMove);
    window.removeEventListener('mouseup', onEnd);
  }

  function onEnd() {
    if (dragState?.moved) {
      window.addEventListener('click', suppressClickOnce, {
        capture: true,
        once: true,
      });
    }
    stop();
  }

  function onTableDragStart(e: MouseEvent) {
    if (e.button !== 0) return;
    if ((e.target as HTMLElement).closest(INTERACTIVE_SELECTOR)) return;
    const wrap = scrollWrap();
    if (!wrap) return;
    dragState = {
      moved: false,
      startScrollLeft: wrap.scrollLeft,
      startX: e.clientX,
    };
    window.addEventListener('mousemove', onMove);
    window.addEventListener('mouseup', onEnd);
  }

  // 拖到一半換頁或關分頁時，別讓 window 上的監聽與 user-select 殘留
  onBeforeUnmount(stop);

  return { onTableDragStart };
}
