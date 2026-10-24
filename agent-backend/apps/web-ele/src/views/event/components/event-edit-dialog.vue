<script lang="ts" setup>
import { computed, reactive, ref } from 'vue';

import { useUserStore } from '@vben/stores';

import dayjs from 'dayjs';
import {
  ElButton,
  ElCard,
  ElCheckbox,
  ElDatePicker,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElMessageBox,
  ElOption,
  ElRadio,
  ElRadioGroup,
  ElSelect,
  ElTable,
  ElTableColumn,
  ElTabPane,
  ElTabs,
} from 'element-plus';

import {
  getAdvertiseViewApi,
  getEventActionsApi,
  getEventManageListApi,
  getEventParamListApi,
  getEventViewDetailApi,
  getGameTypeListApi,
  getGeneralAgentDropdownApi,
  getLanguagesApi,
  getPrizeTypeListApi,
  getVendorListApi,
  saveEventApi,
  saveEventJackpotApi,
  uploadAdvertiseImageApi,
} from '#/api';
import { requestClient } from '#/api/request';
import ImageListDialog from '#/components/image-list-dialog.vue';
import { useAgentOptions } from '#/composables/use-agent-options';
import { tl } from '#/locales';
import {
  IMAGE_ACCEPT,
  ImageUploadError,
  imageUploadText,
  readImageAsDataUrl,
} from '#/utils/image-upload';

const emit = defineEmits<{ success: [] }>();

const userStore = useUserStore();
const hostId = computed(() =>
  Number((userStore.userInfo as any)?.host_id ?? 0),
);

/** 上傳中的圖片欄位（`語系:欄位`）；網址還沒回來就存檔會漏圖，故存檔時擋住 */
const uploading = reactive<Record<string, boolean>>({});
const isUploading = computed(() => Object.keys(uploading).length > 0);

interface ImgSlot {
  base64: string;
  id: number | string;
  preview: string;
}
interface PrizeRow {
  flow_limit_multiple: string;
  id: number | string;
  name: string;
  names: Record<number, string>;
  num: number | string;
  number: string;
  percentage: string;
  point: string;
  point_type: number | string;
  random_point_max: string;
  /** 0=金額、1=百分比 */
  type: number | string;
}
interface FlowLimitRow {
  game_type_id: number | string;
  game_type_name: string;
  num: number | string;
  vendor_id: number | string;
  vendor_name: string;
}
/** 彩池獎項層級（type_id==3 12 欄 grid） */
interface JackpotRow {
  achieve_point: string;
  agent_number: string;
  end_time: string;
  expire_date: string;
  expire_hour: string;
  flow_limit_multiple: string;
  id: number | string;
  /** 提交值：新列為圖庫圖片 id，編輯帶入為既有 image 值 */
  image: string;
  image_id: number | string;
  /** 1=流水、2=輸分 */
  jackpot_type_id: number | string;
  member_number: string;
  number_of_member_in_agent: string;
  pay_number: string;
  point: string;
  sort: string;
  start_time: string;
}

const visible = ref(false);
const editId = ref('');
const isEdit = computed(() => Boolean(editId.value));

const picker = ref<InstanceType<typeof ImageListDialog>>();
const loading = ref(false);
const saving = ref(false);
const activeTab = ref('step1');
const langTab = ref('default');
const canEdit = ref(false);

const types = ref<Array<{ parameter?: string; value: number | string }>>([]);
const actions = ref<Array<{ parameter?: string; value: number | string }>>([]);
const languages = ref<Array<{ id: number; name: string }>>([]);
const vendors = ref<Array<{ id: number; name: string }>>([]);
const gameTypes = ref<Array<{ id: number; name: string }>>([]);
const prizeTypes = ref<Array<{ id: number; name: string }>>([]);
const {
  loading: agentSearching,
  options: agents,
  pin: pinAgent,
  search: searchAgents,
  unpin: unpinAgent,
} = useAgentOptions();
const {
  loading: excludeSearching,
  options: excludeAgents,
  pin: pinExcludeAgent,
  search: searchExclude,
  unpin: unpinExcludeAgent,
} = useAgentOptions();
const generalAgents = ref<Array<Record<string, any>>>([]);

/** 帶入既有活動設定（target/tickets#2983）：僅限建立活動，彩池活動（type_id==3）不支援 */
const sourceEventId = ref<number | string>('');
const sourceEventSearching = ref(false);
const sourceEventOptions = ref<
  Array<{ agent_username: string; id: number | string; title: string }>
>([]);
/** 已成功帶入過一次後，之後每次改選來源都視為「已填寫」要先確認覆寫 */
const sourceEventApplied = ref(false);
/** 上一次成功套用的來源活動 id；取消確認或帶入失敗時下拉退回這個值 */
const lastAppliedSourceId = ref<number | string>('');
let sourceEventSeq = 0;

function blankForm() {
  return {
    achieve_payout_people: '',
    account_block: false,
    action_id: '' as number | string,
    agent_id: '' as number | string,
    agent_ids: [] as Array<number | string>,
    bind_unlimited: '0' as number | string,
    browser_block: false,
    content: '',
    contents: {} as Record<number, string>,
    detail_id: 0 as number | string,
    end_time: dayjs().add(1, 'month').format('YYYY-MM-DD 23:59'),
    event_day_limit: '',
    event_mode: false,
    event_total_limit: '',
    exclude_agent_ids: [] as Array<number | string>,
    expire_hour: '',
    game_type_id: '' as number | string,
    global_account_block: false,
    global_ip_block: false,
    global_phone_block: false,
    ip_block: false,
    ip_limit: '',
    max_point: '',
    only_weekend: false,
    // 0＝全部；存檔時空陣列本來就會被補成 [0]，預設就勾起來讓畫面與實際存的一致
    order_type_id: [0] as Array<number | string>,
    phone_block: false,
    point: '',
    prize_cancel_other_reward: '0' as number | string,
    prize_deduct_principal: '0' as number | string,
    prize_draw_date: dayjs().add(1, 'month').format('YYYY-MM-DD 23:59'),
    prize_expire_date: dayjs().add(1, 'month').format('YYYY-MM-DD 23:59'),
    prize_flow_limit_include_principal: '0' as number | string,
    prize_flow_limit_multiple: '' as number | string,
    prize_is_repeatedly: '1' as number | string,
    prize_name: '',
    prize_number: '',
    prize_random_point_checkbox_input: false,
    prize_type_id: '' as number | string,
    prize_withdrawal_cancel_reward: '0' as number | string,
    realname_block: false,
    start_amount: '',
    start_time: dayjs().format('YYYY-MM-DD 00:00'),
    tgid_block: false,
    times_a_day: '',
    title: '',
    titles: {} as Record<number, string>,
    type_id: '' as number | string,
    vendor_id: '' as number | string,
  };
}

const form = reactive(blankForm());

const prizeRows = ref<PrizeRow[]>([]);
const flowRows = ref<FlowLimitRow[]>([]);
/** 彩池模式（type_id==3）：切換 jackpot 子流程 */
const isJackpot = computed(() => Number(form.type_id) === 3);
/** 彩池獎項層級列 + 多語獎勵名稱 */
const jackpotRows = ref<JackpotRow[]>([]);
const jackpotNames = reactive<Record<number, string>>({});
function blankJackpotRow(): JackpotRow {
  return {
    achieve_point: '',
    agent_number: '',
    end_time: '',
    expire_date: '',
    expire_hour: '',
    flow_limit_multiple: '',
    id: 0,
    image: '',
    image_id: 0,
    jackpot_type_id: 1,
    member_number: '',
    number_of_member_in_agent: '',
    pay_number: '',
    point: '',
    sort: String(jackpotRows.value.length + 1),
    start_time: '',
  };
}
function addJackpotRow() {
  jackpotRows.value.push(blankJackpotRow());
}
function removeJackpotRow(i: number) {
  jackpotRows.value.splice(i, 1);
}
const imageLanguage = ref<number[]>([1]);
const bannerLanguage = ref<number[]>([1]);
/** images[langId] = { mobile, pc }；banner[langId] */
const images = reactive<
  Record<number, { banner: ImgSlot; mobile: ImgSlot; pc: ImgSlot }>
>({});

// 新增獎勵列暫存
const addPrize = reactive({
  flow_limit_multiple: '',
  name: '',
  number: '',
  percentage: '',
  point: '',
  point_type: '1' as number | string,
  random_point_max: '',
  type: 0 as number | string,
});
// 獎勵內容編號自動流水（對齊舊版 #p_num：從 1 起、每新增一列 +1）
const prizeNumSeq = ref(1);
// 新增流水限制列暫存
const addFlow = reactive({
  game_type_id: '' as number | string,
  num: '' as number | string,
  vendor_id: '' as number | string,
});

/** 依 action_id 決定區塊顯隱 */
const DEPOSIT_ACTIONS = new Set([1, 4, 51, 52, 53, 54, 55, 56, 57, 58, 59]);
/** 可否重覆獲取依 action 強制值並鎖定（對齊舊版 r1/r2 自動點選＋disabled） */
const REPEAT_FORCE_0 = new Set([8, 9, 11, 14, 51, 53]);
const REPEAT_FORCE_1 = new Set([16, 52, 54, 55]);

const ui = computed(() => {
  const a = Number(form.action_id ?? -1);
  const isDeposit = DEPOSIT_ACTIONS.has(a);
  const isInvite = a === 31 || a === 12 || a === 16;
  const isSlot9 = a === 9;
  const isRegister = a === 11;
  const showGame =
    isSlot9 || (!isDeposit && !isInvite && !isRegister && a !== 17 && a > 0);
  return {
    showDeposit: isDeposit,
    showGame,
    showUnlimited: a === 31,
    showEventMode: isDeposit || isRegister || a === 17,
    // 每日充(55)/每日充自動(56)/註冊(11) 亦顯示獎勵領取時限（對齊舊版）
    showExpireHour:
      isSlot9 || showGame || a === 17 || a === 55 || a === 56 || isRegister,
    showAchievePayout: a === 16,
    // 老虎機輸分回扣(9)/邀請(31,12)/每日連續儲值(59) 不填活動獎勵點數（後端固定 1）
    showRewardPoints: !(a === 9 || a === 31 || a === 12 || a === 59),
    // 流水倍數含本金/扣除本金僅儲值類動作顯示
    showFlowPrincipal: isDeposit,
    showRandomWeekend: [51, 52, 53, 54, 55].includes(a),
    showRealnameBlock: a === 31,
    showWithdrawalCancel: [51, 52, 53, 54, 55, 56].includes(a),
    showTimesADay: a === 55,
    repeatLocked: REPEAT_FORCE_0.has(a) || REPEAT_FORCE_1.has(a),
  };
});
/** 對齊舊版 action change：強制可否重覆獲取（55 UI 鎖「可」、送出轉限時 2） */
function applyRepeatForce() {
  const a = Number(form.action_id);
  if (REPEAT_FORCE_0.has(a)) form.prize_is_repeatedly = '0';
  else if (REPEAT_FORCE_1.has(a)) form.prize_is_repeatedly = '1';
}
function onActionChange(v: number | string) {
  form.action_id = v;
  applyRepeatForce();
  // 對齊舊版：切到非遊戲類時重設遊戲商/遊戲類型（舊版 reset 回「請選擇」）
  if (!ui.value.showGame) {
    form.vendor_id = '';
    form.game_type_id = '';
  }
}

function ensureImg(langId: number) {
  if (!images[langId]) {
    images[langId] = {
      banner: { base64: '', id: 0, preview: '' },
      mobile: { base64: '', id: 0, preview: '' },
      pc: { base64: '', id: 0, preview: '' },
    };
  }
  return images[langId];
}

async function loadMe() {
  const me = await requestClient
    .get<Record<string, any>>('/me')
    .catch(() => null);
  canEdit.value = Number(me?.id) === 1 || Number(me?.host_id) === 1;
}

async function loadDropdowns() {
  const [t, ac, lg, vd, gt, pt] = await Promise.all([
    getEventParamListApi('event_type').catch(() => []),
    getEventActionsApi().catch(() => []),
    getLanguagesApi().catch(() => []),
    getVendorListApi().catch(() => []),
    getGameTypeListApi().catch(() => []),
    getPrizeTypeListApi().catch(() => []),
    searchAgents(''),
    searchExclude(''),
  ]);
  types.value = t ?? [];
  actions.value = (ac ?? []) as any;
  languages.value = (lg ?? []) as any;
  vendors.value = vd ?? [];
  gameTypes.value = gt ?? [];
  prizeTypes.value = (pt ?? []) as any;
}

function prefillFromEvent(d: Record<string, any>) {
  const detail = (d.details ?? [])[0] ?? {};
  form.agent_id = d.agent_id ?? '';
  pinAgent([{ id: d.agent_id, username: d.agent_username }]);
  form.type_id = d.type_id ?? '';
  form.title = d.title ?? '';
  form.content = d.content ?? '';
  form.detail_id = detail.id ?? 0;
  onActionChange(detail.action_id ?? '');
  form.point = String(detail.point ?? '');
  form.expire_hour = String(detail.expire_hour ?? '');
  form.start_time = (detail.start_time ?? '').slice(0, 16);
  form.end_time = (detail.end_time ?? '').slice(0, 16);
  form.bind_unlimited = String(detail.bind_unlimited ?? '0');
  form.vendor_id = detail.vendor_id ?? '';
  form.game_type_id = detail.game_type_id ?? '';
  form.event_mode = Number(detail.vendor_lock) === 1;
  for (const k of [
    'ip_block',
    'phone_block',
    'account_block',
    'realname_block',
    'tgid_block',
    'global_ip_block',
    'global_phone_block',
    'global_account_block',
  ] as const) {
    (form as any)[k] = Number(detail[k]) === 1;
  }
  form.order_type_id = (d.details ?? []).map((r: any) => r.order_type_id);
  // 多語標題/內容
  for (const lang of d.languages ?? []) {
    form.titles[lang.language_id] = lang.title ?? '';
    form.contents[lang.language_id] = lang.content ?? '';
  }
  // 彩池（type_id==3）prefill：start_amount + 層級列 + 多語獎勵名稱
  form.start_amount = String(d.start_amount ?? '');
  jackpotRows.value = (d.jackpot ?? []).map((r: any) => ({
    achieve_point: String(r.achieve_point ?? ''),
    agent_number: String(r.agent_number ?? ''),
    end_time: (r.end_time ?? '').slice(0, 16),
    expire_date: r.expire_date ?? '',
    expire_hour: String(r.expire_hour ?? ''),
    flow_limit_multiple: String(r.flow_limit_multiple ?? ''),
    id: r.id ?? 0,
    image: r.image ?? '',
    image_id: r.image ?? '',
    jackpot_type_id: Number(r.type) === 1 ? 1 : 2,
    member_number: String(r.member_number ?? ''),
    number_of_member_in_agent: String(r.number_of_member_in_agent ?? ''),
    pay_number: String(r.pay_number ?? ''),
    point: String(r.point ?? ''),
    sort: String(r.sort ?? ''),
    start_time: (r.start_time ?? '').slice(0, 16),
  }));
  for (const n of d.names ?? []) {
    jackpotNames[n.language_id] = n.name ?? '';
  }
  // 獎勵設定
  form.prize_type_id = d.prize_type_id ?? '';
  form.prize_name = d.prize_name ?? '';
  form.max_point = String(d.max_point ?? '');
  form.prize_is_repeatedly = String(d.prize_is_repeatedly ?? '1');
  form.prize_expire_date = (d.prize_expire_date ?? '').slice(0, 16);
  form.prize_draw_date = (d.prize_draw_date ?? '').slice(0, 16);
  form.prize_number = String(d.prize_number ?? '');
  form.prize_flow_limit_multiple = String(d.prize_flow_limit_multiple ?? '');
  form.prize_flow_limit_include_principal = String(
    d.prize_flow_limit_include_principal ?? '0',
  );
  form.event_day_limit = String(d.event_day_limit ?? '');
  form.event_total_limit = String(d.event_total_limit ?? '');
  form.achieve_payout_people = String(d.achieve_payout_people ?? '');
  form.times_a_day = String(d.times_a_day ?? '');
  form.prize_withdrawal_cancel_reward = String(
    d.prize_withdrawal_cancel_reward ?? '0',
  );
  form.prize_cancel_other_reward = String(d.prize_cancel_other_reward ?? '0');
  form.only_weekend = Number(d.only_weekend) === 1;
  // 獎勵內容列
  prizeRows.value = (d.prizes ?? []).map((p: any) => ({
    flow_limit_multiple: String(p.flow_limit_multiple ?? ''),
    id: p.id,
    name: p.name ?? '',
    names: Object.fromEntries(
      (p.names ?? []).map((n: any) => [n.language_id, n.name]),
    ),
    num: p.id,
    number: String(p.number ?? ''),
    percentage: String(p.percentage ?? ''),
    point: String(Number(p.type) === 0 ? (p.point ?? '') : p.point_percentage),
    point_type: String(p.point_type ?? 1),
    random_point_max: String(p.random_point_max ?? ''),
    type: p.type ?? 0,
  }));
  // 流水限制列
  flowRows.value = (d.flow_limit ?? []).map((r: any) => ({
    game_type_id: r.game_type_id,
    game_type_name:
      String(r.game_type_name) === '0' ? tl('unlimited') : r.game_type_name,
    num: r.prize_content_id,
    vendor_id: r.vendor_id,
    vendor_name:
      String(r.vendor_name) === '0' ? tl('unlimited') : r.vendor_name,
  }));
  // 圖片
  const imgLangs: number[] = [];
  for (const row of d.image ?? []) {
    const slot = ensureImg(row.language_id);
    slot.mobile.preview = row.image ?? '';
    slot.pc.preview = row.image_pc ?? '';
    imgLangs.push(row.language_id);
  }
  imageLanguage.value = imgLangs.length > 0 ? imgLangs : [1];
  const banLangs: number[] = [];
  for (const row of d.banner ?? []) {
    const slot = ensureImg(row.language_id);
    slot.banner.preview = row.image ?? '';
    slot.banner.base64 = row.image ?? '';
    slot.banner.id = row.image_id ?? '';
    banLangs.push(row.language_id);
  }
  bannerLanguage.value = banLangs.length > 0 ? banLangs : [1];
}

/** 把既有圖片網址轉回 base64，供複製活動時重新走一次正常上傳流程 */
async function fetchImageAsBase64(url: string): Promise<string> {
  const res = await fetch(url);
  const blob = await res.blob();
  // 共用處理依副檔名判斷格式，網址不一定帶副檔名，優先用回應的 MIME 組檔名
  const ext = blob.type.split('/')[1] ?? '';
  const name = ext
    ? `image.${ext}`
    : (new URL(url, location.href).pathname.split('/').pop() ?? '');
  return await readImageAsDataUrl(new File([blob], name, { type: blob.type }));
}

/**
 * 複製活動的圖片沒有可重用的圖庫 id（event_images 只存最終網址），
 * 逐張重新 fetch 轉 base64 後走現有上傳流程，等同使用者手動重新上傳同一張圖。
 * 單張失敗不擋整個帶入流程，使用者仍可在「新增圖片」分頁自行補傳。
 */
async function recopyImages() {
  const jobs: Array<Promise<void>> = [];
  for (const langId of Object.keys(images).map(Number)) {
    const im = images[langId];
    if (!im) continue;
    for (const slot of ['mobile', 'pc', 'banner'] as const) {
      const url = im[slot].preview;
      if (!url) continue;
      jobs.push(
        fetchImageAsBase64(url)
          .then(async (base64) => {
            const res = await uploadAdvertiseImageApi({
              agent_id: hostId.value,
              base64Image: base64,
            }).catch(() => null);
            if (!res || Number(res.code) !== 1000) return;
            const path = res.data?.path ?? '';
            const imageId = Number(res.data?.advertise_image_id ?? 0);
            im[slot].preview = path || base64;
            im[slot].base64 = imageId > 0 ? path : base64;
            im[slot].id = imageId;
          })
          .catch((error) => {
            // 圖片來源網址 fetch 失敗（例如跨網域擋下），略過這張，不擋整體帶入
            if (error instanceof ImageUploadError) {
              ElMessage.error(error.message);
            }
          }),
      );
    }
  }
  await Promise.all(jobs);
}

/** 「帶入既有活動設定」下拉：關鍵字為純數字送 id 精確比對，否則送 title 模糊比對 */
async function searchSourceEvent(keyword: string) {
  const current = ++sourceEventSeq;
  sourceEventSearching.value = true;
  try {
    const trimmed = keyword.trim();
    const isId = trimmed !== '' && /^\d+$/.test(trimmed);
    const res = await getEventManageListApi({
      page: 1,
      per_page: 20,
      ...(isId ? { id: Number(trimmed) } : { title: trimmed }),
    }).catch(() => null);
    if (current !== sourceEventSeq) return;
    // agent/api/event/list 已遷入 apis，成功碼是 200（舊 API_Agent 才是 1000）
    const ok = res && (Number(res.code) === 1000 || Number(res.code) === 200);
    sourceEventOptions.value = ok ? (res.data ?? []) : [];
  } finally {
    if (current === sourceEventSeq) sourceEventSearching.value = false;
  }
}

/** 是否已有使用者填寫內容：涵蓋名稱/內容、設定獎勵、獎勵內容、圖片等主要分頁 */
function hasUnsavedInput(): boolean {
  if (sourceEventApplied.value) return true;
  if (form.title || form.content) return true;
  if (
    Object.values(form.titles).some(Boolean) ||
    Object.values(form.contents).some(Boolean)
  ) {
    return true;
  }
  if (form.prize_name || form.point || form.expire_hour) return true;
  if (prizeRows.value.length > 0 || flowRows.value.length > 0) return true;
  if (
    Object.values(images).some(
      (im) => im.mobile.preview || im.pc.preview || im.banner.preview,
    )
  ) {
    return true;
  }
  return false;
}

/** 帶入既有活動設定：取完整活動明細，套用既有的 prefillFromEvent，再覆寫成「複製為新活動」的語意 */
async function applySourceEvent(id: number | string) {
  loading.value = true;
  try {
    const res = await getEventViewDetailApi(id).catch(() => null);
    if (!res || Number(res.code) !== 1000 || !res.data) {
      ElMessage.error(res?.message || tl('failure'));
      sourceEventId.value = lastAppliedSourceId.value;
      return;
    }
    const d = res.data;
    // 確保下拉顯示得出這筆（可能來自列表「複製」按鈕直接帶入，不是搜尋選出來的）
    if (!sourceEventOptions.value.some((o) => String(o.id) === String(id))) {
      sourceEventOptions.value = [
        {
          agent_username: d.agent_username ?? '',
          id,
          title: d.title ?? '',
        },
        ...sourceEventOptions.value,
      ];
    }
    if (Number(d.type_id) === 3) {
      ElMessage.warning(tl('event_copy_jackpot_unsupported'));
      sourceEventId.value = lastAppliedSourceId.value;
      return;
    }

    prefillFromEvent(d);
    // 以下覆寫 prefillFromEvent 針對「編輯」語意的設定，改成「複製為新活動」：
    // 新活動還沒有自己的 detail，代理改用建立模式的多選欄位
    form.detail_id = 0;
    form.agent_ids = d.agent_id ? [d.agent_id] : [];
    form.exclude_agent_ids = (d.exclude_agent ?? []).map(
      (a: any) => a.id as number | string,
    );
    pinExcludeAgent(d.exclude_agent ?? []);
    // 名稱加「（複製）」提醒營運改名，各語系都加
    if (form.title) form.title = `${form.title}（複製）`;
    for (const k of Object.keys(form.titles)) {
      const langId = Number(k);
      if (form.titles[langId]) {
        form.titles[langId] = `${form.titles[langId]}（複製）`;
      }
    }
    // 獎勵內容重新編號＋解鎖（來源的 id 只在「編輯」語意下用來鎖定既有列不可改），
    // 流水限制原本用 num 對應某一列獎勵，換號後要跟著換，否則存檔後對不到新活動自己的獎勵
    const numMap = new Map<string, number>();
    prizeRows.value = prizeRows.value.map((p) => {
      const newNum = prizeNumSeq.value++;
      numMap.set(String(p.num), newNum);
      return { ...p, id: -1, num: newNum };
    });
    const remappedFlowRows: FlowLimitRow[] = [];
    for (const r of flowRows.value) {
      const mapped = numMap.get(String(r.num));
      if (mapped !== undefined) remappedFlowRows.push({ ...r, num: mapped });
    }
    flowRows.value = remappedFlowRows;

    await recopyImages();

    sourceEventId.value = id;
    sourceEventApplied.value = true;
    lastAppliedSourceId.value = id;
  } finally {
    loading.value = false;
  }
}

/** 下拉改選來源活動：已填過內容時先確認覆寫，取消則退回上一次成功套用的來源 */
async function onSourceEventChange(id: number | string) {
  if (!id) {
    sourceEventId.value = lastAppliedSourceId.value;
    return;
  }
  if (hasUnsavedInput()) {
    try {
      await ElMessageBox.confirm(
        tl('event_copy_overwrite_confirm'),
        tl('prompt'),
        { cancelButtonText: tl('cancel'), confirmButtonText: tl('confirm') },
      );
    } catch {
      sourceEventId.value = lastAppliedSourceId.value;
      return;
    }
  }
  await applySourceEvent(id);
}

/** 靜態下拉/身分只載一次（跨多次開窗共用） */
let staticLoaded = false;

/** 清回初始狀態：彈窗重開時避免殘留上一次的表單/列/圖片 */
function resetState() {
  Object.assign(form, blankForm());
  unpinAgent();
  unpinExcludeAgent();
  sourceEventId.value = '';
  sourceEventApplied.value = false;
  sourceEventOptions.value = [];
  lastAppliedSourceId.value = '';
  prizeRows.value = [];
  flowRows.value = [];
  jackpotRows.value = [];
  for (const k of Object.keys(jackpotNames)) delete jackpotNames[Number(k)];
  for (const k of Object.keys(images)) delete images[Number(k)];
  imageLanguage.value = [1];
  bannerLanguage.value = [1];
  activeTab.value = 'step1';
  langTab.value = 'default';
  prizeNumSeq.value = 1;
  Object.assign(addPrize, {
    flow_limit_multiple: '',
    name: '',
    number: '',
    percentage: '',
    point: '',
    point_type: '1',
    random_point_max: '',
    type: 0,
  });
  addFlow.vendor_id = '';
  addFlow.game_type_id = '';
}

/**
 * @param id 要編輯的活動 id；空字串＝建立活動
 * @param copyFromId 建立活動時，開窗即帶入這個既有活動的設定（活動列表「複製」按鈕用）
 */
async function open(
  id: number | string = '',
  copyFromId: number | string = '',
) {
  editId.value = String(id || '');
  resetState();
  visible.value = true;
  await load();
  if (!isEdit.value && copyFromId) {
    await applySourceEvent(copyFromId);
  }
}

async function load() {
  loading.value = true;
  try {
    if (!staticLoaded) {
      await Promise.all([loadMe(), loadDropdowns()]);
      staticLoaded = true;
    }
    if (isEdit.value) {
      const res = await getEventViewDetailApi(editId.value);
      if (Number(res.code) === 1000 && res.data) prefillFromEvent(res.data);
      await getAdvertiseViewApi(editId.value).catch(() => null);
    } else {
      generalAgents.value =
        (await getGeneralAgentDropdownApi().catch(() => [])) ?? [];
      // 新增：選擇方式預設第一個（對齊舊版 select 預設選首項）
      const firstAction = actions.value[0];
      if (firstAction) form.action_id = firstAction.value;
      searchSourceEvent('').catch(() => {});
    }
  } finally {
    loading.value = false;
  }
}

function pickImage(slot: 'banner' | 'mobile' | 'pc', langId: number) {
  picker.value?.open(`${slot}:${langId}`);
}
/**
 * 從電腦桌面上傳：選檔後立刻上傳進圖庫，只留下圖片位址與 id。
 * 若把 base64 留到存檔時一次送，語系一多 payload 就會大到 timeout（對齊舊後台一張一存）。
 */
function uploadImage(slot: 'banner' | 'mobile' | 'pc', langId: number) {
  const input = document.createElement('input');
  input.type = 'file';
  input.accept = IMAGE_ACCEPT;
  input.addEventListener('change', async () => {
    const file = input.files?.[0];
    if (!file) return;
    const key = `${langId}:${slot}`;
    uploading[key] = true;
    let base64: string;
    try {
      base64 = await readImageAsDataUrl(file);
    } catch (error) {
      delete uploading[key];
      ElMessage.error(
        error instanceof ImageUploadError ? error.message : tl('failure'),
      );
      return;
    }
    const im = ensureImg(langId);
    im[slot].preview = base64;
    try {
      const res = await uploadAdvertiseImageApi({
        agent_id: hostId.value,
        base64Image: base64,
      }).catch(() => null);
      if (!res || Number(res.code) !== 1000) {
        im[slot].preview = '';
        ElMessage.error(res?.message || tl('failure'));
        return;
      }
      const url = res.data?.path ?? '';
      const imageId = Number(res.data?.advertise_image_id ?? 0);
      im[slot].preview = url || base64;
      // 後端只在 image_id 為 0 時才把 image 當 base64 解，所以沒拿到 id 就得留 base64
      im[slot].base64 = imageId > 0 ? url : base64;
      im[slot].id = imageId;
    } finally {
      delete uploading[key];
    }
  });
  input.click();
}
/** 彩池層級列圖片（target 以列索引帶入） */
function pickJackpotImage(index: number) {
  picker.value?.open(`jackpotimg:${index}`);
}
function onImageSelected(p: {
  id: number | string;
  target: string;
  url: string;
}) {
  const [slot, key] = p.target.split(':');
  if (slot === 'jackpotimg') {
    const row = jackpotRows.value[Number(key)];
    if (row) {
      row.image = p.url;
      row.image_id = p.id;
    }
    return;
  }
  const langId = Number(key);
  const im = ensureImg(langId);
  if (slot === 'pc') {
    im.pc.preview = p.url;
    im.pc.base64 = p.url;
    im.pc.id = p.id;
  } else if (slot === 'banner') {
    im.banner.preview = p.url;
    im.banner.base64 = p.url;
    im.banner.id = p.id;
  } else {
    im.mobile.preview = p.url;
    im.mobile.base64 = p.url;
    im.mobile.id = p.id;
  }
}

function addPrizeRow() {
  if (!addPrize.name) {
    ElMessage.warning(tl('reward_name'));
    return;
  }
  prizeRows.value.push({
    flow_limit_multiple: addPrize.flow_limit_multiple,
    id: -1,
    name: addPrize.name,
    names: {},
    num: prizeNumSeq.value,
    number: addPrize.number,
    percentage: addPrize.percentage,
    point: addPrize.point,
    point_type: addPrize.point_type,
    random_point_max: addPrize.random_point_max,
    type: addPrize.type,
  });
  prizeNumSeq.value = Number(prizeNumSeq.value) + 1;
  Object.assign(addPrize, {
    flow_limit_multiple: '',
    name: '',
    number: '',
    percentage: '',
    point: '',
    point_type: '1',
    random_point_max: '',
    type: 0,
  });
}
function removePrizeRow(i: number) {
  prizeRows.value.splice(i, 1);
}
/** 可獲得點數 $(金額,0)/%(百分比,1) 切換；既有列（id>0）鎖定不可改 */
function setPrizeType(row: PrizeRow, t: number) {
  if (Number(row.id) > 0) return;
  row.type = t;
}

function addFlowRow() {
  if (!addFlow.vendor_id && !addFlow.game_type_id) return;
  flowRows.value.push({
    game_type_id: addFlow.game_type_id || 0,
    game_type_name:
      gameTypes.value.find((g) => g.id === addFlow.game_type_id)?.name ??
      tl('unlimited'),
    num: addFlow.num === '' ? 0 : addFlow.num,
    vendor_id: addFlow.vendor_id || 0,
    vendor_name:
      vendors.value.find((v) => v.id === addFlow.vendor_id)?.name ??
      tl('unlimited'),
  });
  addFlow.num = '';
  addFlow.vendor_id = '';
  addFlow.game_type_id = '';
}
function removeFlowRow(i: number) {
  flowRows.value.splice(i, 1);
}

function buildPayload(): Record<string, any> {
  const data: Record<string, any> = {};
  if (isEdit.value) {
    data.id = editId.value;
    data.agent_id = form.agent_id;
  } else {
    data.agent_ids = form.agent_ids;
    // 複製/帶入既有活動設定建立的新活動預設停用，待營運確認無誤後再手動啟用（target/tickets#2983）。
    // API_Agent 的 newCreate 尚未支援此欄位前，多送的 status 會被忽略、行為不變。
    if (sourceEventApplied.value) data.status = 0;
  }
  data.exclude_agent_ids = form.exclude_agent_ids;
  data.type_id = form.type_id;
  data.title = form.title;
  data.content = form.content === '' ? ' ' : form.content;
  const langs: Array<{ content: string; language_id: number; title: string }> =
    [];
  for (const lang of languages.value) {
    const tv = form.titles[lang.id];
    if (tv) {
      langs.push({
        content: form.contents[lang.id] ?? '',
        language_id: lang.id,
        title: tv,
      });
    }
  }
  if (langs.length > 0) data.languages = langs;
  const a = Number(form.action_id);
  data.details = {
    account_block: form.account_block ? 1 : 0,
    action_id: form.action_id,
    bind_unlimited: form.bind_unlimited,
    browser_block: form.browser_block ? 1 : 0,
    end_time: `${form.end_time}:59`,
    expire_hour: form.expire_hour,
    game_type_id: ui.value.showGame ? form.game_type_id : '',
    global_account_block: form.global_account_block ? 1 : 0,
    global_ip_block: form.global_ip_block ? 1 : 0,
    global_phone_block: form.global_phone_block ? 1 : 0,
    id: form.detail_id,
    ip_block: form.ip_block ? 1 : 0,
    // 空陣列後端不會建 event_detail（會產生無 detail 的殘缺活動害列表掛掉），比照舊版預設帶「全部」(0)
    order_type_id: form.order_type_id.length > 0 ? form.order_type_id : [0],
    phone_block: form.phone_block ? 1 : 0,
    // 對齊舊版：31/9/12/15 由 ajax 強制 1、59 由 JS 直接把輸入設 1
    point: [9, 12, 15, 31, 59].includes(a) ? 1 : form.point,
    realname_block: form.realname_block ? 1 : 0,
    start_time: `${form.start_time}:00`,
    tgid_block: form.tgid_block ? 1 : 0,
    vendor_id: ui.value.showGame ? form.vendor_id : '',
    vendor_lock: form.event_mode ? 1 : 0,
  };
  data.prize_type_id = form.prize_type_id;
  data.prize_name = form.prize_name;
  data.max_point = form.max_point;
  data.prize_number = form.prize_number || 1;
  // 對齊舊版兩層行為：先依 action 強制可/不可重複，55/15 再由 ajax 蓋成「限時」(2)
  let repeatForced = form.prize_is_repeatedly;
  if (REPEAT_FORCE_0.has(a)) repeatForced = '0';
  else if (REPEAT_FORCE_1.has(a)) repeatForced = '1';
  data.prize_is_repeatedly = [15, 55].includes(a) ? 2 : repeatForced;
  data.prize_withdrawal_cancel_reward = form.prize_withdrawal_cancel_reward;
  data.prize_cancel_other_reward = form.prize_cancel_other_reward;
  data.prize_flow_limit_include_principal =
    form.prize_flow_limit_include_principal;
  data.prize_flow_limit_multiple = form.prize_flow_limit_multiple || 0;
  data.prize_deduct_principal = form.prize_deduct_principal;
  data.achieve_payout_people = form.achieve_payout_people || 0;
  data.prize_expire_date = `${form.prize_expire_date}:59`;
  data.prize_draw_date = `${form.prize_draw_date}:59`;
  data.event_day_limit = form.event_day_limit || 0;
  data.event_total_limit = form.event_total_limit || 0;
  data.ip_limit = form.ip_limit || 0;
  data.vendor_lock = form.event_mode ? 1 : 0;
  data.times_a_day = form.times_a_day || 1;
  data.only_weekend = form.only_weekend ? 1 : 0;
  const enableRandom = form.prize_random_point_checkbox_input;
  data.prizes = prizeRows.value
    .filter((p) => p.name)
    .map((p) => {
      const row: Record<string, any> = {
        flow_limit_multiple: p.flow_limit_multiple,
        id: p.id,
        name: p.name,
        names: p.names,
        num: p.num,
        number: p.number,
        percentage: p.percentage,
        point: Number(p.type) === 0 ? p.point : 0,
        point_percentage: Number(p.type) === 1 ? p.point : 0,
        point_type: p.point_type,
        type: p.type,
      };
      if (enableRandom) row.random_point_max = p.random_point_max;
      return row;
    });
  data.flow_limit_num = flowRows.value.map((r) => r.num);
  data.flow_limit_vendors = flowRows.value.map((r) => r.vendor_id);
  data.flow_limit_game_types = flowRows.value.map((r) => r.game_type_id);
  // 圖片
  data.image_language = imageLanguage.value;
  const image: Record<number, string> = {};
  const imageId: Record<number, number | string> = {};
  const imagePc: Record<number, string> = {};
  const imagePcId: Record<number, number | string> = {};
  for (const langId of imageLanguage.value) {
    const im = ensureImg(langId);
    if (im.mobile.base64) image[langId] = im.mobile.base64;
    imageId[langId] = im.mobile.id;
    if (im.pc.base64) imagePc[langId] = im.pc.base64;
    imagePcId[langId] = im.pc.id;
  }
  data.image = image;
  data.image_id = imageId;
  data.image_pc = imagePc;
  data.image_pc_id = imagePcId;
  return data;
}

/** 彩池活動 payload */
function buildJackpotPayload(): Record<string, any> {
  // 彩池儲存不送 agent_id/agent_ids/exclude_agent_ids，編輯僅多帶 id。
  const data: Record<string, any> = {};
  if (isEdit.value) data.id = editId.value;
  data.type_id = form.type_id;
  data.start_amount = form.start_amount;
  data.title = form.title;
  data.content = form.content === '' ? ' ' : form.content;
  const langs: Array<{ content: string; language_id: number; title: string }> =
    [];
  for (const lang of languages.value) {
    const tv = form.titles[lang.id];
    if (tv) {
      langs.push({
        content: form.contents[lang.id] ?? '',
        language_id: lang.id,
        title: tv,
      });
    }
  }
  if (langs.length > 0) data.languages = langs;
  const rows = jackpotRows.value;
  data.prize_id = rows.map((r) => r.id);
  data.sort = rows.map((r) => r.sort);
  // 對齊舊版：jackpot 起訖原樣送 YYYY-MM-DD HH:mm，不補秒
  data.start_time = rows.map((r) => r.start_time);
  data.end_time = rows.map((r) => r.end_time);
  data.expire_date = rows.map((r) => r.expire_date);
  data.expire_hour = rows.map((r) => r.expire_hour);
  data.jackpot_type_id = rows.map((r) => r.jackpot_type_id);
  data.achieve_point = rows.map((r) => r.achieve_point);
  data.member_number = rows.map((r) => r.member_number);
  data.agent_number = rows.map((r) => r.agent_number);
  data.pay_number = rows.map((r) => r.pay_number);
  data.number_of_member_in_agent = rows.map((r) => r.number_of_member_in_agent);
  data.point = rows.map((r) => r.point);
  data.flow_limit_multiple = rows.map((r) => r.flow_limit_multiple);
  // 每列圖片
  data.image = rows.map((r) => r.image_id || r.image);
  // 多語獎勵名稱（step7 jackpot）
  const names: Array<{ language_id: number; name: string }> = [];
  for (const lang of languages.value) {
    const nv = jackpotNames[lang.id];
    if (nv) names.push({ language_id: lang.id, name: nv });
  }
  if (names.length > 0) data.names = names;
  // banner（jackpot_step3：banner_language + 各語系 banner 圖/圖 id）
  data.banner_language = bannerLanguage.value;
  const banner: Record<number, string> = {};
  const bannerId: Record<number, number | string> = {};
  for (const langId of bannerLanguage.value) {
    const im = ensureImg(langId);
    if (im.banner.base64) banner[langId] = im.banner.base64;
    bannerId[langId] = im.banner.id;
  }
  data.banner = banner;
  data.banner_id = bannerId;
  return data;
}

async function save() {
  // 圖片還在上傳時網址尚未回填，這時存檔會漏圖（編輯則存回舊圖）且不會有任何錯誤
  if (isUploading.value) {
    ElMessage.warning(tl('processing'));
    return;
  }
  saving.value = true;
  try {
    const res = isJackpot.value
      ? await saveEventJackpotApi(buildJackpotPayload(), isEdit.value)
      : await saveEventApi(buildPayload(), isEdit.value);
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      visible.value = false;
      emit('success');
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
    :title="`${isEdit ? tl('edit') : tl('add')}${tl('event')}`"
    align-center
    width="1200"
  >
    <div v-loading="loading" class="max-h-[74vh] overflow-y-auto pr-1">
      <ElForm label-position="top" :disabled="!canEdit">
        <ElTabs v-model="activeTab">
          <!-- Step1 活動與內容 -->
          <ElTabPane :label="tl('event_and_content')" name="step1">
            <!-- 帶入既有活動設定（target/tickets#2983）：只在建立活動顯示，編輯既有活動不顯示 -->
            <ElFormItem v-if="!isEdit" :label="tl('event_copy_from_existing')">
              <ElSelect
                v-model="sourceEventId"
                :debounce="500"
                :loading="sourceEventSearching"
                :remote-method="searchSourceEvent"
                class="w-full"
                filterable
                remote
                @change="onSourceEventChange"
              >
                <ElOption
                  v-for="ev in sourceEventOptions"
                  :key="ev.id"
                  :label="`${ev.id}｜${ev.title}｜${ev.agent_username}`"
                  :value="ev.id"
                />
              </ElSelect>
            </ElFormItem>
            <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
              <ElFormItem :label="tl('agent_account')" required>
                <ElSelect
                  v-if="isEdit"
                  v-model="form.agent_id"
                  :debounce="500"
                  :loading="agentSearching"
                  :remote-method="searchAgents"
                  class="w-full"
                  filterable
                  remote
                >
                  <ElOption
                    v-for="ag in agents"
                    :key="ag.id"
                    :label="ag.username"
                    :value="ag.id"
                  />
                </ElSelect>
                <ElSelect
                  v-else
                  v-model="form.agent_ids"
                  :debounce="500"
                  :loading="agentSearching"
                  :remote-method="searchAgents"
                  class="w-full"
                  filterable
                  multiple
                  remote
                >
                  <ElOption
                    v-for="ag in agents"
                    :key="ag.id"
                    :label="ag.username"
                    :value="ag.id"
                  />
                </ElSelect>
              </ElFormItem>
              <ElFormItem :label="tl('exclude_agent')">
                <ElSelect
                  v-model="form.exclude_agent_ids"
                  :debounce="500"
                  :disabled="isEdit"
                  :loading="excludeSearching"
                  :remote-method="searchExclude"
                  class="w-full"
                  filterable
                  multiple
                  remote
                >
                  <ElOption
                    v-for="ag in excludeAgents"
                    :key="ag.id"
                    :label="ag.username"
                    :value="ag.id"
                  />
                </ElSelect>
              </ElFormItem>
            </div>
            <ElFormItem :label="tl('event_choose_event_category')" required>
              <ElSelect
                v-model="form.type_id"
                :disabled="isEdit"
                class="w-full"
              >
                <ElOption
                  v-for="t in types"
                  :key="t.value"
                  :label="tl(`event_type.${t.parameter}`)"
                  :value="t.value"
                />
              </ElSelect>
            </ElFormItem>
            <ElFormItem v-if="isJackpot" :label="tl('start_amount')" required>
              <ElInput v-model="form.start_amount" />
            </ElFormItem>
            <ElTabs v-model="langTab" type="border-card">
              <ElTabPane :label="tl('default')" name="default">
                <ElFormItem :label="tl('event_name')" required>
                  <ElInput v-model="form.title" />
                </ElFormItem>
                <ElFormItem :label="tl('event_content')">
                  <ElInput v-model="form.content" :rows="10" type="textarea" />
                </ElFormItem>
              </ElTabPane>
              <ElTabPane
                v-for="lang in languages"
                :key="lang.id"
                :label="lang.name"
                :name="String(lang.id)"
              >
                <ElFormItem :label="tl('event_name')">
                  <ElInput v-model="form.titles[lang.id]" />
                </ElFormItem>
                <ElFormItem :label="tl('event_content')">
                  <ElInput
                    v-model="form.contents[lang.id]"
                    :rows="10"
                    type="textarea"
                  />
                </ElFormItem>
              </ElTabPane>
            </ElTabs>
          </ElTabPane>

          <!-- Step2 條件設定 -->
          <ElTabPane
            v-if="!isJackpot"
            :label="tl('event_condition_setting')"
            name="step2"
          >
            <div class="grid grid-cols-1 gap-x-4 md:grid-cols-3">
              <ElFormItem :label="tl('choose_event_category')" required>
                <ElSelect
                  :disabled="isEdit"
                  :model-value="form.action_id"
                  class="w-full"
                  @change="onActionChange"
                >
                  <ElOption
                    v-for="ac in actions"
                    :key="ac.value"
                    :label="tl(`event_action.${ac.parameter}`)"
                    :value="ac.value"
                  />
                </ElSelect>
              </ElFormItem>
              <ElFormItem :label="tl('label_start_time')" required>
                <ElDatePicker
                  v-model="form.start_time"
                  format="YYYY-MM-DD HH:mm"
                  type="datetime"
                  value-format="YYYY-MM-DD HH:mm"
                />
              </ElFormItem>
              <ElFormItem :label="tl('label_end_time')" required>
                <ElDatePicker
                  v-model="form.end_time"
                  format="YYYY-MM-DD HH:mm"
                  type="datetime"
                  value-format="YYYY-MM-DD HH:mm"
                />
              </ElFormItem>
            </div>
            <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
              <ElFormItem
                v-if="ui.showRewardPoints"
                :label="tl('event_reward_points')"
                required
              >
                <ElInput v-model="form.point" />
              </ElFormItem>
              <ElFormItem
                v-if="ui.showExpireHour"
                :label="tl('event_expire_hour')"
              >
                <ElInput v-model="form.expire_hour" />
              </ElFormItem>
            </div>
            <div class="mb-3 grid grid-cols-2 gap-2 md:grid-cols-4">
              <ElCheckbox v-model="form.ip_block">
                {{ tl('block_duplicate_ip') }}
              </ElCheckbox>
              <ElCheckbox v-model="form.phone_block">
                {{ tl('block_duplicate_phone') }}
              </ElCheckbox>
              <ElCheckbox v-model="form.account_block">
                {{ tl('block_duplicate_account') }}
              </ElCheckbox>
              <ElCheckbox
                v-if="ui.showRealnameBlock"
                v-model="form.realname_block"
              >
                {{ tl('block_duplicate_realname') }}
              </ElCheckbox>
              <ElCheckbox v-model="form.global_ip_block">
                {{ tl('event_global') }}{{ tl('block_duplicate_ip') }}
              </ElCheckbox>
              <ElCheckbox v-model="form.global_phone_block">
                {{ tl('event_global') }}{{ tl('block_duplicate_phone') }}
              </ElCheckbox>
              <ElCheckbox v-model="form.global_account_block">
                {{ tl('event_global') }}{{ tl('block_duplicate_account') }}
              </ElCheckbox>
              <ElCheckbox v-model="form.tgid_block">
                {{ tl('block_duplicate_tgid') }}
              </ElCheckbox>
            </div>
            <div class="mb-3 flex flex-wrap gap-4">
              <ElCheckbox v-if="ui.showEventMode" v-model="form.event_mode">
                {{ tl('event_mode') }}
              </ElCheckbox>
              <ElCheckbox
                v-if="ui.showRandomWeekend"
                v-model="form.prize_random_point_checkbox_input"
              >
                {{ tl('random_point') }}
              </ElCheckbox>
              <ElCheckbox
                v-if="ui.showRandomWeekend"
                v-model="form.only_weekend"
              >
                {{ tl('only_weekend') }}
              </ElCheckbox>
            </div>
            <!-- 儲值方式 -->
            <ElFormItem
              v-if="ui.showDeposit"
              :label="tl('choose_deposit_category')"
            >
              <ElCheckbox
                :model-value="form.order_type_id.includes(0)"
                @change="(v) => (form.order_type_id = v ? [0] : [])"
              >
                {{ tl('all') }}
              </ElCheckbox>
            </ElFormItem>
            <!-- 遊戲商/類型 -->
            <div
              v-if="ui.showGame"
              class="grid grid-cols-1 gap-x-4 md:grid-cols-2"
            >
              <ElFormItem :label="tl('choose_agents')" required>
                <ElSelect v-model="form.vendor_id" class="w-full" filterable>
                  <ElOption :label="tl('please_choose')" value="" />
                  <ElOption
                    v-for="v in vendors"
                    :key="v.id"
                    :label="v.name"
                    :value="v.id"
                  />
                </ElSelect>
              </ElFormItem>
              <ElFormItem :label="tl('choose_game_types')" required>
                <ElSelect v-model="form.game_type_id" class="w-full" filterable>
                  <ElOption :label="tl('please_choose')" value="" />
                  <ElOption
                    v-for="g in gameTypes"
                    :key="g.id"
                    :label="g.name"
                    :value="g.id"
                  />
                </ElSelect>
              </ElFormItem>
            </div>
            <ElFormItem
              v-if="ui.showUnlimited"
              :label="tl('bind_unlimited')"
              required
            >
              <ElRadioGroup v-model="form.bind_unlimited">
                <ElRadio value="1">{{ tl('option_yes') }}</ElRadio>
                <ElRadio value="0">{{ tl('option_no') }}</ElRadio>
              </ElRadioGroup>
            </ElFormItem>
          </ElTabPane>

          <!-- Step3 獎勵設定 -->
          <ElTabPane
            v-if="!isJackpot"
            :label="tl('event_reward_setting')"
            name="step3"
          >
            <div class="grid grid-cols-1 gap-x-4 md:grid-cols-3">
              <ElFormItem :label="tl('reward_method')" required>
                <ElSelect v-model="form.prize_type_id" class="w-full">
                  <ElOption
                    v-for="p in prizeTypes"
                    :key="p.id"
                    :label="p.name"
                    :value="p.id"
                  />
                </ElSelect>
              </ElFormItem>
              <ElFormItem :label="tl('reward_name')" required>
                <ElInput v-model="form.prize_name" />
              </ElFormItem>
              <ElFormItem :label="tl('max_bonus_point')">
                <ElInput v-model="form.max_point" />
              </ElFormItem>
              <ElFormItem :label="tl('available_multiple_get')" required>
                <ElRadioGroup
                  v-model="form.prize_is_repeatedly"
                  :disabled="ui.repeatLocked"
                >
                  <ElRadio value="1">
                    {{ tl('available_multiple_can') }}
                  </ElRadio>
                  <ElRadio value="0">
                    {{ tl('available_not_multiple') }}
                  </ElRadio>
                  <ElRadio v-if="form.prize_is_repeatedly === '2'" value="2">
                    {{ tl('available_multiple_limited') }}
                  </ElRadio>
                </ElRadioGroup>
              </ElFormItem>
              <ElFormItem :label="tl('event_deadline')" required>
                <ElDatePicker
                  v-model="form.prize_expire_date"
                  format="YYYY-MM-DD HH:mm"
                  type="datetime"
                  value-format="YYYY-MM-DD HH:mm"
                />
              </ElFormItem>
              <ElFormItem :label="tl('event_draw_date')" required>
                <ElDatePicker
                  v-model="form.prize_draw_date"
                  format="YYYY-MM-DD HH:mm"
                  type="datetime"
                  value-format="YYYY-MM-DD HH:mm"
                />
              </ElFormItem>
              <ElFormItem
                v-if="ui.showAchievePayout"
                :label="tl('achieve_payout_people')"
              >
                <ElInput v-model="form.achieve_payout_people" />
              </ElFormItem>
              <ElFormItem v-if="ui.showTimesADay" :label="tl('times_a_day')">
                <ElInput v-model="form.times_a_day" />
              </ElFormItem>
            </div>
            <div
              v-if="ui.showWithdrawalCancel"
              class="grid grid-cols-1 gap-x-4 md:grid-cols-2"
            >
              <ElFormItem :label="tl('cancel_deposit_reward')" required>
                <ElRadioGroup v-model="form.prize_withdrawal_cancel_reward">
                  <ElRadio value="1">{{ tl('option_yes') }}</ElRadio>
                  <ElRadio value="0">{{ tl('option_no') }}</ElRadio>
                </ElRadioGroup>
              </ElFormItem>
              <ElFormItem :label="tl('cancel_same_order_reward')" required>
                <ElRadioGroup v-model="form.prize_cancel_other_reward">
                  <ElRadio value="1">{{ tl('option_yes') }}</ElRadio>
                  <ElRadio value="0">{{ tl('option_no') }}</ElRadio>
                </ElRadioGroup>
              </ElFormItem>
            </div>
          </ElTabPane>

          <!-- Step4 獎勵內容 -->
          <ElTabPane
            v-if="!isJackpot"
            :label="tl('event_reward_content_setting')"
            name="step4"
          >
            <div
              v-if="canEdit || prizeRows.length > 0"
              class="text-muted-foreground mb-1 hidden gap-2 text-xs font-medium md:grid md:grid-cols-11"
            >
              <span>{{ tl('num') }}</span>
              <span class="md:col-span-2"
                ><span class="text-red-500">*</span>
                {{ tl('reward_name') }}</span
              >
              <span class="md:col-span-2"
                ><span class="text-red-500">*</span>
                {{ tl('reward_type') }}</span
              >
              <span class="md:col-span-2"
                ><span class="text-red-500">*</span>
                {{ tl('reward_point') }}</span
              >
              <span
                ><span class="text-red-500">*</span>
                {{ tl('win_chance') }}</span
              >
              <span
                ><span class="text-red-500">*</span>
                {{ tl('reward_item_count') }}</span
              >
              <span
                ><span class="text-red-500">*</span>
                {{ tl('event_flow_limit_multiple') }}</span
              >
              <span></span>
            </div>
            <div
              v-if="canEdit"
              class="mb-3 grid grid-cols-2 items-center gap-2 md:grid-cols-11"
            >
              <ElInput
                :model-value="prizeNumSeq"
                :placeholder="tl('num')"
                disabled
              />
              <ElInput
                v-model="addPrize.name"
                :placeholder="tl('reward_name')"
                class="md:col-span-2"
              />
              <ElSelect
                v-model="addPrize.point_type"
                :placeholder="tl('reward_type')"
                class="md:col-span-2"
              >
                <ElOption :label="tl('platform_point')" value="1" />
                <ElOption :label="tl('rolling_chip')" value="2" />
                <ElOption :label="tl('entity_reward')" value="3" />
                <ElOption :label="tl('promotion_wallet')" value="4" />
              </ElSelect>
              <ElInput
                v-model="addPrize.point"
                :placeholder="tl('reward_point')"
                class="md:col-span-2"
              >
                <template #prefix>
                  <span
                    class="cursor-pointer select-none"
                    :class="
                      Number(addPrize.type) === 0
                        ? 'text-primary font-bold'
                        : 'text-muted-foreground'
                    "
                    @click="addPrize.type = 0"
                    >$</span
                  >
                </template>
                <template #suffix>
                  <span
                    class="cursor-pointer select-none"
                    :class="
                      Number(addPrize.type) === 1
                        ? 'text-primary font-bold'
                        : 'text-muted-foreground'
                    "
                    @click="addPrize.type = 1"
                    >%</span
                  >
                </template>
              </ElInput>
              <ElInput
                v-model="addPrize.percentage"
                :placeholder="tl('win_chance')"
              />
              <ElInput
                v-model="addPrize.number"
                :placeholder="tl('reward_item_count')"
              />
              <ElInput
                v-model="addPrize.flow_limit_multiple"
                :placeholder="tl('event_flow_limit_multiple')"
              />
              <ElButton circle size="small" type="primary" @click="addPrizeRow">
                +
              </ElButton>
            </div>
            <div
              v-for="(p, i) in prizeRows"
              :key="i"
              class="mb-2 grid grid-cols-2 items-center gap-2 md:grid-cols-11"
            >
              <ElInput :model-value="p.num" disabled />
              <ElInput
                v-model="p.name"
                :disabled="Number(p.id) > 0"
                class="md:col-span-2"
              />
              <ElSelect
                v-model="p.point_type"
                :disabled="Number(p.id) > 0"
                class="md:col-span-2"
              >
                <ElOption :label="tl('platform_point')" value="1" />
                <ElOption :label="tl('rolling_chip')" value="2" />
                <ElOption :label="tl('entity_reward')" value="3" />
                <ElOption :label="tl('promotion_wallet')" value="4" />
              </ElSelect>
              <ElInput
                v-model="p.point"
                :disabled="Number(p.id) > 0"
                class="md:col-span-2"
              >
                <template #prefix>
                  <span
                    :class="[
                      Number(p.id) > 0 ? '' : 'cursor-pointer select-none',
                      Number(p.type) === 0
                        ? 'text-primary font-bold'
                        : 'text-muted-foreground',
                    ]"
                    @click="setPrizeType(p, 0)"
                    >$</span
                  >
                </template>
                <template #suffix>
                  <span
                    :class="[
                      Number(p.id) > 0 ? '' : 'cursor-pointer select-none',
                      Number(p.type) === 1
                        ? 'text-primary font-bold'
                        : 'text-muted-foreground',
                    ]"
                    @click="setPrizeType(p, 1)"
                    >%</span
                  >
                </template>
              </ElInput>
              <ElInput v-model="p.percentage" :disabled="Number(p.id) > 0" />
              <ElInput v-model="p.number" :disabled="Number(p.id) > 0" />
              <ElInput
                v-model="p.flow_limit_multiple"
                :disabled="Number(p.id) > 0"
              />
              <ElButton
                v-if="canEdit"
                circle
                size="small"
                type="danger"
                @click="removePrizeRow(i)"
              >
                -
              </ElButton>
            </div>
          </ElTabPane>

          <!-- Step5 流水限制 -->
          <ElTabPane v-if="!isJackpot" :label="tl('flow_limit')" name="step5">
            <div
              v-if="ui.showFlowPrincipal"
              class="mb-3 flex flex-wrap items-center gap-x-8 gap-y-2"
            >
              <ElFormItem
                :label="`${tl('event_flow_limit_multiple')}${tl('event_flow_limit_include_principal')}`"
              >
                <ElRadioGroup v-model="form.prize_flow_limit_include_principal">
                  <ElRadio value="0">{{ tl('option_no') }}</ElRadio>
                  <ElRadio value="1">{{ tl('option_yes') }}</ElRadio>
                </ElRadioGroup>
              </ElFormItem>
              <ElFormItem :label="tl('deduct_principal')">
                <ElRadioGroup v-model="form.prize_deduct_principal">
                  <ElRadio value="0">{{ tl('option_no') }}</ElRadio>
                  <ElRadio value="1">{{ tl('option_yes') }}</ElRadio>
                </ElRadioGroup>
              </ElFormItem>
            </div>
            <div v-if="canEdit" class="mb-3 flex flex-wrap items-center gap-2">
              <span>{{ tl('id') }}</span>
              <ElInput v-model="addFlow.num" class="!w-24" />
              <span>{{ tl('vendor_announcement') }}</span>
              <ElSelect v-model="addFlow.vendor_id" class="!w-48" filterable>
                <ElOption :label="tl('unlimited')" :value="0" />
                <ElOption
                  v-for="v in vendors"
                  :key="v.id"
                  :label="v.name"
                  :value="v.id"
                />
              </ElSelect>
              <span>{{ tl('game_type') }}</span>
              <ElSelect v-model="addFlow.game_type_id" class="!w-48" filterable>
                <ElOption :label="tl('unlimited')" :value="0" />
                <ElOption
                  v-for="g in gameTypes"
                  :key="g.id"
                  :label="g.name"
                  :value="g.id"
                />
              </ElSelect>
              <ElButton circle size="small" type="primary" @click="addFlowRow">
                +
              </ElButton>
            </div>
            <div
              v-for="(r, i) in flowRows"
              :key="i"
              class="mb-2 flex items-center gap-4"
            >
              <span>{{ tl('id') }}: {{ r.num }}</span>
              <span>{{ tl('vendor_announcement') }}: {{ r.vendor_name }}</span>
              <span>{{ tl('game_type') }}: {{ r.game_type_name }}</span>
              <ElButton
                v-if="canEdit"
                circle
                size="small"
                type="danger"
                @click="removeFlowRow(i)"
              >
                -
              </ElButton>
            </div>
          </ElTabPane>

          <!-- Step6 新增圖片 -->
          <ElTabPane v-if="!isJackpot" :label="tl('add_image')" name="step6">
            <ElFormItem
              :label="`${tl('choose_language')}${tl('default_english')}`"
            >
              <ElSelect v-model="imageLanguage" class="w-full" multiple>
                <ElOption
                  v-for="lang in languages"
                  :key="lang.id"
                  :label="lang.name"
                  :value="lang.id"
                />
              </ElSelect>
            </ElFormItem>
            <div
              v-for="langId in imageLanguage"
              :key="langId"
              class="grid grid-cols-1 items-center gap-4 border-t py-3 md:grid-cols-3"
            >
              <div class="font-bold">
                {{ languages.find((l) => l.id === langId)?.name ?? langId }}
              </div>
              <div class="text-center">
                <div class="mb-1 text-xs">
                  {{ tl('choose_mobile_ad_picture') }}
                </div>
                <img
                  v-if="images[langId]?.mobile.preview"
                  :src="images[langId]?.mobile.preview"
                  class="mx-auto max-h-[140px] max-w-[140px]"
                />
                <div class="flex justify-center gap-2">
                  <ElButton
                    :loading="uploading[`${langId}:mobile`]"
                    size="small"
                    @click="uploadImage('mobile', langId)"
                  >
                    {{ tl('upload_image') }}
                  </ElButton>
                  <ElButton
                    :disabled="uploading[`${langId}:mobile`]"
                    size="small"
                    @click="pickImage('mobile', langId)"
                  >
                    {{ tl('choose_file') }}
                  </ElButton>
                </div>
                <div class="text-muted-foreground mt-1 text-xs">
                  {{ imageUploadText('image_upload_hint') }}
                </div>
              </div>
              <div class="text-center">
                <div class="mb-1 text-xs">{{ tl('choose_pc_ad_picture') }}</div>
                <img
                  v-if="images[langId]?.pc.preview"
                  :src="images[langId]?.pc.preview"
                  class="mx-auto max-h-[140px] max-w-[140px]"
                />
                <div class="flex justify-center gap-2">
                  <ElButton
                    :loading="uploading[`${langId}:pc`]"
                    size="small"
                    @click="uploadImage('pc', langId)"
                  >
                    {{ tl('upload_image') }}
                  </ElButton>
                  <ElButton
                    :disabled="uploading[`${langId}:pc`]"
                    size="small"
                    @click="pickImage('pc', langId)"
                  >
                    {{ tl('choose_file') }}
                  </ElButton>
                </div>
                <div class="text-muted-foreground mt-1 text-xs">
                  {{ imageUploadText('image_upload_hint') }}
                </div>
              </div>
            </div>
          </ElTabPane>

          <!-- Step7 獎勵名稱多語系 -->
          <ElTabPane v-if="!isJackpot" :label="tl('reward_name')" name="step7">
            <ElCard
              v-for="(p, i) in prizeRows"
              :key="i"
              class="mb-3"
              shadow="never"
            >
              <template #header>{{ p.name }}</template>
              <ElFormItem
                v-for="lang in languages"
                :key="lang.id"
                :label="lang.name"
              >
                <ElInput v-model="p.names[lang.id]" />
              </ElFormItem>
            </ElCard>
          </ElTabPane>

          <!-- 彩池獎項層級（type_id==3） -->
          <ElTabPane
            v-if="isJackpot"
            :label="tl('event_reward_setting')"
            name="jackpot"
          >
            <div class="mb-2">
              <ElButton type="primary" @click="addJackpotRow">
                {{ tl('add') }}
              </ElButton>
            </div>
            <ElTable :data="jackpotRows" border size="small" stripe>
              <ElTableColumn :label="tl('field_sort')" min-width="90">
                <template #default="{ row }">
                  <ElInput v-model="row.sort" />
                </template>
              </ElTableColumn>
              <ElTableColumn :label="tl('label_start_time')" min-width="190">
                <template #default="{ row }">
                  <ElDatePicker
                    v-model="row.start_time"
                    class="!w-full"
                    format="YYYY-MM-DD HH:mm"
                    type="datetime"
                    value-format="YYYY-MM-DD HH:mm"
                  />
                </template>
              </ElTableColumn>
              <ElTableColumn :label="tl('label_end_time')" min-width="190">
                <template #default="{ row }">
                  <ElDatePicker
                    v-model="row.end_time"
                    class="!w-full"
                    format="YYYY-MM-DD HH:mm"
                    type="datetime"
                    value-format="YYYY-MM-DD HH:mm"
                  />
                </template>
              </ElTableColumn>
              <ElTableColumn :label="tl('event_expire_hour')" min-width="110">
                <template #default="{ row }">
                  <ElInput v-model="row.expire_hour" />
                </template>
              </ElTableColumn>
              <ElTableColumn
                :label="tl('event_condition_setting')"
                min-width="120"
              >
                <template #default="{ row }">
                  <ElSelect v-model="row.jackpot_type_id" class="w-full">
                    <ElOption :label="tl('flow')" :value="1" />
                    <ElOption :label="tl('lose_point')" :value="2" />
                  </ElSelect>
                </template>
              </ElTableColumn>
              <ElTableColumn :label="tl('event_reward_points')" min-width="110">
                <template #default="{ row }">
                  <ElInput v-model="row.achieve_point" />
                </template>
              </ElTableColumn>
              <ElTableColumn
                :label="tl('conform_member_count')"
                min-width="110"
              >
                <template #default="{ row }">
                  <ElInput v-model="row.member_number" />
                </template>
              </ElTableColumn>
              <ElTableColumn :label="tl('conform_agent_count')" min-width="110">
                <template #default="{ row }">
                  <ElInput v-model="row.agent_number" />
                </template>
              </ElTableColumn>
              <ElTableColumn :label="tl('pay_reward_count')" min-width="110">
                <template #default="{ row }">
                  <ElInput v-model="row.pay_number" />
                </template>
              </ElTableColumn>
              <ElTableColumn
                :label="tl('can_get_reward_from_agent_count')"
                min-width="130"
              >
                <template #default="{ row }">
                  <ElInput v-model="row.number_of_member_in_agent" />
                </template>
              </ElTableColumn>
              <ElTableColumn
                :label="`${tl('reward')}${tl('amount')}`"
                min-width="110"
              >
                <template #default="{ row }">
                  <ElInput v-model="row.point" />
                </template>
              </ElTableColumn>
              <ElTableColumn
                :label="tl('event_flow_limit_multiple')"
                min-width="120"
              >
                <template #default="{ row }">
                  <ElInput v-model="row.flow_limit_multiple" />
                </template>
              </ElTableColumn>
              <ElTableColumn :label="tl('picture')" min-width="120">
                <template #default="{ row, $index }">
                  <img
                    v-if="row.image"
                    :src="row.image"
                    class="mb-1 max-h-[50px] max-w-[50px]"
                  />
                  <ElButton size="small" @click="pickJackpotImage($index)">
                    {{ tl('choose_file') }}
                  </ElButton>
                </template>
              </ElTableColumn>
              <ElTableColumn :label="tl('action')" align="center" width="80">
                <template #default="{ $index }">
                  <ElButton
                    link
                    type="danger"
                    @click="removeJackpotRow($index)"
                  >
                    {{ tl('delete') }}
                  </ElButton>
                </template>
              </ElTableColumn>
              <template #empty>{{ tl('no_record') }}</template>
            </ElTable>
          </ElTabPane>

          <!-- 彩池獎勵名稱（多語，type_id==3） -->
          <ElTabPane
            v-if="isJackpot"
            :label="tl('reward_name')"
            name="jackpot_names"
          >
            <ElFormItem
              v-for="lang in languages"
              :key="lang.id"
              :label="lang.name"
            >
              <ElInput v-model="jackpotNames[lang.id]" />
            </ElFormItem>
          </ElTabPane>

          <!-- banner 圖片（jackpot_step3，type_id==3） -->
          <ElTabPane
            v-if="isJackpot"
            :label="tl('add_image')"
            name="jackpot_banner"
          >
            <ElFormItem
              :label="`${tl('choose_language')}${tl('default_english')}`"
            >
              <ElSelect v-model="bannerLanguage" class="w-full" multiple>
                <ElOption
                  v-for="lang in languages"
                  :key="lang.id"
                  :label="lang.name"
                  :value="lang.id"
                />
              </ElSelect>
            </ElFormItem>
            <div
              v-for="langId in bannerLanguage"
              :key="langId"
              class="grid grid-cols-1 items-center gap-4 border-t py-3 md:grid-cols-3"
            >
              <div class="font-bold">
                {{ languages.find((l) => l.id === langId)?.name ?? langId }}
              </div>
              <div class="text-center md:col-span-2">
                <div class="mb-1 text-xs">banner</div>
                <img
                  v-if="images[langId]?.banner.preview"
                  :src="images[langId]?.banner.preview"
                  class="mx-auto max-h-[300px] max-w-[300px]"
                />
                <div class="flex justify-center gap-2">
                  <ElButton
                    :loading="uploading[`${langId}:banner`]"
                    size="small"
                    @click="uploadImage('banner', langId)"
                  >
                    {{ tl('upload_image') }}
                  </ElButton>
                  <ElButton
                    :disabled="uploading[`${langId}:banner`]"
                    size="small"
                    @click="pickImage('banner', langId)"
                  >
                    {{ tl('choose_file') }}
                  </ElButton>
                </div>
                <div class="text-muted-foreground mt-1 text-xs">
                  {{ imageUploadText('image_upload_hint') }}
                </div>
              </div>
            </div>
          </ElTabPane>
        </ElTabs>
      </ElForm>
    </div>
    <template #footer>
      <ElButton @click="visible = false">{{ tl('cancel') }}</ElButton>
      <ElButton v-if="canEdit" :loading="saving" type="success" @click="save">
        {{ tl('btn_update') }}
      </ElButton>
    </template>

    <ImageListDialog ref="picker" @select="onImageSelected" />
  </ElDialog>
</template>
