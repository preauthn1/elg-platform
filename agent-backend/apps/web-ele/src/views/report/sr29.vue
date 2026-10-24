<script lang="ts" setup>
import type { Sr29Metrics, Sr29Params, Sr29Result, Sr29Row } from '#/api/sr29';

import { computed, onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import dayjs from 'dayjs';
import {
  ElButton,
  ElCard,
  ElCheckbox,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
  ElTag,
} from 'element-plus';

import { getGameTypeListApi, getVendorListApi } from '#/api';
import { getSr29ListApi } from '#/api/sr29';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import { amountThousands, buildPlainAmounts } from '#/utils/format';
import { currentTzOffset, nowInUserTz } from '#/utils/timezone-shift';

defineOptions({ name: 'ReportSr29' });

/**
 * SR29 代理遊戲明細表（target/tickets#4318）。
 *
 * 需求文件標題寫「SR26 代理遊戲明細表」，SR26／SR27 編號已被既有報表佔用，
 * 經 PM 確認改掛 SR29。欄位名一律照需求文件的正式名（PM 拿試算表逐欄對），
 * 所以用 `sr29_*` 的語系 key 而不是後台既有的「押注」系 key。
 *
 * ## 下鑽（需求文件②③）
 *
 * **同頁面逐層換表，不開彈窗、不用 ＋／− 展開樹。** 第 0 層是「遊戲商 × 遊戲類別」，
 * 點遊戲商換成登入者的直屬下線（代理與直屬會員並列），代理列可續點、會員列是葉節點。
 * 麵包屑記錄路徑，點任一節點回溯。
 *
 * 🔴 **往回跳用快取、不重打 API**（需求文件③「取數時機」）：每一節麵包屑連同它那層的
 * 回應一起存在 `crumbs` 裡，回溯只是把陣列截短再讀回 `result`。要最新數字請重新查詢。
 * 所以「每點一層才打一次 API」這條，前進才打、後退不打。
 *
 * 🔴 **驗收核心**：本層所有列相加 ＝ 本層合計列 ＝ 上一層被點的那一列，每一層都要成立
 * （玩家人數欄除外，它是 COUNT DISTINCT 不可加）。這條由後端保證，畫面只負責把合計列
 * 固定置頂顯示、不自行加總。
 *
 * ## 不分頁、前端排序（使用者 2026-10-02 指定）
 *
 * 後端一次回整層。**只有主表可以排序**：預設下注金額由大到小，點欄位標題切換，
 * 合計列不參與排序、固定置頂。下鑽層不開放排序，固定照後端的順序：直屬會員在前、
 * 下級代理在後，各組內依下注金額由大到小（2026-09-29 定案，2026-10-02 再次確認）。
 *
 * **主表不分頁、下鑽層後端分頁**（使用者 2026-10-02 指定）：下鑽層預設每頁 10 筆，可選
 * 10／20／50／100／500／1000，**不提供「全部」**——正式站有直屬會員近四萬的代理，一次
 * 撈整層後端與瀏覽器都接不住。翻頁重打當前這一層；每一層的頁碼存在自己那節麵包屑，
 * 回溯讀快取、仍停在原本那頁。合計列一律是整層的合計（後端另算）、每頁都置頂；
 * 匯出的是整層而不是當頁（匯出時另外一次撈回）。
 *
 * ## 查詢列跟著下鑽同步
 *
 * 下鑽或點麵包屑回溯時，查詢列的遊戲商／遊戲類別／代理商帳號同步成當前這一層的條件
 * （見 syncFiltersTo()），讓使用者看得出現在看的是哪個範圍；此時按「查詢」就是以這組
 * 條件重查主表。
 */

/** 麵包屑的一節，連同那一層的回應一起快取 */
interface Crumb {
  /** 下鑽目標代理；第 0、1 層為 undefined */
  agentId?: number;
  /**
   * 下鑽鍵，與 vendorId 成對；第 0 層為 undefined。
   * 🔴 查詢表單的篩選不可以放這裡，見 `applied` 的註解。
   */
  gameTypeId?: number;
  label: string;
  /** 下鑽層的頁碼與單頁筆數，各層獨立（回溯後仍停在原本那頁）；主表不分頁、不用 */
  page: number;
  perPage: number;
  /** 該層的 API 回應，回溯時直接讀回、不重打 */
  result: null | Sr29Result;
  /** 下鑽鍵，與 gameTypeId 成對；第 0 層為 undefined（paramsFor 以此判定層級） */
  vendorId?: number;
}

/** 匯出整層時送的單頁筆數，等於後端 Request 的上限（那個上限就是給匯出用的） */
const EXPORT_PER_PAGE = 100_000;

interface PageSizeOption {
  label: string;
  value: number;
}

/**
 * 下鑽層的單頁筆數選項；預設取第一個（宣告成非空 tuple，取 [0] 不需要非空斷言）。
 * 🔴 刻意不提供「全部」，理由見檔頭「主表不分頁、下鑽層後端分頁」。
 */
const DRILL_PAGE_SIZE_OPTIONS: [PageSizeOption, ...PageSizeOption[]] = [
  { label: '10', value: 10 },
  { label: '20', value: 20 },
  { label: '50', value: 50 },
  { label: '100', value: 100 },
  { label: '500', value: 500 },
  { label: '1000', value: 1000 },
];

const DEFAULT_DRILL_PER_PAGE = DRILL_PAGE_SIZE_OPTIONS[0].value;

/**
 * 明細欄位。前三欄（下注時間／遊戲商／遊戲類別，下鑽層為下注時間／帳號）左側凍結
 * 且不開放取消勾選——凍結一個被隱藏的欄位沒有意義，與 SR27 同一個原則。
 */
const COLUMNS: Array<{ key: string; labelKey: string; locked?: boolean }> = [
  { key: 'vendor', labelKey: 'sr29_vendor', locked: true },
  { key: 'game_type', labelKey: 'game_type', locked: true },
  { key: 'account', labelKey: 'sr29_account', locked: true },
  { key: 'player_count', labelKey: 'sr29_player_count' },
  { key: 'bet_count', labelKey: 'sr29_bet_count' },
  { key: 'bet_amount', labelKey: 'sr29_bet_amount' },
  { key: 'valid_bet', labelKey: 'sr29_valid_bet' },
  { key: 'payout', labelKey: 'payout' },
  { key: 'third_party_fee', labelKey: 'sr29_third_party_fee' },
  { key: 'company_fee', labelKey: 'sr29_company_fee' },
  { key: 'member_win_lose', labelKey: 'member_win_lose' },
  { key: 'company_win_lose', labelKey: 'sr29_company_win_lose' },
];

/**
 * 這個 key 專屬 SR29，不與其他報表的欄位設定共用——換頁面就是另一組偏好，
 * 不可以改成共用 key，否則會互相覆蓋（SR27 踩過，見該頁同名常數）。
 */
const COLUMN_STORAGE_KEY = 'sr29-visible-columns';

const visibleColumns = reactive<Record<string, boolean>>(
  Object.fromEntries(COLUMNS.map((c) => [c.key, true])),
);
const columnSettingsVisible = ref(false);

function isVisible(key: string): boolean {
  return visibleColumns[key] !== false;
}

/** 私密瀏覽模式讀寫 localStorage 會丟例外，讀不到就用全部顯示的預設值 */
function loadColumnPrefs() {
  try {
    const raw = localStorage.getItem(COLUMN_STORAGE_KEY);
    if (!raw) return;
    const saved = JSON.parse(raw) as Record<string, boolean>;
    for (const c of COLUMNS) {
      const value = saved[c.key];
      if (!c.locked && typeof value === 'boolean') {
        visibleColumns[c.key] = value;
      }
    }
  } catch {
    // 讀不到就維持預設
  }
}

function saveColumnPrefs() {
  try {
    localStorage.setItem(COLUMN_STORAGE_KEY, JSON.stringify(visibleColumns));
  } catch {
    // 存不了不影響當下顯示
  }
}

function toggleColumn(key: string, checked: boolean) {
  visibleColumns[key] = checked;
  saveColumnPrefs();
}

function setAllColumns(checked: boolean) {
  for (const c of COLUMNS) {
    if (!c.locked) visibleColumns[c.key] = checked;
  }
  saveColumnPrefs();
}

const filters = reactive({
  agent_username: '',
  game_type_id: undefined as number | undefined,
  // 期間到「分」，與需求文件的畫面一致（2026-08-01 00:00 ~ 2026-08-31 23:59）；
  // 預設昨天 00:00 ~ 今天 23:59
  time_range: [
    nowInUserTz().subtract(1, 'day').format('YYYY-MM-DD 00:00'),
    nowInUserTz().format('YYYY-MM-DD 23:59'),
  ] as string[],
  vendor_id: undefined as number | undefined,
});

const vendors = ref<Array<{ id: number; name: string }>>([]);

/**
 * 「未分類」＝遊戲類別 id 0：彙總列的 game_id 查不到對應遊戲（來源沒給遊戲代碼）時
 * 後端歸到 0，名稱為 null。查詢下拉多一個 0 的選項（後端 filter_game_type_id 允許 0），
 * 表格、麵包屑與匯出一律顯示「未分類」而不是「—」。
 */
const UNCATEGORIZED_GAME_TYPE_ID = 0;

function gameTypeLabel(row: Pick<Sr29Row, 'game_type_name'>): string {
  return row.game_type_name ?? tl('sr29_uncategorized');
}
const gameTypes = ref<Array<{ id: number; name: string }>>([]);

const loading = ref(false);
const exporting = ref(false);
/**
 * 查詢當下的條件快照：下鑽過程沿用它，不受使用者中途改了篩選欄位影響。
 *
 * 遊戲商／類別篩選放這裡、不放第 0 層麵包屑的 vendorId／gameTypeId：那兩個是
 * **下鑽鍵**，後端以它們有沒有帶來判定層級。原本把篩選塞進去，結果只選遊戲商
 * 就 422、兩個都選則直接拿到下鑽層的資料，見 paramsFor()。
 */
const applied = ref<null | {
  end: string;
  gameTypeId?: number;
  start: string;
  username: string;
  vendorId?: number;
}>(null);
const crumbs = ref<Crumb[]>([]);

const current = computed<Crumb | undefined>(
  () => crumbs.value[crumbs.value.length - 1],
);
const rows = computed<Sr29Row[]>(() => current.value?.result?.list ?? []);
const summary = computed<null | Sr29Metrics>(
  () => current.value?.result?.summary ?? null,
);
/**
 * 合計列固定置頂（需求文件①：每張報表最上方都要有 summary）。
 *
 * ElTable 沒有原生的置頂列，這裡把合計併成資料的第一列、以 `row_type: 'summary'`
 * 標記，再由 `rowClass()` 上底色、各欄的 template 自行避開下鑽連結。
 * 🔴 合計取後端的 `summary`，**不在前端加總 `rows`**：玩家人數是 COUNT DISTINCT
 * 不可加，自行加總會得到錯的人數（需求文件註記②）。
 */
const tableData = computed<Sr29Row[]>(() => {
  const s = summary.value;
  return s === null
    ? sortedRows.value
    : [
        { ...s, row_type: 'summary' } as unknown as Sr29Row,
        ...sortedRows.value,
      ];
});

/** 下鑽層的總列數（不含合計列），後端在分頁資訊裡給；主表不分頁 */
const total = computed(() => current.value?.result?.pagination?.total ?? 0);
const page = computed(() => current.value?.page ?? 1);
const perPage = computed(
  () => current.value?.perPage ?? DEFAULT_DRILL_PER_PAGE,
);

const isSummary = (row: Sr29Row) => (row.row_type as string) === 'summary';

type SortOrder = 'ascending' | 'descending';

/** 預設依下注金額由大到小（使用者 2026-10-02 指定） */
const DEFAULT_SORT: { order: SortOrder; prop: string } = {
  order: 'descending',
  prop: 'bet_amount',
};

const sortState = reactive<{ order: null | SortOrder; prop: null | string }>({
  ...DEFAULT_SORT,
});

/** 文字欄（遊戲商／遊戲類別）依字串排，其餘都是數字 */
const TEXT_SORT_KEYS: Record<string, (r: Sr29Row) => string> = {
  game_type_name: (r) => gameTypeLabel(r),
  vendor_name: (r) => r.vendor_name ?? '',
};

function onSortChange({
  order,
  prop,
}: {
  order: null | SortOrder;
  prop: null | string;
}) {
  sortState.order = order;
  sortState.prop = prop;
}

/** 第 0 層顯示遊戲商／遊戲類別兩欄，下鑽層改成單一帳號欄 */
const isMainTable = computed(() => crumbs.value.length <= 1);

/**
 * 主表與下鑽層用兩個 ElTable 實例（模板以 isMainTable 當 key）。
 *
 * 🔴 不能共用同一個實例：ElTable 的排序狀態（sortingColumn）會跨層殘留，而它只對
 * `sortable="custom"` 的欄位跳過內建排序。下鑽層把欄位改成 `sortable=false` 後，
 * 殘留的「下注金額降冪」會被 ElTable 拿來用**字串**重排資料（949000 排在 712100
 * 前面），連置頂的合計列都會被排走。換 key 讓下鑽層拿到一個沒有排序狀態的新表；
 * 回到主表時以目前的 sortState 當 default-sort，排序箭頭與資料順序一致。
 */
const mainDefaultSort = computed(() =>
  sortState.order && sortState.prop
    ? { order: sortState.order, prop: sortState.prop }
    : undefined,
);

/**
 * 主表依目前排序重排。合計列不在這裡（tableData 另外放在最前面），所以永遠置頂。
 * 取消排序（第三次點標題）就回到後端給的順序，也就是下注金額由大到小。
 * 數字欄的 null 不論升降冪一律排最後。
 *
 * 🔴 下鑽層一律原樣回傳、不排序（使用者 2026-10-02 指定）：後端給的順序就是定案的
 * 「直屬會員在前、下級代理在後，各組內依下注金額由大到小」，欄位標題也不開放點排序。
 */
const sortedRows = computed<Sr29Row[]>(() => {
  const { order, prop } = sortState;
  if (!isMainTable.value || !order || !prop) return rows.value;

  const dir = order === 'ascending' ? 1 : -1;
  const textKey = TEXT_SORT_KEYS[prop];

  return rows.value.toSorted((a, b) => {
    if (textKey) return textKey(a).localeCompare(textKey(b)) * dir;

    const av = (a as unknown as Record<string, unknown>)[prop];
    const bv = (b as unknown as Record<string, unknown>)[prop];
    const aEmpty = av === null || av === undefined;
    const bEmpty = bv === null || bv === undefined;
    if (aEmpty || bEmpty) return Number(aEmpty) - Number(bEmpty);
    return (Number(av) - Number(bv)) * dir;
  });
});

function rowClass({ row }: { row: Sr29Row }): string {
  return isSummary(row) ? 'sr29-summary-row' : '';
}

/**
 * 合計列跨欄：主表併「下注時間＋遊戲商＋遊戲類別」三欄，下鑽層併「下注時間＋帳號」
 * 兩欄——就是被凍結的那幾欄。被併掉的欄位回 colspan 0 讓 ElTable 不要渲染。
 */
function spanMethod({
  row,
  columnIndex,
}: {
  columnIndex: number;
  row: Sr29Row;
}): undefined | { colspan: number; rowspan: number } {
  if (!isSummary(row)) return undefined;

  const merged = mergedColumnCount();
  if (columnIndex === 0) return { colspan: merged, rowspan: 1 };
  if (columnIndex < merged) return { colspan: 0, rowspan: 0 };

  return undefined;
}

/**
 * 併幾欄要跟實際渲染出來的凍結欄數一致，欄位被隱藏時不會算進來。
 * 下鑽層只有帳號欄一欄（等於不併，合計兩字就落在那一格）。
 */
function mergedColumnCount(): number {
  const keys = isMainTable.value ? ['vendor', 'game_type'] : ['account'];

  return keys.filter((k) => isVisible(k)).length;
}

/** 時間欄顯示查詢區間本身、不按日拆列（需求文件①，⑤(b) 不做按日檢視） */
const periodLabel = computed(() =>
  applied.value ? `${applied.value.start} ~ ${applied.value.end}` : '',
);

/** apis 成功碼為 200；失敗時顯示後端訊息 */
function ok(res: { code: number | string; message?: string }): boolean {
  if (Number(res.code) === 200) return true;
  if (res.message) ElMessage.error(res.message);
  return false;
}

function guard(): boolean {
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) {
    ElMessage.warning(tl('required_column'));
    return false;
  }
  // 後端 Request::withValidator 也擋一次；這裡先擋是為了不用送一趟才知道
  if (dayjs(end).isAfter(dayjs(start).add(3, 'month'))) {
    ElMessage.warning(tl('period_max_3_months'));
    return false;
  }
  return true;
}

/**
 * 組 API 參數：期間與代理帳號取查詢當下的快照，層級條件取該節麵包屑。
 *
 * 🔴 篩選與下鑽鍵分兩組參數送：第 0 層（麵包屑沒有 vendorId）送
 * `filter_vendor_id`／`filter_game_type_id`，兩者各自獨立；下鑽層送
 * `vendor_id`／`game_type_id`（成對，由被點的那一列鎖定，必然落在篩選範圍內，
 * 所以不再帶篩選）。
 */
function paramsFor(crumb: Crumb): null | Sr29Params {
  if (!applied.value) return null;

  const isMain = crumb.vendorId === undefined;

  return {
    ...(isMain
      ? {
          filter_game_type_id: applied.value.gameTypeId,
          filter_vendor_id: applied.value.vendorId,
        }
      : {}),
    agent_id: crumb.agentId,
    agent_username: applied.value.username || undefined,
    // 分頁只作用在下鑽層，主表不帶（後端也不理會）
    ...(isMain ? {} : { page: crumb.page, per_page: crumb.perPage }),
    // 期間已含時分，這裡只補秒：起補 :00、迄補 :59（皆為所選時區的牆鐘）
    end_time: `${applied.value.end}:59`,
    game_type_id: crumb.gameTypeId,
    start_time: `${applied.value.start}:00`,
    tz_offset: currentTzOffset(),
    vendor_id: crumb.vendorId,
  };
}

async function fetchInto(crumb: Crumb) {
  const params = paramsFor(crumb);
  if (!params) return;

  loading.value = true;
  try {
    const res = await getSr29ListApi(params);
    crumb.result = ok(res) ? res.data : null;
  } finally {
    loading.value = false;
  }
}

/** 按「查詢」→ 回第 0 層並清空麵包屑（需求文件③「查詢條件區」） */
async function search() {
  if (!guard()) return;
  const [start = '', end = ''] = filters.time_range ?? [];

  applied.value = {
    end,
    gameTypeId: filters.game_type_id,
    start,
    username: filters.agent_username.trim(),
    vendorId: filters.vendor_id,
  };
  // 第 0 層不帶下鑽鍵，篩選走 applied（理由見 applied 的註解）
  crumbs.value = [
    {
      label: tl('all'),
      page: 1,
      perPage: DEFAULT_DRILL_PER_PAGE,
      result: null,
    },
  ];
  // 從陣列讀回（響應式代理），理由同 drill() 的註解
  const root = crumbs.value[0];
  if (root) await fetchInto(root);
}

/** 點一列往下鑽：遊戲商列帶遊戲商＋類別，代理列帶 agent_id */
async function drill(row: Sr29Row) {
  if (!canDrill(row)) return;

  const parent = current.value;
  const crumb: Crumb =
    row.row_type === 'vendor'
      ? {
          gameTypeId: row.game_type_id,
          label: `${row.vendor_name ?? '—'}／${gameTypeLabel(row)}`,
          page: 1,
          perPage: perPage.value,
          result: null,
          vendorId: row.vendor_id,
        }
      : {
          // 下鑽過程遊戲商＋類別鎖定不變，沿用上一層的條件
          agentId: row.row_id,
          gameTypeId: parent?.gameTypeId,
          label: row.username ?? '—',
          page: 1,
          perPage: perPage.value,
          result: null,
          vendorId: parent?.vendorId,
        };

  crumbs.value.push(crumb);
  // 🔴 一定要把 push 進去之後讀回來的那個（響應式代理）交給 fetchInto，不能傳
  // 上面這個原始物件：`crumbs` 是 ref，深層響應是在「讀取」時才包代理的，直接改
  // 原始物件雖然資料會變，但不會通知 computed 重算，畫面會停在空表。
  const pushed = crumbs.value.at(-1);
  if (!pushed) return;
  syncFiltersTo(pushed);
  await fetchInto(pushed);
}

/**
 * 會員列是葉節點不可點；無投注的列後端本來就不回，不必另外擋。
 * 主表的遊戲商列一律可點。
 */
function canDrill(row: Sr29Row): boolean {
  return row.row_type !== 'member';
}

/** 翻頁只重打當前這一層，其他層的快取不動 */
async function handlePageChange(p: number) {
  const crumb = current.value;
  if (!crumb) return;
  crumb.page = p;
  await fetchInto(crumb);
}

/** 改單頁筆數一律回到第 1 頁，留在原本的頁碼可能已經超出總頁數 */
async function handleSizeChange(size: number) {
  const crumb = current.value;
  if (!crumb) return;
  crumb.perPage = size;
  crumb.page = 1;
  await fetchInto(crumb);
}

/**
 * 查詢列同步成某一層的條件（需求：下鑽時遊戲商／遊戲類別／代理商帳號自動帶成當前）。
 *
 * - 第 0 層：回到查詢當下的條件（applied）。
 * - 下鑽層：遊戲商＋類別取該層的下鑽鍵；代理商帳號在第 1 層沿用查詢當下的帳號
 *   （第 1 層的起點就是它，空白＝可視範疇的根），第 2 層以後是被點的那個代理。
 *
 * 只改畫面上的表單，不動 applied：下鑽與回溯仍沿用查詢當下的快照，使用者按
 * 「查詢」才會以表單上的條件重查。
 */
function syncFiltersTo(crumb: Crumb) {
  const base = applied.value;
  if (!base) return;

  if (crumb.vendorId === undefined) {
    filters.vendor_id = base.vendorId;
    filters.game_type_id = base.gameTypeId;
    filters.agent_username = base.username;
    return;
  }

  filters.vendor_id = crumb.vendorId;
  filters.game_type_id = crumb.gameTypeId;
  filters.agent_username =
    crumb.agentId === undefined ? base.username : crumb.label;
}

/** 點麵包屑回溯：截短陣列即可，該層的回應已在快取裡（需求文件③「往回跳」） */
function jumpTo(index: number) {
  if (index >= crumbs.value.length - 1) return;
  crumbs.value = crumbs.value.slice(0, index + 1);
  const target = crumbs.value[index];
  if (target) syncFiltersTo(target);
}

const fmt = (v?: null | number | string) =>
  v === null || v === undefined ? '—' : amountThousands(v, 4);
const fmtInt = (v?: null | number) =>
  v === null || v === undefined ? '—' : amountThousands(v, 0);

/** 綠正紅負；0 或 null 不上色。需求文件註記③：不要複製 SR12 的「正藍負紅」 */
function winLoseClass(v?: null | string): string {
  const n = Number(v);
  if (!v || Number.isNaN(n) || n === 0) return '';
  return n > 0 ? 'text-green-600' : 'text-red-500';
}

/** 輸贏帶正負號，讓正負不只靠顏色辨識 */
function fmtSigned(v?: null | string): string {
  const text = fmt(v);
  const n = Number(v);
  if (text === '—' || Number.isNaN(n) || n === 0) return text;
  return n > 0 ? `+${text}` : text;
}

/** 匯出當前所在層（畫面看到什麼就匯什麼），檔名帶麵包屑路徑（需求文件③「匯出」） */
async function handleExport() {
  const crumb = current.value;
  const period = applied.value;
  const params = crumb ? paramsFor(crumb) : null;
  if (!crumb?.result || !period || !params || rows.value.length === 0) {
    ElMessage.warning(tl('no_data'));
    return;
  }

  exporting.value = true;
  try {
    // 匯出當前所在層的「整層」：主表畫面上就是整層（照目前的排序）；下鑽層畫面只有
    // 當頁，另外一次撈回整層
    let exportRows = sortedRows.value;
    let exportSummary = summary.value;
    if (!isMainTable.value) {
      const full = await getSr29ListApi({
        ...params,
        page: 1,
        per_page: EXPORT_PER_PAGE,
      });
      if (!ok(full)) return;
      exportRows = full.data.list;
      exportSummary = full.data.summary;
    }

    // 分隔符用畫面麵包屑的「›」：`>` 會被匯出工具的 sanitizeFilename 換成 `_`
    const path = crumbs.value.map((c) => c.label).join(' › ');

    // 匯出欄位與畫面一致：主表是「遊戲商＋遊戲類別」，下鑽層是「帳號＋類型」。
    // 期間不另外開一欄，寫在 dateRange 稽核資訊裡（畫面也只顯示在麵包屑右上角）
    const head = isMainTable.value
      ? [tl('sr29_vendor'), tl('game_type')]
      : [tl('sr29_account'), tl('type')];

    const leading = (r: Sr29Row): Array<null | number | string> =>
      isMainTable.value
        ? [r.vendor_name ?? '—', gameTypeLabel(r)]
        : [r.username ?? '—', tl(r.row_type === 'agent' ? 'agent' : 'member')];

    // 用 exportRows 不用 tableData：tableData 的第一列已是合計，下面會另外 unshift
    const body: Array<Array<null | number | string>> = exportRows.map((r) => [
      ...leading(r),
      ...metricCells(r),
    ]);
    // 合計列固定置頂，匯出比照畫面
    body.unshift([
      tl('sr29_total'),
      ...Array.from({ length: head.length - 1 }, () => ''),
      ...metricCells(exportSummary),
    ]);

    // 🔴 路徑要放在 title，不能放 filename：exportExcel 只要有 title 就以
    // 「title＋日期」當檔名、filename 整個被忽略（原本路徑就是這樣不見的）。
    // 放進 title 後檔名與工作表的標題列都會帶到路徑；稽核紀錄的選單名稱另外用
    // menuName 指定，維持乾淨的報表名稱，不被每次不同的路徑打散。
    const menuName = tl('menu_report_sr29');

    await buildPlainAmounts(() =>
      exportExcel(
        'sr29',
        [
          ...head,
          tl('sr29_player_count'),
          tl('sr29_bet_count'),
          tl('sr29_bet_amount'),
          tl('sr29_valid_bet'),
          tl('payout'),
          tl('sr29_third_party_fee'),
          tl('sr29_company_fee'),
          tl('member_win_lose'),
          tl('sr29_company_win_lose'),
        ],
        body,
        {
          audit: {
            conditions: { ...params },
            menuName,
            // body 第一列是合計、不是資料，不給的話稽核筆數會多算 1
            recordCount: exportRows.length,
          },
          dateRange: [period.start, period.end],
          title: `${menuName}（${path}）`,
        },
      ),
    );
  } finally {
    exporting.value = false;
  }
}

function metricCells(m: null | Sr29Metrics): Array<null | number | string> {
  return [
    m?.player_count ?? '—',
    m?.bet_count ?? 0,
    m?.bet_amount ?? '0',
    m?.valid_bet ?? '0',
    m?.payout ?? '0',
    m?.third_party_fee ?? '—',
    m?.company_fee ?? '—',
    m?.member_win_lose ?? '0',
    m?.company_win_lose ?? '0',
  ];
}

/**
 * 進頁面只載下拉選項，**不做預查詢**——主表不帶遊戲商時是整條下線跨所有遊戲商的
 * 彙總，平台端登入更是整站，一進頁面就自動跑等於每次開頁都打一發重查詢。
 * 要按「查詢」才送。
 */
onMounted(async () => {
  loadColumnPrefs();
  const [v, g] = await Promise.all([
    getVendorListApi().catch(() => null),
    getGameTypeListApi().catch(() => null),
  ]);
  vendors.value = v ?? [];
  gameTypes.value = g ?? [];
});
</script>

<template>
  <Page :title="`${tl('statistical_report')} > ${tl('menu_report_sr29')}`">
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-3">
          <ElFormItem :label="tl('sr29_vendor')">
            <ElSelect
              v-model="filters.vendor_id"
              class="w-full"
              clearable
              filterable
            >
              <ElOption
                v-for="v in vendors"
                :key="v.id"
                :label="v.name"
                :value="v.id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('game_type')">
            <ElSelect v-model="filters.game_type_id" class="w-full" clearable>
              <ElOption
                :label="tl('sr29_uncategorized')"
                :value="UNCATEGORIZED_GAME_TYPE_ID"
              />
              <ElOption
                v-for="g in gameTypes"
                :key="g.id"
                :label="g.name"
                :value="g.id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('agent_account')">
            <ElInput v-model="filters.agent_username" clearable />
          </ElFormItem>
          <ElFormItem class="md:col-span-2" :label="tl('between')" required>
            <DateRangeFields
              v-model="filters.time_range"
              format="YYYY-MM-DD HH:mm"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm"
            />
          </ElFormItem>
          <!-- 按鈕擺在期間右邊那格空位，不另佔一列（ElFormItem 的 22px 下緣留白
               是為了跟同列的欄位對齊） -->
          <ElFormItem label-width="0">
            <div class="flex w-full justify-end gap-2">
              <ElButton
                :loading="exporting"
                type="success"
                @click="handleExport"
              >
                {{ tl('excel') }}
              </ElButton>
              <ElButton :loading="loading" native-type="submit" type="primary">
                {{ tl('search') }}
              </ElButton>
            </div>
          </ElFormItem>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <template #header>
        <div class="flex items-center justify-end">
          <ElButton size="small" @click="columnSettingsVisible = true">
            {{ tl('sr29_column_settings') }}
          </ElButton>
        </div>
      </template>
      <!-- 麵包屑：點任一節點跳回該層，目前所在層不可點 -->
      <div
        v-if="crumbs.length > 0"
        class="bg-muted mb-3 flex flex-wrap items-center gap-1.5 rounded-md px-3 py-2 text-sm"
      >
        <template v-for="(c, i) in crumbs" :key="i">
          <span v-if="i > 0" class="text-muted-foreground">›</span>
          <span v-if="i === crumbs.length - 1" class="font-medium">
            {{ c.label }}
          </span>
          <ElButton v-else link type="primary" @click="jumpTo(i)">
            {{ c.label }}
          </ElButton>
        </template>
        <ElTag class="ml-auto" size="large" type="primary">
          {{ periodLabel }}
        </ElTag>
      </div>

      <ElTable
        v-loading="loading"
        border
        :key="isMainTable ? 'main' : 'drill'"
        :data="tableData"
        :empty-text="applied ? undefined : tl('please_search_first')"
        :default-sort="isMainTable ? mainDefaultSort : undefined"
        :row-class-name="rowClass"
        size="small"
        :span-method="spanMethod"
        @sort-change="onSortChange"
      >
        <template v-if="isMainTable">
          <ElTableColumn
            v-if="isVisible('vendor')"
            fixed="left"
            :label="tl('sr29_vendor')"
            prop="vendor_name"
            :show-overflow-tooltip="false"
            sortable="custom"
            width="230"
          >
            <template #default="{ row }">
              <span v-if="isSummary(row as Sr29Row)" class="font-medium">
                {{ tl('sr29_total') }}
              </span>
              <ElButton
                v-else
                class="!whitespace-normal !text-left"
                link
                type="primary"
                @click="drill(row as Sr29Row)"
              >
                {{ row.vendor_name ?? '—' }}
              </ElButton>
            </template>
          </ElTableColumn>
          <ElTableColumn
            v-if="isVisible('game_type')"
            fixed="left"
            :label="tl('game_type')"
            prop="game_type_name"
            sortable="custom"
            width="130"
          >
            <template #default="{ row }">
              <template v-if="!isSummary(row as Sr29Row)">
                {{ gameTypeLabel(row as Sr29Row) }}
              </template>
            </template>
          </ElTableColumn>
        </template>
        <template v-else>
          <ElTableColumn
            v-if="isVisible('account')"
            fixed="left"
            :label="tl('sr29_account')"
            width="240"
          >
            <template #default="{ row }">
              <span v-if="isSummary(row as Sr29Row)" class="font-medium">
                {{ tl('sr29_total') }}
              </span>
              <template v-else>
                <ElButton
                  v-if="canDrill(row as Sr29Row)"
                  link
                  type="primary"
                  @click="drill(row as Sr29Row)"
                >
                  {{ row.username ?? '—' }}
                </ElButton>
                <span v-else>{{ row.username ?? '—' }}</span>
                <ElTag
                  class="ml-1"
                  :type="row.row_type === 'agent' ? 'primary' : 'success'"
                  size="small"
                >
                  {{ tl(row.row_type === 'agent' ? 'agent' : 'member') }}
                </ElTag>
              </template>
            </template>
          </ElTableColumn>
        </template>

        <ElTableColumn
          v-if="isVisible('player_count')"
          align="right"
          :label="tl('sr29_player_count')"
          min-width="100"
          prop="player_count"
          :sortable="isMainTable ? 'custom' : false"
        >
          <template #default="{ row }">{{ fmtInt(row.player_count) }}</template>
        </ElTableColumn>
        <ElTableColumn
          v-if="isVisible('bet_count')"
          align="right"
          :label="tl('sr29_bet_count')"
          min-width="110"
          prop="bet_count"
          :sortable="isMainTable ? 'custom' : false"
        >
          <template #default="{ row }">{{ fmtInt(row.bet_count) }}</template>
        </ElTableColumn>
        <ElTableColumn
          v-if="isVisible('bet_amount')"
          align="right"
          :label="tl('sr29_bet_amount')"
          min-width="140"
          prop="bet_amount"
          :sortable="isMainTable ? 'custom' : false"
        >
          <template #default="{ row }">{{ fmt(row.bet_amount) }}</template>
        </ElTableColumn>
        <ElTableColumn
          v-if="isVisible('valid_bet')"
          align="right"
          :label="tl('sr29_valid_bet')"
          min-width="140"
          prop="valid_bet"
          :sortable="isMainTable ? 'custom' : false"
        >
          <template #default="{ row }">{{ fmt(row.valid_bet) }}</template>
        </ElTableColumn>
        <ElTableColumn
          v-if="isVisible('payout')"
          align="right"
          :label="tl('payout')"
          min-width="140"
          prop="payout"
          :sortable="isMainTable ? 'custom' : false"
        >
          <template #default="{ row }">{{ fmt(row.payout) }}</template>
        </ElTableColumn>
        <ElTableColumn
          v-if="isVisible('third_party_fee')"
          align="right"
          :label="tl('sr29_third_party_fee')"
          min-width="130"
        >
          <template #default="{ row }">{{ fmt(row.third_party_fee) }}</template>
        </ElTableColumn>
        <ElTableColumn
          v-if="isVisible('company_fee')"
          align="right"
          :label="tl('sr29_company_fee')"
          min-width="130"
        >
          <template #default="{ row }">{{ fmt(row.company_fee) }}</template>
        </ElTableColumn>
        <ElTableColumn
          v-if="isVisible('member_win_lose')"
          align="right"
          :label="tl('member_win_lose')"
          min-width="140"
          prop="member_win_lose"
          :sortable="isMainTable ? 'custom' : false"
        >
          <template #default="{ row }">
            <span :class="winLoseClass(row.member_win_lose)">
              {{ fmtSigned(row.member_win_lose) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          v-if="isVisible('company_win_lose')"
          align="right"
          :label="tl('sr29_company_win_lose')"
          min-width="140"
          prop="company_win_lose"
          :sortable="isMainTable ? 'custom' : false"
        >
          <template #default="{ row }">
            <span :class="winLoseClass(row.company_win_lose)">
              {{ fmtSigned(row.company_win_lose) }}
            </span>
          </template>
        </ElTableColumn>
      </ElTable>

      <!-- 只有下鑽層分頁；主表不分頁 -->
      <div
        v-if="!isMainTable"
        class="mt-4 flex flex-wrap items-center justify-end gap-3"
      >
        <div class="flex items-center gap-2">
          <span class="text-muted-foreground text-sm">
            {{ tl('per_page') }}
          </span>
          <ElSelect
            class="w-28"
            :model-value="perPage"
            size="small"
            @update:model-value="handleSizeChange"
          >
            <ElOption
              v-for="o in DRILL_PAGE_SIZE_OPTIONS"
              :key="o.value"
              :label="o.label"
              :value="o.value"
            />
          </ElSelect>
        </div>
        <TablePagination
          class="!mt-0"
          :current-page="page"
          layout="total, prev, pager, next, jumper"
          :page-size="perPage"
          :total="total"
          @current-change="handlePageChange"
        />
      </div>
    </ElCard>

    <ElDialog
      v-model="columnSettingsVisible"
      :title="tl('sr29_column_settings')"
      width="520px"
    >
      <div class="mb-3 flex gap-2">
        <ElButton size="small" @click="setAllColumns(true)">
          {{ tl('select_all') }}
        </ElButton>
        <ElButton size="small" @click="setAllColumns(false)">
          {{ tl('cancel') }}
        </ElButton>
      </div>
      <div class="grid grid-cols-2 gap-2">
        <ElCheckbox
          v-for="c in COLUMNS"
          :key="c.key"
          :disabled="c.locked"
          :label="tl(c.labelKey)"
          :model-value="isVisible(c.key)"
          @update:model-value="(v) => toggleColumn(c.key, v === true)"
        />
      </div>
    </ElDialog>
  </Page>
</template>

<style scoped>
/* 合計列固定置頂並與明細列區隔（需求文件①） */
:deep(.sr29-summary-row) {
  font-weight: 600;
  background-color: var(--el-fill-color-light);
}
</style>
