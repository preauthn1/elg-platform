<script lang="ts" setup>
import type { EventV2Category, EventV2TargetOption } from '#/api';

import { computed, reactive, ref, watch } from 'vue';

import { useUserStore } from '@vben/stores';

import dayjs from 'dayjs';
import {
  ElButton,
  ElCheckbox,
  ElCheckboxGroup,
  ElDatePicker,
  ElDialog,
  ElForm,
  ElFormItem,
  ElImage,
  ElInput,
  ElMessage,
  ElOption,
  ElRadio,
  ElRadioGroup,
  ElSelect,
  ElTabPane,
  ElTabs,
} from 'element-plus';

import {
  createEventV2Api,
  EVENT_V2_PERIOD_HANDLER,
  EVENT_V2_SCORE_UNSUPPORTED_HANDLERS,
  getAgentViewByIdApi,
  getEventV2CategoryListApi,
  getEventV2DetailApi,
  getEventV2TargetOptionsApi,
  getGameTypeListApi,
  getLanguagesApi,
  getVendorListApi,
  searchAgentOptionsApi,
  updateEventV2Api,
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

import FieldParamsHint from './field-params-hint.vue';

defineOptions({ name: 'EventV2EditDialog' });

const emit = defineEmits<{ saved: [] }>();

interface LangRow {
  content: string;
  id: number;
  imageUrl: string;
  name: string;
  title: string;
}

const userStore = useUserStore();
const hostId = Number((userStore.userInfo as any)?.host_id ?? 0);

const visible = ref(false);
const loading = ref(false);
const saving = ref(false);
const editId = ref<number | string>('');
const disabledStarted = ref(false);
const activeStep = ref('1');

const vendors = ref<Array<{ id: number; is_free?: number; name: string }>>([]);
const gameTypes = ref<Array<{ id: number; name: string }>>([]);
const categories = ref<EventV2Category[]>([]);
const agentOptions = ref<Array<{ id: number; username: string }>>([]);
const excludeOptions = ref<Array<{ id: number; username: string }>>([]);
const agentLoading = ref(false);
const excludeLoading = ref(false);

const langRows = ref<LangRow[]>([]);
const langTab = ref('');

const form = reactive({
  account_block: [] as number[],
  agent_id: '' as number | string,
  balance_config: '',
  balance_formula: '',
  bonus_vendor_id: '' as number | string,
  condition_config: '',
  condition_formula: '',
  cost_type: '' as number | string,
  end_time: '',
  event_v2_id: '' as number | string,
  exclude_agent: [] as number[],
  exclude_vendor_data: [] as number[],
  flow_config: '',
  flow_formula: '',
  ip_block: [] as number[],
  is_recycle: 1 as number,
  join_withdraw: false,
  phone_block: [] as number[],
  restrict_game_type: [] as number[],
  restrict_vendor: [] as number[],
  reward_config: '',
  reward_formula: '',
  start_time: '',
  status: 1 as number,
  withdraw_config: '',
  withdraw_formula: '',
  // 領取去向：1=主錢包（預設）2=積分，對應 apis EventV2WithdrawTypeEnum（target/tickets#4231）
  withdraw_type: 1 as number,
  // 兩個週期各自獨立、只有紅包可設非 0（target/tickets#4234）：0=不分 1=日 2=週 3=月 4=年
  // member_period「可重複參加」＝同一會員多久能再抽；total_period「包括期間」＝全站上限多久重新計；兩者建立後鎖定（同 withdraw_type）
  member_period: 0 as number,
  total_period: 0 as number,
  // 目標用戶類型：0=不限制 1=指定會員 2=會員群組，所有活動類型共用（target/tickets#4234）
  target_type: 0 as number,
});

// ── 目標用戶：在代理商帳號那條線裡，二選一「指定會員／會員群組」或不選（照舊整條線）──
// 選項以「活動代理」為準（target-options），不是以操作者為準：admin 開活動不會看到別條線的會員／群組
const TARGET_MEMBER = 1;
const TARGET_GROUP = 2;
const targetMemberOptions = ref<EventV2TargetOption[]>([]);
const targetMemberSearching = ref(false);
const selectedTargetMembers = ref<EventV2TargetOption[]>([]);
const selectedTargetMemberIds = computed({
  get: () => selectedTargetMembers.value.map((m) => m.id),
  set: (ids: number[]) => {
    const idSet = new Set(ids);
    // 已選但不在這次搜尋結果裡的要保留，否則換關鍵字就掉 label
    const kept = selectedTargetMembers.value.filter(
      (m) =>
        idSet.has(m.id) &&
        !targetMemberOptions.value.some((o) => o.id === m.id),
    );
    const picked = targetMemberOptions.value.filter((o) => idSet.has(o.id));
    selectedTargetMembers.value = [...kept, ...picked];
  },
});
const targetGroupIds = ref<number[]>([]);
const memberGroupOptions = ref<EventV2TargetOption[]>([]);

async function searchTargetMembers(keyword: string) {
  const q = (keyword ?? '').trim();
  if (!q || !form.agent_id) {
    targetMemberOptions.value = [];
    return;
  }
  targetMemberSearching.value = true;
  try {
    const res = await getEventV2TargetOptionsApi({
      agent_id: form.agent_id,
      keyword: q,
      target_type: TARGET_MEMBER,
    });
    targetMemberOptions.value =
      Number(res.code) === 200 ? (res.data ?? []) : [];
  } finally {
    targetMemberSearching.value = false;
  }
}

/** 群組依代理商帳號載入：換代理就重載 */
async function loadTargetGroups() {
  if (!form.agent_id) {
    memberGroupOptions.value = [];
    return;
  }
  const res = await getEventV2TargetOptionsApi({
    agent_id: form.agent_id,
    target_type: TARGET_GROUP,
  }).catch(() => null);
  memberGroupOptions.value =
    res && Number(res.code) === 200 ? (res.data ?? []) : [];
}

/** 切換類型時清掉另一種的已選，避免存到不相干的 target_ids */
function onTargetTypeChange() {
  selectedTargetMembers.value = [];
  targetMemberOptions.value = [];
  targetGroupIds.value = [];
  if (form.target_type === TARGET_GROUP) loadTargetGroups();
}

/** 換代理商帳號：原本選的會員／群組可能不在新的線裡，清掉重選（後端也會擋越線） */
function onAgentChange() {
  onTargetTypeChange();
}

function targetIdsForSave(): number[] {
  if (form.target_type === TARGET_MEMBER) return selectedTargetMemberIds.value;
  if (form.target_type === TARGET_GROUP) return targetGroupIds.value;
  return [];
}

// 積分型現階段固定「參加即領獎」：勾選鎖定並自動勾上（後端也會強制存 1）
const isScoreWithdraw = computed(() => Number(form.withdraw_type) === 2);
watch(isScoreWithdraw, (score) => {
  if (!score) return;
  form.join_withdraw = true;
  // 積分與活動錢包無關：獎勵／餘額／稽核流水六格對積分型必須為空（後端會擋），切到積分就清掉並鎖定
  form.reward_formula = '';
  form.reward_config = '';
  form.balance_formula = '';
  form.balance_config = '';
  form.flow_formula = '';
  form.flow_config = '';
});

// 三種陣列型不派發積分：選了積分就鎖掉這些類型；選了這些類型就鎖掉積分（後端同樣會擋）
function isScoreUnsupported(c: EventV2Category) {
  return EVENT_V2_SCORE_UNSUPPORTED_HANDLERS.has(c.handler_class ?? '');
}
const selectedCategory = computed(() =>
  categories.value.find((x) => x.id === Number(form.event_v2_id)),
);
const selectedCategoryScoreUnsupported = computed(() =>
  selectedCategory.value ? isScoreUnsupported(selectedCategory.value) : false,
);

// 十格標籤的參數說明彈窗（需求 7）：清單來自 event-list 的 field_params，依目前類型與領取去向挑該顯示的一組
const paramsHintProps = computed(() => ({
  hasCategory: Boolean(selectedCategory.value),
  params: selectedCategory.value?.field_params,
  score: isScoreWithdraw.value,
}));

// 兩個週期只有紅包能設（後端 ValidatesAngpow 同樣會擋）：切到其他類型就歸 0 並鎖住
const isAngpow = computed(
  () => selectedCategory.value?.handler_class === EVENT_V2_PERIOD_HANDLER,
);
watch(isAngpow, (angpow) => {
  if (angpow) return;
  form.member_period = 0;
  form.total_period = 0;
});
const PERIOD_OPTIONS = [0, 1, 2, 3, 4] as const;

// 圖庫挑選（共用組件，target 帶語系 id）
const picker = ref<InstanceType<typeof ImageListDialog>>();

// 配置欄 JSON 範例（放常數避免模板屬性雙/單引號衝突）
const PH_CONDITION = '[{"count":3,"deposit":"10"}]';
const PH_REWARD = '[{"reward":6.99,"condition":"deposit < 50"}]';
const PH_BALANCE = '[{"balance":50}]';
// 提款限制配置是物件不是陣列（程式只讀 balance_multiple）；積分型這一對欄位＝積分數量＋{有效期, 上限}
const PH_WITHDRAW = '{"balance_multiple": 1}';
const PH_WITHDRAW_SCORE =
  '{"expire": 720, "limit": 300, "tiers": [{"condition": "deposit >= 300", "score": 30}]}';
const PH_FLOW = '[{"reward":50}]';

async function searchAgent(query: string) {
  if (!query) return;
  agentLoading.value = true;
  try {
    const res = await searchAgentOptionsApi(query);
    if (Number(res.code) === 1000) agentOptions.value = res.data ?? [];
  } finally {
    agentLoading.value = false;
  }
}
async function searchExclude(query: string) {
  if (!query) return;
  excludeLoading.value = true;
  try {
    const res = await searchAgentOptionsApi(query);
    if (Number(res.code) === 1000) excludeOptions.value = res.data ?? [];
  } finally {
    excludeLoading.value = false;
  }
}

function resetForm() {
  Object.assign(form, {
    account_block: [],
    agent_id: '',
    balance_config: '',
    balance_formula: '',
    bonus_vendor_id: '',
    condition_config: '',
    condition_formula: '',
    cost_type: '',
    end_time: dayjs().add(1, 'month').format('YYYY-MM-DD 23:59'),
    event_v2_id: '',
    exclude_agent: [],
    exclude_vendor_data: [],
    flow_config: '',
    flow_formula: '',
    ip_block: [],
    is_recycle: 1,
    join_withdraw: false,
    phone_block: [],
    restrict_game_type: [],
    restrict_vendor: [],
    reward_config: '',
    reward_formula: '',
    start_time: dayjs().format('YYYY-MM-DD 00:00'),
    status: 1,
    withdraw_config: '',
    withdraw_formula: '',
    withdraw_type: 1,
    member_period: 0,
    total_period: 0,
    target_type: 0,
  });
  onTargetTypeChange();
}

async function loadStatics() {
  if (vendors.value.length === 0) {
    vendors.value = ((await getVendorListApi()) as any) ?? [];
  }
  if (gameTypes.value.length === 0) {
    gameTypes.value = (await getGameTypeListApi()) ?? [];
  }
  if (categories.value.length === 0) {
    const c = await getEventV2CategoryListApi();
    if (Number(c.code) === 200) categories.value = c.data ?? [];
  }
}

async function open(id?: number | string) {
  visible.value = true;
  loading.value = true;
  editId.value = id ?? '';
  disabledStarted.value = false;
  activeStep.value = '1';
  resetForm();
  try {
    await loadStatics();
    const languages = (await getLanguagesApi()) ?? [];
    langRows.value = languages.map((l) => ({
      content: '',
      id: l.id,
      imageUrl: '',
      name: l.name,
      title: '',
    }));
    langTab.value = langRows.value[0] ? String(langRows.value[0].id) : '';

    if (id) {
      const res = await getEventV2DetailApi(id);
      if (Number(res.code) !== 200) {
        ElMessage.error(res.message);
        visible.value = false;
        return;
      }
      const d = res.data ?? {};
      disabledStarted.value =
        !!d.start_time && dayjs(d.start_time).isBefore(dayjs());
      form.start_time = d.start_time ?? form.start_time;
      form.end_time = d.end_time ?? form.end_time;
      form.status = Number(d.status ?? 1);
      form.is_recycle = Number(d.is_recycle ?? 1);
      form.cost_type = d.cost_type ?? '';
      form.event_v2_id = d.event_v2_id ?? '';
      form.bonus_vendor_id = d.bonus_vendor_id ?? '';
      form.join_withdraw = Number(d.join_withdraw) === 1;
      form.withdraw_type = Number(d.withdraw_type ?? 1);
      form.member_period = Number(d.member_period ?? 0);
      form.total_period = Number(d.total_period ?? 0);
      form.ip_block = (d.ip_block ?? []).map(Number);
      form.phone_block = (d.phone_block ?? []).map(Number);
      form.account_block = (d.account_block ?? []).map(Number);
      form.condition_formula = d.condition_formula ?? '';
      form.reward_formula = d.reward_formula ?? '';
      form.balance_formula = d.balance_formula ?? '';
      form.withdraw_formula = d.withdraw_formula ?? '';
      form.flow_formula = d.flow_formula ?? '';
      form.condition_config = d.condition_config
        ? JSON.stringify(d.condition_config)
        : '';
      form.reward_config = d.reward_config
        ? JSON.stringify(d.reward_config)
        : '';
      form.balance_config = d.balance_config
        ? JSON.stringify(d.balance_config)
        : '';
      form.withdraw_config = d.withdraw_config
        ? JSON.stringify(d.withdraw_config)
        : '';
      form.flow_config = d.flow_config ? JSON.stringify(d.flow_config) : '';
      form.restrict_vendor = (d.restrict_vendor ?? []).map((r: any) => r.id);
      form.restrict_game_type = (d.restrict_game_type ?? []).map(
        (r: any) => r.id,
      );
      form.exclude_vendor_data = (d.exclude_vendor ?? []).map((r: any) => r.id);
      form.exclude_agent = (d.exclude_agent ?? []).map((r: any) => r.id);
      excludeOptions.value = (d.exclude_agent ?? []).map((r: any) => ({
        id: r.id,
        username: r.agent_username,
      }));
      // 目標用戶：從後端回傳值初始化，沒動就原樣送回，不會把既有設定清掉
      form.target_type = Number(d.target_type ?? 0);
      const targets: Array<{ id: number; name: string }> = d.targets ?? [];
      if (form.target_type === TARGET_MEMBER) {
        selectedTargetMembers.value = targets.map((t) => ({
          id: Number(t.id),
          name: t.name,
        }));
      } else if (form.target_type === TARGET_GROUP) {
        targetGroupIds.value = targets.map((t) => Number(t.id));
      }
      form.agent_id = d.agent_id ?? '';
      if (form.target_type === TARGET_GROUP) await loadTargetGroups();
      if (d.agent_id) {
        const a = (await getAgentViewByIdApi(Number(d.agent_id))) as any;
        agentOptions.value = [
          { id: Number(d.agent_id), username: a?.username ?? '' },
        ];
      }
      for (const dl of d.detail_lang ?? []) {
        const row = langRows.value.find((r) => r.id === Number(dl.language_id));
        if (!row) continue;
        row.title = dl.name ?? '';
        row.content = dl.content ?? '';
        row.imageUrl = dl.image ?? '';
      }
    } else {
      // 新增：排除遊戲商預設帶 is_free 的
      form.exclude_vendor_data = vendors.value
        .filter((v) => Number(v.is_free) === 1)
        .map((v) => v.id);
    }
  } finally {
    loading.value = false;
  }
}

async function onPickFile(e: Event, row: LangRow) {
  const file = (e.target as HTMLInputElement).files?.[0];
  if (!file) return;
  let base64: string;
  try {
    base64 = await readImageAsDataUrl(file);
  } catch (error) {
    if (error instanceof ImageUploadError) {
      ElMessage.error(error.message);
      return;
    }
    throw error;
  }
  row.imageUrl = base64;
  const res = await uploadAdvertiseImageApi({
    agent_id: hostId,
    base64Image: base64,
  });
  if (Number(res.code) === 1000) {
    row.imageUrl = res.data?.path ?? base64;
  } else {
    ElMessage.error(res.message);
  }
}

function openPicker(lang: number) {
  picker.value?.open(String(lang));
}
function onImageSelected(p: {
  id: number | string;
  target: string;
  url: string;
}) {
  const row = langRows.value.find((r) => r.id === Number(p.target));
  if (row) row.imageUrl = p.url;
}

function parseConfig(raw: string): any {
  if (!raw) return undefined;
  try {
    return JSON.parse(raw);
  } catch {
    return null;
  }
}

async function handleSave() {
  saving.value = true;
  try {
    const payload: Record<string, any> = {
      account_block: form.account_block,
      agent_id: form.agent_id,
      exclude_agent: form.exclude_agent,
      target_ids: targetIdsForSave(),
      target_type: form.target_type,
      exclude_vendor_data: form.exclude_vendor_data,
      ip_block: form.ip_block,
      is_recycle: form.is_recycle,
      join_withdraw: form.join_withdraw ? 1 : 0,
      phone_block: form.phone_block,
      restrict_game_type: form.restrict_game_type,
      restrict_vendor: form.restrict_vendor,
      status: form.status,
      withdraw_type: form.withdraw_type,
      member_period: form.member_period,
      total_period: form.total_period,
    };
    // 純量欄位：非空才帶
    const scalars: Array<[string, any]> = [
      ['event_v2_id', form.event_v2_id],
      ['condition_formula', form.condition_formula],
      ['reward_formula', form.reward_formula],
      ['balance_formula', form.balance_formula],
      ['withdraw_formula', form.withdraw_formula],
      ['flow_formula', form.flow_formula],
      ['bonus_vendor_id', form.bonus_vendor_id],
      ['cost_type', form.cost_type],
      ['start_time', form.start_time],
      ['end_time', form.end_time],
    ];
    for (const [k, v] of scalars) {
      if (v !== '' && v !== null && v !== undefined) payload[k] = v;
    }
    // JSON 欄位
    const configs: Array<[string, string]> = [
      ['condition_config', form.condition_config],
      ['reward_config', form.reward_config],
      ['balance_config', form.balance_config],
      ['withdraw_config', form.withdraw_config],
      ['flow_config', form.flow_config],
    ];
    for (const [k, raw] of configs) {
      const parsed = parseConfig(raw);
      if (parsed !== undefined) payload[k] = parsed;
    }
    // 多語
    payload.detail_lang = [];
    for (const row of langRows.value) {
      const title = row.title || null;
      const content = row.content || null;
      const image = row.imageUrl || null;
      if (title || content || image) {
        payload.detail_lang.push({
          content,
          image,
          language_id: row.id,
          name: title,
          reward_name: null,
        });
      }
    }

    let res;
    if (editId.value) {
      payload.event_detail_id = editId.value;
      res = await updateEventV2Api(payload);
    } else {
      res = await createEventV2Api(payload);
    }
    if (Number(res.code) === 200) {
      ElMessage.success(res.message);
      visible.value = false;
      emit('saved');
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
    :title="`${tl('menu_event_v2')} > ${editId ? tl('edit') : tl('add')}${tl('event')}`"
    align-center
    width="980"
  >
    <div class="max-h-[74vh] overflow-y-auto pr-1">
      <ElTabs v-model="activeStep" v-loading="loading" type="border-card">
        <!-- 步驟1 名稱內容 -->
        <ElTabPane :label="tl('event_and_content')" name="1">
          <ElForm label-position="top">
            <div class="grid grid-cols-1 gap-x-4 md:grid-cols-3">
              <ElFormItem :label="tl('agent_account')" required>
                <ElSelect
                  v-model="form.agent_id"
                  :debounce="500"
                  :loading="agentLoading"
                  :remote-method="searchAgent"
                  class="w-full"
                  filterable
                  remote
                  @change="onAgentChange"
                >
                  <ElOption
                    v-for="a in agentOptions"
                    :key="a.id"
                    :label="a.username"
                    :value="a.id"
                  />
                </ElSelect>
              </ElFormItem>
              <ElFormItem :label="tl('exclude_agent')">
                <ElSelect
                  v-model="form.exclude_agent"
                  :debounce="500"
                  :loading="excludeLoading"
                  :remote-method="searchExclude"
                  class="w-full"
                  filterable
                  multiple
                  remote
                >
                  <ElOption
                    v-for="a in excludeOptions"
                    :key="a.id"
                    :label="a.username"
                    :value="a.id"
                  />
                </ElSelect>
              </ElFormItem>
              <ElFormItem :label="tl('status')">
                <ElRadioGroup v-model="form.status">
                  <ElRadio :value="1">{{ tl('enable') }}</ElRadio>
                  <ElRadio :value="0">{{ tl('disable') }}</ElRadio>
                </ElRadioGroup>
              </ElFormItem>
              <!-- 目標用戶（所有活動類型共用）：代理商帳號照舊決定整條線；這裡在線內再縮小，不選＝照舊整條線。
                   獨立佔一整列、右側兩欄固定保留給選擇器，切換類型只換這一列的內容，其他欄位不會被擠動 -->
              <div
                class="grid grid-cols-1 gap-x-4 md:col-span-3 md:grid-cols-3"
              >
                <ElFormItem :label="tl('game_restriction_scope')">
                  <ElSelect
                    v-model="form.target_type"
                    :disabled="!form.agent_id"
                    class="w-full"
                    @change="onTargetTypeChange"
                  >
                    <ElOption :label="tl('unlimited')" :value="0" />
                    <ElOption
                      :label="tl('game_restriction_scope_member')"
                      :value="TARGET_MEMBER"
                    />
                    <ElOption
                      :label="tl('game_restriction_scope_group')"
                      :value="TARGET_GROUP"
                    />
                  </ElSelect>
                </ElFormItem>
                <div class="md:col-span-2">
                  <ElFormItem
                    v-if="form.target_type === TARGET_MEMBER"
                    :label="tl('game_restriction_scope_member')"
                  >
                    <ElSelect
                      v-model="selectedTargetMemberIds"
                      :loading="targetMemberSearching"
                      :remote-method="searchTargetMembers"
                      class="w-full"
                      collapse-tags
                      collapse-tags-tooltip
                      filterable
                      multiple
                      remote
                      reserve-keyword
                    >
                      <ElOption
                        v-for="m in [
                          ...selectedTargetMembers.filter(
                            (s) =>
                              !targetMemberOptions.some((o) => o.id === s.id),
                          ),
                          ...targetMemberOptions,
                        ]"
                        :key="m.id"
                        :label="m.name"
                        :value="m.id"
                      />
                    </ElSelect>
                  </ElFormItem>
                  <ElFormItem
                    v-else-if="form.target_type === TARGET_GROUP"
                    :label="tl('game_restriction_scope_group')"
                  >
                    <ElSelect
                      v-model="targetGroupIds"
                      class="w-full"
                      collapse-tags
                      collapse-tags-tooltip
                      filterable
                      multiple
                    >
                      <ElOption
                        v-for="g in memberGroupOptions"
                        :key="g.id"
                        :label="g.name"
                        :value="g.id"
                      />
                    </ElSelect>
                  </ElFormItem>
                </div>
              </div>
              <ElFormItem :label="tl('restrict_vendor')">
                <ElSelect
                  v-model="form.restrict_vendor"
                  class="w-full"
                  filterable
                  multiple
                >
                  <ElOption
                    v-for="v in vendors"
                    :key="v.id"
                    :label="v.name"
                    :value="v.id"
                  />
                </ElSelect>
              </ElFormItem>
              <ElFormItem :label="tl('restrict_game_type')">
                <ElSelect
                  v-model="form.restrict_game_type"
                  class="w-full"
                  filterable
                  multiple
                >
                  <ElOption
                    v-for="g in gameTypes"
                    :key="g.id"
                    :label="g.name"
                    :value="g.id"
                  />
                </ElSelect>
              </ElFormItem>
              <ElFormItem :label="tl('is_recycle')">
                <ElRadioGroup v-model="form.is_recycle">
                  <ElRadio :value="1">{{ tl('enable') }}</ElRadio>
                  <ElRadio :value="0">{{ tl('disable') }}</ElRadio>
                </ElRadioGroup>
              </ElFormItem>
              <ElFormItem :label="tl('exclude_vendor')">
                <ElSelect
                  v-model="form.exclude_vendor_data"
                  class="w-full"
                  filterable
                  multiple
                >
                  <ElOption
                    v-for="v in vendors"
                    :key="v.id"
                    :label="v.name"
                    :value="v.id"
                  />
                </ElSelect>
              </ElFormItem>
              <ElFormItem :label="tl('cost_type')" required>
                <ElSelect
                  v-model="form.cost_type"
                  :disabled="disabledStarted"
                  class="w-full"
                >
                  <ElOption :label="tl('please_choose')" value="" />
                  <ElOption :label="tl('cost_type_1')" :value="1" />
                  <ElOption :label="tl('cost_type_2')" :value="2" />
                </ElSelect>
              </ElFormItem>
            </div>
          </ElForm>

          <ElTabs v-model="langTab" class="mt-2">
            <ElTabPane
              v-for="row in langRows"
              :key="row.id"
              :label="row.name"
              :name="String(row.id)"
            >
              <ElFormItem :label="tl('event_name')" label-width="auto">
                <ElInput v-model="row.title" />
              </ElFormItem>
              <ElFormItem :label="tl('event_content')" label-width="auto">
                <ElInput v-model="row.content" :rows="4" type="textarea" />
              </ElFormItem>
              <ElFormItem :label="tl('picture')" label-width="auto">
                <div>
                  <ElImage
                    v-if="row.imageUrl"
                    :src="row.imageUrl"
                    class="mb-2 max-h-[200px]"
                    fit="contain"
                  />
                  <div class="flex gap-2">
                    <label class="cursor-pointer">
                      <span class="el-button el-button--small">
                        {{ tl('upload_image') }}
                      </span>
                      <input
                        :accept="IMAGE_ACCEPT"
                        class="hidden"
                        type="file"
                        @change="(e) => onPickFile(e, row)"
                      />
                    </label>
                    <ElButton size="small" @click="openPicker(row.id)">
                      {{ tl('choose_file') }}
                    </ElButton>
                  </div>
                  <div class="text-muted-foreground mt-1 text-xs">
                    {{ imageUploadText('image_upload_hint') }}
                  </div>
                </div>
              </ElFormItem>
            </ElTabPane>
          </ElTabs>
        </ElTabPane>

        <!-- 步驟2 條件設定 -->
        <ElTabPane :label="tl('event_condition_setting')" name="2">
          <ElForm label-position="top">
            <div class="grid grid-cols-1 gap-x-4 md:grid-cols-3">
              <ElFormItem :label="tl('choose_event_category')" required>
                <ElSelect
                  v-model="form.event_v2_id"
                  :disabled="disabledStarted"
                  class="w-full"
                >
                  <ElOption :label="tl('please_choose')" value="" />
                  <ElOption
                    v-for="c in categories"
                    :key="c.id"
                    :disabled="isScoreWithdraw && isScoreUnsupported(c)"
                    :label="c.name"
                    :value="c.id"
                  />
                </ElSelect>
              </ElFormItem>
              <ElFormItem :label="tl('label_start_time')" required>
                <ElDatePicker
                  v-model="form.start_time"
                  :disabled="disabledStarted"
                  class="!w-full"
                  format="YYYY-MM-DD HH:mm"
                  type="datetime"
                  value-format="YYYY-MM-DD HH:mm"
                />
              </ElFormItem>
              <ElFormItem :label="tl('label_end_time')" required>
                <ElDatePicker
                  v-model="form.end_time"
                  class="!w-full"
                  format="YYYY-MM-DD HH:mm"
                  type="datetime"
                  value-format="YYYY-MM-DD HH:mm"
                />
              </ElFormItem>
            </div>

            <div class="grid grid-cols-1 gap-x-8 gap-y-4 mb-4 md:grid-cols-3">
              <div>
                <div class="mb-1 font-medium">
                  {{ tl('block_duplicate_ip') }}
                </div>
                <ElCheckboxGroup v-model="form.ip_block">
                  <div>
                    <ElCheckbox :value="1">{{ tl('ip_block_1') }}</ElCheckbox>
                  </div>
                  <div>
                    <ElCheckbox :value="2">{{ tl('ip_block_2') }}</ElCheckbox>
                  </div>
                  <div>
                    <ElCheckbox :value="3">{{ tl('ip_block_3') }}</ElCheckbox>
                  </div>
                </ElCheckboxGroup>
              </div>
              <div>
                <div class="mb-1 font-medium">
                  {{ tl('block_duplicate_phone') }}
                </div>
                <ElCheckboxGroup v-model="form.phone_block">
                  <div>
                    <ElCheckbox :value="1">
                      {{ tl('phone_block_1') }}
                    </ElCheckbox>
                  </div>
                  <div>
                    <ElCheckbox :value="2">
                      {{ tl('phone_block_2') }}
                    </ElCheckbox>
                  </div>
                  <div>
                    <ElCheckbox :value="3">
                      {{ tl('phone_block_3') }}
                    </ElCheckbox>
                  </div>
                </ElCheckboxGroup>
              </div>
              <div>
                <div class="mb-1 font-medium">
                  {{ tl('block_duplicate_account') }}
                </div>
                <ElCheckboxGroup v-model="form.account_block">
                  <div>
                    <ElCheckbox :value="1">
                      {{ tl('account_block_1') }}
                    </ElCheckbox>
                  </div>
                  <div>
                    <ElCheckbox :value="2">
                      {{ tl('account_block_2') }}
                    </ElCheckbox>
                  </div>
                  <div>
                    <ElCheckbox :value="3">
                      {{ tl('account_block_3') }}
                    </ElCheckbox>
                  </div>
                </ElCheckboxGroup>
              </div>
            </div>

            <!-- 阻擋勾選是主要設定放前面；後加的下拉類欄位排成一列放後面，避免右欄直排留一大塊空白 -->
            <div class="grid grid-cols-1 gap-x-4 md:grid-cols-4">
              <ElFormItem :label="tl('bonus_vendor')">
                <ElSelect
                  v-model="form.bonus_vendor_id"
                  class="w-full"
                  clearable
                  filterable
                >
                  <ElOption :label="tl('please_choose')" value="" />
                  <ElOption
                    v-for="v in vendors"
                    :key="v.id"
                    :label="v.name"
                    :value="v.id"
                  />
                </ElSelect>
              </ElFormItem>
              <ElFormItem :label="tl('withdraw_type')">
                <div class="w-full">
                  <ElSelect
                    v-model="form.withdraw_type"
                    :disabled="!!editId"
                    class="w-full"
                  >
                    <ElOption :label="tl('withdraw_type_1')" :value="1" />
                    <ElOption
                      :disabled="selectedCategoryScoreUnsupported"
                      :label="tl('withdraw_type_2')"
                      :value="2"
                    />
                  </ElSelect>
                  <div
                    v-if="editId"
                    class="mt-1 text-xs leading-4 text-gray-500"
                  >
                    {{ tl('withdraw_type_locked_hint') }}
                  </div>
                </div>
              </ElFormItem>
              <ElFormItem :label="tl('event_v2_member_period')">
                <div class="w-full">
                  <ElSelect
                    v-model="form.member_period"
                    :disabled="!isAngpow || !!editId"
                    class="w-full"
                  >
                    <ElOption
                      v-for="p in PERIOD_OPTIONS"
                      :key="p"
                      :label="tl(`event_v2_period_${p}`)"
                      :value="p"
                    />
                  </ElSelect>
                  <div class="mt-1 text-xs leading-4 text-gray-500">
                    {{
                      tl(
                        isAngpow
                          ? 'event_v2_member_period_hint'
                          : 'event_v2_period_hint_other',
                      )
                    }}
                  </div>
                  <div
                    v-if="editId && isAngpow"
                    class="mt-1 text-xs leading-4 text-gray-500"
                  >
                    {{ tl('event_v2_period_locked_hint') }}
                  </div>
                </div>
              </ElFormItem>
              <ElFormItem :label="tl('event_v2_total_period')">
                <div class="w-full">
                  <ElSelect
                    v-model="form.total_period"
                    :disabled="!isAngpow || !!editId"
                    class="w-full"
                  >
                    <ElOption
                      v-for="p in PERIOD_OPTIONS"
                      :key="p"
                      :label="tl(`event_v2_period_${p}`)"
                      :value="p"
                    />
                  </ElSelect>
                  <div class="mt-1 text-xs leading-4 text-gray-500">
                    {{
                      tl(
                        isAngpow
                          ? 'event_v2_total_period_hint'
                          : 'event_v2_period_hint_other',
                      )
                    }}
                  </div>
                  <div
                    v-if="editId && isAngpow"
                    class="mt-1 text-xs leading-4 text-gray-500"
                  >
                    {{ tl('event_v2_period_locked_hint') }}
                  </div>
                </div>
              </ElFormItem>
            </div>

            <ElCheckbox
              v-model="form.join_withdraw"
              :disabled="isScoreWithdraw"
              class="mb-4"
            >
              {{ tl('join_withdraw') }}
            </ElCheckbox>

            <div class="mt-3 grid grid-cols-1 gap-x-4 md:grid-cols-2">
              <ElFormItem>
                <template #label>
                  <FieldParamsHint
                    field="condition_formula"
                    v-bind="paramsHintProps"
                  >
                    {{ tl('condition_formula') }}
                  </FieldParamsHint>
                </template>
                <ElInput
                  v-model="form.condition_formula"
                  :rows="6"
                  placeholder="deposit >= 20"
                  type="textarea"
                />
              </ElFormItem>
              <ElFormItem>
                <template #label>
                  <FieldParamsHint
                    field="condition_config"
                    v-bind="paramsHintProps"
                  >
                    {{ tl('condition_config') }}
                  </FieldParamsHint>
                </template>
                <ElInput
                  v-model="form.condition_config"
                  :placeholder="PH_CONDITION"
                  :rows="6"
                  type="textarea"
                />
              </ElFormItem>
              <ElFormItem>
                <template #label>
                  <FieldParamsHint
                    field="reward_formula"
                    v-bind="paramsHintProps"
                  >
                    {{ tl('reward_formula') }}
                  </FieldParamsHint>
                </template>
                <ElInput
                  v-model="form.reward_formula"
                  :disabled="isScoreWithdraw"
                  :rows="6"
                  placeholder="win_lose * 0.05"
                  type="textarea"
                />
              </ElFormItem>
              <ElFormItem>
                <template #label>
                  <FieldParamsHint
                    field="reward_config"
                    v-bind="paramsHintProps"
                  >
                    {{ tl('reward_config') }}
                  </FieldParamsHint>
                </template>
                <ElInput
                  v-model="form.reward_config"
                  :disabled="isScoreWithdraw"
                  :placeholder="PH_REWARD"
                  :rows="6"
                  type="textarea"
                />
              </ElFormItem>
              <ElFormItem>
                <template #label>
                  <FieldParamsHint
                    field="balance_formula"
                    v-bind="paramsHintProps"
                  >
                    {{ tl('balance_limit_formula') }}
                  </FieldParamsHint>
                </template>
                <ElInput
                  v-model="form.balance_formula"
                  :disabled="isScoreWithdraw"
                  :rows="6"
                  placeholder="(deposit + reward) * 5"
                  type="textarea"
                />
              </ElFormItem>
              <ElFormItem>
                <template #label>
                  <FieldParamsHint
                    field="balance_config"
                    v-bind="paramsHintProps"
                  >
                    {{ tl('balance_limit_config') }}
                  </FieldParamsHint>
                </template>
                <ElInput
                  v-model="form.balance_config"
                  :disabled="isScoreWithdraw"
                  :placeholder="PH_BALANCE"
                  :rows="6"
                  type="textarea"
                />
              </ElFormItem>
              <ElFormItem>
                <template #label>
                  <FieldParamsHint
                    field="withdraw_formula"
                    v-bind="paramsHintProps"
                  >
                    {{ tl('withdrawal_limit_formula') }}
                  </FieldParamsHint>
                </template>
                <ElInput
                  v-model="form.withdraw_formula"
                  :placeholder="
                    isScoreWithdraw ? 'rand(10, 500)' : 'reward * 3'
                  "
                  :rows="6"
                  type="textarea"
                />
              </ElFormItem>
              <ElFormItem>
                <template #label>
                  <FieldParamsHint
                    field="withdraw_config"
                    v-bind="paramsHintProps"
                  >
                    {{ tl('withdrawal_limit_config') }}
                  </FieldParamsHint>
                </template>
                <ElInput
                  v-model="form.withdraw_config"
                  :placeholder="
                    isScoreWithdraw ? PH_WITHDRAW_SCORE : PH_WITHDRAW
                  "
                  :rows="6"
                  type="textarea"
                />
              </ElFormItem>
              <ElFormItem>
                <template #label>
                  <FieldParamsHint
                    field="flow_formula"
                    v-bind="paramsHintProps"
                  >
                    {{ tl('flow_limit_formula') }}
                  </FieldParamsHint>
                </template>
                <ElInput
                  v-model="form.flow_formula"
                  :disabled="isScoreWithdraw"
                  :rows="6"
                  placeholder="reward * 5"
                  type="textarea"
                />
              </ElFormItem>
              <ElFormItem>
                <template #label>
                  <FieldParamsHint field="flow_config" v-bind="paramsHintProps">
                    {{ tl('flow_limit_config') }}
                  </FieldParamsHint>
                </template>
                <ElInput
                  v-model="form.flow_config"
                  :disabled="isScoreWithdraw"
                  :placeholder="PH_FLOW"
                  :rows="6"
                  type="textarea"
                />
              </ElFormItem>
            </div>
          </ElForm>
        </ElTabPane>
      </ElTabs>
    </div>

    <template #footer>
      <ElButton @click="visible = false">{{ tl('btn_back') }}</ElButton>
      <ElButton :loading="saving" type="primary" @click="handleSave">
        {{ tl('btn_update') }}
      </ElButton>
    </template>

    <ImageListDialog ref="picker" @select="onImageSelected" />
  </ElDialog>
</template>

<style scoped>
/* 阻擋類勾選項標籤較長，讓文字換行不要溢出蓋到隔壁欄 */
:deep(.el-checkbox) {
  align-items: flex-start;
  height: auto;
  white-space: normal;
}

:deep(.el-checkbox__label) {
  line-height: 1.3;
  white-space: normal;
}

/* 勾選項之間拉開垂直間距，換行的長標籤才不會擠在一起 */
:deep(.el-checkbox-group > div) {
  margin-bottom: 8px;
}

:deep(.el-checkbox-group > div:last-child) {
  margin-bottom: 0;
}
</style>
