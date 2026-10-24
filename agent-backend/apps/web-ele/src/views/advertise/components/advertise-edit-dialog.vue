<script lang="ts" setup>
import { computed, reactive, ref } from 'vue';

import { useUserStore } from '@vben/stores';

import dayjs from 'dayjs';
import {
  ElButton,
  ElCard,
  ElDatePicker,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElInputNumber,
  ElMessage,
  ElOption,
  ElRadioButton,
  ElRadioGroup,
  ElSelect,
  ElTabPane,
  ElTabs,
} from 'element-plus';

import {
  getAdvertiseGameListApi,
  getAdvertiseInternalTypesApi,
  getAdvertisementPathListApi,
  getAdvertiseViewApi,
  getAgentDropdownListApi,
  getAgentViewByIdApi,
  getGameViewApi,
  getGeneralAgentDropdownApi,
  getLanguagesApi,
  getVendorListApi,
  saveAdvertiseApi,
  uploadAdvertiseImageApi,
} from '#/api';
import ImageListDialog from '#/components/image-list-dialog.vue';
import { tl } from '#/locales';
import {
  IMAGE_ACCEPT,
  ImageUploadError,
  imageUploadText,
  readImageAsDataUrl,
} from '#/utils/image-upload';

const emit = defineEmits<{ success: [] }>();

const visible = ref(false);
const editId = ref<number | string>('');
const isEdit = computed(() => Boolean(editId.value));

const activeStep = ref('step1');
const loading = ref(false);
const submitting = ref(false);

// 下拉資料
const languages = ref<Array<{ id: number | string; name: string }>>([]);
const paths = ref<Array<{ id: number | string; name: string }>>([]);
const agents = ref<Array<{ id: number | string; username: string }>>([]);
/** 總代基礎清單（開窗即載，作為未輸入關鍵字時的預設選項） */
const generalAgents = ref<Array<{ id: number | string; username: string }>>([]);
/** 出現過的所有代理選項（id → 選項），供已選項保 label 與編輯回填 */
const agentCache = new Map<string, { id: number | string; username: string }>();
const agentSearching = ref(false);
const games = ref<
  Array<{ id: number | string; name: string; vendor_id: number | string }>
>([]);
const vendors = ref<Array<{ id: number; name: string }>>([]);
const internalTypes = ref<Array<{ parameter: string }>>([]);
// 下拉資料每筆紀錄共用，載一次即可
let dropdownsLoaded = false;

const form = reactive<Record<string, any>>(makeDefaults());

function makeDefaults() {
  return {
    agent_id: '' as number | string,
    agents: [] as Array<number | string>,
    end_date: dayjs().add(1, 'month').format('YYYY-MM-DD 23:59'),
    exclude_agent_ids: [] as Array<number | string>,
    external_url: {} as Record<string, string>,
    game_id: '' as number | string,
    internal_type: '' as number | string,
    language: [1] as Array<number | string>,
    open_window: 0,
    path_id: '' as number | string,
    route_url: '',
    sort: 0,
    start_date: dayjs().format('YYYY-MM-DD 00:00'),
    status: 1,
    type: 0 as number | string,
    vendor_id: '' as number | string,
  };
}

/** 每語系圖片：{ [lang]: { image, image_id, image_pc, image_pc_id } } */
const images = reactive<
  Record<
    string,
    { image: string; image_id: string; image_pc: string; image_pc_id: string }
  >
>({});

function ensureImageSlot(lang: number | string) {
  if (!images[lang])
    images[lang] = { image: '', image_id: '', image_pc: '', image_pc_id: '' };
  return images[lang];
}

function resetForm() {
  Object.assign(form, makeDefaults());
  for (const k of Object.keys(images)) delete images[k];
  // 上傳鎖也一併清掉，否則關窗重開會殘留鎖定而擋住存檔
  for (const k of Object.keys(uploading)) delete uploading[k];
  gameVendorId.value = '';
  games.value = [];
  activeStep.value = 'step1';
}

/**
 * 連結至遊戲的選擇（target/tickets#4167）：先選遊戲商 → 遠端搜尋該商遊戲。
 * 原本開彈窗即載入全站遊戲（per_page 10,000、約 3MB）並於 type=2 時
 * 一次渲染上萬個選項，主執行緒凍結、分頁被瀏覽器終止。
 */
const gameVendorId = ref<number | string>('');
const gameSearching = ref(false);
async function remoteSearchGames(query: string) {
  if (!gameVendorId.value) return;
  gameSearching.value = true;
  try {
    games.value =
      (await getAdvertiseGameListApi({
        name: query || undefined,
        vendor_id: gameVendorId.value,
      }).catch(() => [])) ?? [];
  } finally {
    gameSearching.value = false;
  }
}
function onGameVendorChange() {
  form.game_id = '';
  games.value = [];
  // 先給前 50 筆當初始清單，再由使用者打字縮小
  remoteSearchGames('');
}
/** 編輯回填：以 game_id 反查名稱與所屬遊戲商，塞入下拉當前選項 */
async function seedSelectedGame(gameId: number | string) {
  if (!gameId) return;
  try {
    const res = await getGameViewApi(gameId);
    const g = (res as any)?.data;
    if (g?.id) {
      gameVendorId.value = g.vendor_id ?? '';
      games.value = [{ id: g.id, name: g.name, vendor_id: g.vendor_id }];
    }
  } catch {
    // 反查失敗僅影響名稱顯示，重新選擇即可
  }
}

// 圖庫挑選（共用組件，target 帶「slot:lang」）
const picker = ref<InstanceType<typeof ImageListDialog>>();

const userStore = useUserStore();
/** 上傳一律掛在登入者的 host 代理：新增模式的代理欄是多選且此時可能還沒選 */
const hostId = computed(() =>
  Number((userStore.userInfo as any)?.host_id ?? 0),
);
/** 上傳中的圖片欄位（`語系:欄位`）；網址還沒回來就存檔會漏圖，故存檔時擋住 */
const uploading = reactive<Record<string, boolean>>({});
const isUploading = computed(() => Object.keys(uploading).length > 0);

function openPicker(lang: number | string, slot: 'image' | 'image_pc') {
  picker.value?.open(`${slot}:${lang}`);
}
function onImageSelected(p: {
  id: number | string;
  target: string;
  url: string;
}) {
  const [slot, lang = ''] = p.target.split(':');
  const s = ensureImageSlot(lang);
  if (slot === 'image') {
    s.image = p.url;
    s.image_id = String(p.id);
  } else {
    s.image_pc = p.url;
    s.image_pc_id = String(p.id);
  }
}

/**
 * 從電腦桌面上傳：選檔後立刻上傳進圖庫，只留下圖片位址與 id（target/tickets#4291）。
 * 原本只把 base64 塞進畫面預覽、等存檔才由後端寫進圖庫，剛上傳的圖在存檔前
 * 於圖庫彈窗看不到；且語系一多，base64 一次送 payload 就會大到 timeout。
 */
function uploadFile(lang: number | string, slot: 'image' | 'image_pc') {
  const key = `${lang}:${slot}`;
  if (uploading[key]) return;
  const idKey = slot === 'image' ? 'image_id' : 'image_pc_id';
  const input = document.createElement('input');
  input.type = 'file';
  input.accept = IMAGE_ACCEPT;
  input.addEventListener('change', async () => {
    const file = input.files?.[0];
    if (!file) return;
    uploading[key] = true;
    let base64: string;
    try {
      base64 = await readImageAsDataUrl(file);
    } catch (error) {
      // 讀檔失敗也要解鎖，否則這個欄位會被永久鎖住
      delete uploading[key];
      ElMessage.error(
        error instanceof ImageUploadError ? error.message : tl('failure'),
      );
      return;
    }
    const s = ensureImageSlot(lang);
    // 先以 base64 佔位，讓使用者在上傳完成前就看到選到的圖
    s[slot] = base64;
    s[idKey] = '';
    try {
      const res = await uploadAdvertiseImageApi({
        agent_id: hostId.value,
        base64Image: base64,
      }).catch(() => null);
      if (!res || Number(res.code) !== 1000) {
        s[slot] = '';
        s[idKey] = '';
        ElMessage.error(res?.message || tl('failure'));
        return;
      }
      const url = res.data?.path ?? '';
      const imageId = Number(res.data?.advertise_image_id ?? 0);
      // 後端只在 image_id 為 0 時才把 image 當 base64 解，所以沒拿到 id 就得留
      // base64 走舊路徑；拿到 id 但沒拿到 path 也留 base64，預覽才不會空白
      s[slot] = imageId > 0 && url ? url : base64;
      s[idKey] = imageId > 0 ? String(imageId) : '';
    } finally {
      delete uploading[key];
    }
  });
  input.click();
}

async function loadDropdowns() {
  if (dropdownsLoaded) return;
  // 遊戲清單不在此載入（target/tickets#4167）：僅 type=2 用得到，改由選定遊戲商後遠端搜尋
  const [lg, pth, vd, it] = await Promise.all([
    getLanguagesApi().catch(() => []),
    getAdvertisementPathListApi().catch(() => ({ data: [] }) as any),
    getVendorListApi().catch(() => []),
    getAdvertiseInternalTypesApi().catch(() => []),
  ]);
  languages.value = (lg as any) ?? [];
  paths.value = (pth?.data ?? pth ?? []) as any;
  vendors.value = (vd as any) ?? [];
  internalTypes.value = (it as any) ?? [];
  dropdownsLoaded = true;
}

/**
 * 代理下拉改「總代起手＋打字遠端搜尋」（target/tickets#4191）。
 * 原本以 dropdownList 全量為主，大站一次回數萬筆（TPower 4.4 萬、2.2MB），
 * 兩個下拉各渲染上萬個選項直接凍死彈窗；舊後台同頁即為總代清單＋動態查詢。
 */
async function loadAgents() {
  const ga = await getGeneralAgentDropdownApi().catch(() => []);
  generalAgents.value = (ga as any) ?? [];
  setAgentOptions(generalAgents.value);
}

/** 顯示中的選項 = 本次清單 ＋ 已選但不在清單內的（從 cache 補，避免已選項掉 label） */
function setAgentOptions(
  list: Array<{ id: number | string; username: string }>,
) {
  for (const o of list) agentCache.set(String(o.id), o);
  const ids = [...form.agents, ...form.exclude_agent_ids];
  // 編輯模式的所屬代理（target/tickets#4436）：搜尋結果不含目前代理時仍要保住名稱
  if (form.agent_id !== '' && Number(form.agent_id) !== 0)
    ids.push(form.agent_id);
  const selected = new Set(ids.map(String));
  const inList = new Set(list.map((o) => String(o.id)));
  const keep = [...selected]
    .filter((id) => !inList.has(id))
    .map((id) => agentCache.get(id))
    .filter(Boolean) as Array<{ id: number | string; username: string }>;
  agents.value = [...keep, ...list];
}

async function remoteSearchAgents(query: string) {
  const q = (query ?? '').trim();
  if (!q) {
    setAgentOptions(generalAgents.value);
    return;
  }
  agentSearching.value = true;
  try {
    const res = await getAgentDropdownListApi({ username: q }).catch(() => []);
    setAgentOptions((res as any) ?? []);
  } finally {
    agentSearching.value = false;
  }
}

/**
 * 編輯回填所屬代理（target/tickets#4436）：view 回應只有 agent_id 沒有帳號，
 * 比照舊後台反查 agent/view 帶出帳號，塞進 cache 讓單選欄顯示名稱而非 id
 */
async function seedSelectedAgent(agentId: number | string) {
  if (!agentId || Number(agentId) === 0) return;
  try {
    const a = await getAgentViewByIdApi(Number(agentId));
    if (a?.username) {
      agentCache.set(String(agentId), { id: agentId, username: a.username });
      return;
    }
  } catch {
    // 反查失敗走下方退路
  }
  // 代理已刪或查無帳號：label 退回顯示 id，欄位仍預選原值、可改選
  agentCache.set(String(agentId), { id: agentId, username: String(agentId) });
}

async function loadEdit() {
  // loading 由 open() 統一控管（並行載入下此處自關會提早收掉轉圈）
  try {
    const res = await getAdvertiseViewApi(editId.value);
    if (Number(res.code) === 1000 && res.data) {
      const d = res.data;
      form.agent_id = d.agent_id ?? '';
      await seedSelectedAgent(form.agent_id);
      form.path_id = d.advertisement_path_id ?? '';
      form.start_date = (d.start_time ?? '').slice(0, 16) || form.start_date;
      form.end_date = (d.end_time ?? '').slice(0, 16) || form.end_date;
      form.status = Number(d.status) === 1 ? 1 : 0;
      form.sort = Number(d.sort ?? 0);
      form.type = d.type ?? 0;
      form.open_window = Number(d.open_window) === 1 ? 1 : 0;
      form.exclude_agent_ids = (d.exclude_agents ?? []).map(
        (a: any) => a.agent_id,
      );
      // 回填已排除代理的選項（target/tickets#4191）：下拉不再全量載入，
      // view 回應已帶 username，塞進 cache 讓已選項顯示名稱而非 id
      for (const a of d.exclude_agents ?? []) {
        if (a?.agent_id !== undefined && a?.agent_id !== null && a?.username) {
          agentCache.set(String(a.agent_id), {
            id: a.agent_id,
            username: a.username,
          });
        }
      }
      setAgentOptions(generalAgents.value);
      form.language = (d.detail ?? []).map((x: any) => x.language_id);
      if (Number(d.type) === 2) {
        form.game_id = d.content;
        // 回填已選遊戲（target/tickets#4167）：反查單筆帶出名稱與所屬遊戲商，
        // 下拉不再載全站清單，需自行塞入當前選項才看得到名稱
        void seedSelectedGame(d.content);
      }
      if (Number(d.type) === 3) form.vendor_id = d.content;
      if (Number(d.type) === 5) form.internal_type = d.content;
      if (Number(d.type) === 4) {
        try {
          form.external_url = JSON.parse(d.content ?? '{}');
        } catch {
          form.external_url = {};
        }
      }
      for (const dt of d.detail ?? []) {
        images[dt.language_id] = {
          image: dt.advertise_image ?? '',
          image_id: dt.advertise_image_id ?? '',
          image_pc: dt.advertise_image_pc ?? '',
          image_pc_id: dt.advertise_image_pc_id ?? '',
        };
      }
    }
  } catch {
    // 讀取失敗維持空表單，使用者可關閉重試
  }
}

function buildPayload() {
  const payload: Record<string, any> = {
    end_time: `${form.end_date}:00`,
    exclude_agent_ids: form.exclude_agent_ids,
    language_id: form.language,
    path_id: form.path_id,
    sort: form.sort,
    start_time: `${form.start_date}:00`,
    status: form.status,
    type: form.type,
  };
  if (isEdit.value) payload.agent_id = form.agent_id;
  else payload.agents = form.agents;
  // 多型 content
  const t = Number(form.type);
  switch (t) {
    case 2: {
      payload.contents = form.game_id;
      break;
    }
    case 3: {
      payload.contents = form.vendor_id;
      break;
    }
    case 4: {
      const urls: Record<string, string> = { ...form.external_url };
      if (!urls[1]) urls[1] = '';
      payload.contents = JSON.stringify(urls);
      payload.open_window = form.open_window;
      break;
    }
    case 5: {
      payload.contents =
        form.internal_type === 'route' ? form.route_url : form.internal_type;
      break;
    }
    default: {
      payload.contents = '';
    }
  }
  // 每語系圖片
  const image: Record<string, string> = {};
  const imageId: Record<string, string> = {};
  const imagePc: Record<string, string> = {};
  const imagePcId: Record<string, string> = {};
  for (const lang of form.language) {
    const s = images[lang];
    if (s?.image) image[lang] = s.image;
    imageId[lang] = s?.image_id ?? '';
    if (s?.image_pc) imagePc[lang] = s.image_pc;
    imagePcId[lang] = s?.image_pc_id ?? '';
  }
  payload.image = image;
  payload.image_id = imageId;
  payload.image_pc = imagePc;
  payload.image_pc_id = imagePcId;
  if (isEdit.value) payload.id = editId.value;
  return payload;
}

async function submit() {
  // 圖片還在上傳時網址尚未回填，這時存檔會漏圖且不會有任何錯誤
  if (isUploading.value) {
    ElMessage.warning(tl('image_uploading_wait'));
    return;
  }
  submitting.value = true;
  try {
    const res = await saveAdvertiseApi(buildPayload(), isEdit.value);
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      visible.value = false;
      emit('success');
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    submitting.value = false;
  }
}

const selectedLangs = computed(() =>
  languages.value.filter((l) => form.language.includes(l.id)),
);

/** content 不在參數清單裡代表存的是自訂站內路徑，還原成 route + 路徑值 */
function normalizeInternalType() {
  if (Number(form.type) !== 5 || form.internal_type === '') return;
  const known = internalTypes.value.some(
    (it) => String(it.parameter) === String(form.internal_type),
  );
  if (known) return;
  form.route_url = String(form.internal_type);
  form.internal_type = 'route';
}

async function open(id: number | string = '') {
  editId.value = id || '';
  resetForm();
  visible.value = true;
  loading.value = true;
  try {
    // 三段互不相依，並行載入（target/tickets#4167）：原本串行總延遲相加
    await Promise.all([
      loadDropdowns(),
      // 新增需要可選代理、編輯需要排除代理選單，兩種模式都載
      loadAgents(),
      isEdit.value ? loadEdit() : Promise.resolve(),
    ]);
    normalizeInternalType();
  } finally {
    loading.value = false;
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="`${isEdit ? tl('edit') : tl('add')} - ${tl('menu_advertisement')}`"
    top="5vh"
    width="900"
  >
    <ElCard
      v-loading="loading"
      class="max-h-[70vh] overflow-y-auto"
      shadow="never"
    >
      <ElTabs v-model="activeStep">
        <ElTabPane
          :label="`1. ${tl('choose_language_location_time')}/${tl('agent')}`"
          name="step1"
        >
          <ElForm label-position="top">
            <ElFormItem :label="tl('choose_language')">
              <ElSelect v-model="form.language" class="w-full" multiple>
                <ElOption
                  v-for="l in languages"
                  :key="l.id"
                  :label="l.name"
                  :value="l.id"
                />
              </ElSelect>
            </ElFormItem>
            <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
              <!-- 總代起手＋打字遠端搜尋，不再全量載入（target/tickets#4191） -->
              <ElFormItem v-if="!isEdit" :label="tl('agent_name')">
                <ElSelect
                  v-model="form.agents"
                  class="w-full"
                  :debounce="500"
                  :loading="agentSearching"
                  :remote-method="remoteSearchAgents"
                  filterable
                  multiple
                  remote
                  reserve-keyword
                >
                  <ElOption
                    v-for="a in agents"
                    :key="a.id"
                    :label="a.username"
                    :value="a.id"
                  />
                </ElSelect>
              </ElFormItem>
              <!-- 編輯模式改單選：可查看與改選廣告所屬代理，選項沿用總代起手＋遠端搜尋（target/tickets#4436） -->
              <ElFormItem v-else :label="tl('agent_name')">
                <ElSelect
                  v-model="form.agent_id"
                  class="w-full"
                  :debounce="500"
                  :loading="agentSearching"
                  :remote-method="remoteSearchAgents"
                  filterable
                  remote
                  reserve-keyword
                >
                  <ElOption
                    v-for="a in agents"
                    :key="a.id"
                    :label="a.username"
                    :value="a.id"
                  />
                </ElSelect>
              </ElFormItem>
              <ElFormItem :label="tl('exclude_agent')">
                <ElSelect
                  v-model="form.exclude_agent_ids"
                  class="w-full"
                  :debounce="500"
                  :loading="agentSearching"
                  :remote-method="remoteSearchAgents"
                  filterable
                  multiple
                  remote
                  reserve-keyword
                >
                  <ElOption
                    v-for="a in agents"
                    :key="a.id"
                    :label="a.username"
                    :value="a.id"
                  />
                </ElSelect>
              </ElFormItem>
            </div>
            <ElFormItem :label="tl('select_layout_location')">
              <ElSelect v-model="form.path_id" class="w-full" filterable>
                <ElOption
                  v-for="p in paths"
                  :key="p.id"
                  :label="p.name"
                  :value="p.id"
                />
              </ElSelect>
            </ElFormItem>
            <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
              <ElFormItem :label="tl('label_start_time')">
                <ElDatePicker
                  v-model="form.start_date"
                  class="!w-full"
                  format="YYYY-MM-DD HH:mm"
                  type="datetime"
                  value-format="YYYY-MM-DD HH:mm"
                />
              </ElFormItem>
              <ElFormItem :label="tl('label_end_time')">
                <ElDatePicker
                  v-model="form.end_date"
                  class="!w-full"
                  format="YYYY-MM-DD HH:mm"
                  type="datetime"
                  value-format="YYYY-MM-DD HH:mm"
                />
              </ElFormItem>
              <ElFormItem :label="tl('status')">
                <ElRadioGroup v-model="form.status">
                  <ElRadioButton :value="1">{{ tl('enable') }}</ElRadioButton>
                  <ElRadioButton :value="0">{{ tl('disable') }}</ElRadioButton>
                </ElRadioGroup>
              </ElFormItem>
              <ElFormItem :label="tl('sorts')">
                <ElInputNumber v-model="form.sort" :min="0" />
              </ElFormItem>
            </div>
          </ElForm>
        </ElTabPane>

        <ElTabPane
          :label="`2. ${tl('hyperlink')}/${tl('upload_image')}`"
          name="step2"
        >
          <ElForm label-position="top">
            <ElFormItem :label="tl('hyperlink')">
              <ElSelect v-model="form.type" class="w-full">
                <ElOption
                  :label="tl('advertisement_type_not_link')"
                  :value="0"
                />
                <ElOption :label="tl('advertisement_type_game')" :value="2" />
                <ElOption :label="tl('advertisement_type_vendor')" :value="3" />
                <ElOption
                  :label="tl('advertisement_type_external')"
                  :value="4"
                />
                <ElOption
                  :label="tl('advertisement_type_internal')"
                  :value="5"
                />
              </ElSelect>
            </ElFormItem>

            <!-- 連結至遊戲改兩段式（target/tickets#4167）：先選商縮範圍，遊戲遠端搜尋不再載全站 -->
            <template v-if="Number(form.type) === 2">
              <ElFormItem :label="tl('label_vendor')">
                <ElSelect
                  v-model="gameVendorId"
                  class="w-full"
                  filterable
                  @change="onGameVendorChange"
                >
                  <ElOption
                    v-for="v in vendors"
                    :key="v.id"
                    :label="v.name"
                    :value="v.id"
                  />
                </ElSelect>
              </ElFormItem>
              <ElFormItem :label="tl('game')">
                <ElSelect
                  v-model="form.game_id"
                  class="w-full"
                  :disabled="!gameVendorId"
                  :loading="gameSearching"
                  :remote-method="remoteSearchGames"
                  filterable
                  remote
                >
                  <ElOption
                    v-for="g in games"
                    :key="g.id"
                    :label="g.name"
                    :value="g.id"
                  />
                </ElSelect>
              </ElFormItem>
            </template>
            <ElFormItem
              v-else-if="Number(form.type) === 3"
              :label="tl('label_vendor')"
            >
              <ElSelect v-model="form.vendor_id" class="w-full" filterable>
                <ElOption
                  v-for="v in vendors"
                  :key="v.id"
                  :label="v.name"
                  :value="v.id"
                />
              </ElSelect>
            </ElFormItem>
            <template v-else-if="Number(form.type) === 4">
              <ElFormItem
                v-for="l in selectedLangs"
                :key="l.id"
                :label="l.name"
              >
                <ElInput v-model="form.external_url[l.id]" />
              </ElFormItem>
              <ElFormItem>
                <ElRadioGroup v-model="form.open_window">
                  <ElRadioButton :value="1">
                    {{ tl('open_window') }}
                  </ElRadioButton>
                  <ElRadioButton :value="0">-</ElRadioButton>
                </ElRadioGroup>
              </ElFormItem>
            </template>
            <ElFormItem
              v-else-if="Number(form.type) === 5"
              :label="tl('hyperlink')"
            >
              <ElSelect v-model="form.internal_type" class="w-full">
                <ElOption
                  v-for="it in internalTypes"
                  :key="it.parameter"
                  :label="
                    tl(
                      `advertise_internal_${String(it.parameter).toLowerCase()}`,
                    )
                  "
                  :value="it.parameter"
                />
              </ElSelect>
            </ElFormItem>
            <ElFormItem
              v-if="Number(form.type) === 5 && form.internal_type === 'route'"
              :label="tl('advertise_internal_route')"
            >
              <ElInput v-model="form.route_url" />
            </ElFormItem>

            <!-- 每語系圖片 -->
            <div
              v-for="l in selectedLangs"
              :key="l.id"
              class="mb-3 grid grid-cols-1 gap-4 border-t pt-3 md:grid-cols-3"
            >
              <div class="font-medium">{{ l.name }}</div>
              <!-- 轉圈只蓋住正在上傳的那個欄位，其他語系仍可繼續操作 -->
              <div v-loading="uploading[`${l.id}:image`]">
                <div class="text-muted-foreground mb-1 text-xs">
                  {{ tl('choose_mobile_ad_picture') }}
                </div>
                <img
                  v-if="ensureImageSlot(l.id).image"
                  :src="ensureImageSlot(l.id).image"
                  class="mb-1 max-h-32 w-full object-contain"
                />
                <div class="flex gap-2">
                  <ElButton
                    :disabled="uploading[`${l.id}:image`]"
                    size="small"
                    @click="uploadFile(l.id, 'image')"
                  >
                    {{ tl('upload_image') }}
                  </ElButton>
                  <ElButton
                    :disabled="uploading[`${l.id}:image`]"
                    size="small"
                    @click="openPicker(l.id, 'image')"
                  >
                    {{ tl('choose_file') }}
                  </ElButton>
                </div>
                <div class="text-muted-foreground mt-1 text-xs">
                  {{ imageUploadText('image_upload_hint') }}
                </div>
              </div>
              <div v-loading="uploading[`${l.id}:image_pc`]">
                <div class="text-muted-foreground mb-1 text-xs">
                  {{ tl('choose_pc_ad_picture') }}
                </div>
                <img
                  v-if="ensureImageSlot(l.id).image_pc"
                  :src="ensureImageSlot(l.id).image_pc"
                  class="mb-1 max-h-32 w-full object-contain"
                />
                <div class="flex gap-2">
                  <ElButton
                    :disabled="uploading[`${l.id}:image_pc`]"
                    size="small"
                    @click="uploadFile(l.id, 'image_pc')"
                  >
                    {{ tl('upload_image') }}
                  </ElButton>
                  <ElButton
                    :disabled="uploading[`${l.id}:image_pc`]"
                    size="small"
                    @click="openPicker(l.id, 'image_pc')"
                  >
                    {{ tl('choose_file') }}
                  </ElButton>
                </div>
                <div class="text-muted-foreground mt-1 text-xs">
                  {{ imageUploadText('image_upload_hint') }}
                </div>
              </div>
            </div>
          </ElForm>
        </ElTabPane>
      </ElTabs>
    </ElCard>

    <template #footer>
      <ElButton @click="visible = false">{{ tl('cancel') }}</ElButton>
      <ElButton
        v-if="activeStep === 'step1'"
        type="primary"
        @click="activeStep = 'step2'"
      >
        {{ tl('next') }}
      </ElButton>
      <span v-if="isUploading" class="text-warning mr-2 text-xs">
        {{ tl('image_uploading_wait') }}
      </span>
      <ElButton
        :disabled="isUploading"
        :loading="submitting"
        type="success"
        @click="submit"
      >
        {{ tl('btn_update') }}
      </ElButton>
    </template>
  </ElDialog>

  <ImageListDialog ref="picker" @select="onImageSelected" />
</template>
