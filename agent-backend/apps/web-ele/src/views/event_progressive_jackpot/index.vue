<script lang="ts" setup>
import type {
  EventProgressiveJackpotImageRow,
  EventProgressiveJackpotTierPayload,
  EventProgressiveJackpotTitleRow,
  EventProgressiveJackpotViewData,
} from '#/api';

import { computed, onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';
import { useUserStore } from '@vben/stores';

import {
  ElButton,
  ElCard,
  ElCheckbox,
  ElDivider,
  ElInput,
  ElMessage,
  ElMessageBox,
  ElOption,
  ElSelect,
  ElSwitch,
  ElTable,
  ElTableColumn,
  ElTabPane,
  ElTabs,
  ElTag,
} from 'element-plus';

import {
  AGENT_SEARCH_LIMIT,
  getEventProgressiveJackpotGameTypesApi,
  getEventProgressiveJackpotViewApi,
  getLanguagesApi,
  searchAgentOptionsApi,
  updateEventProgressiveJackpotBetSettingApi,
  updateEventProgressiveJackpotImageApi,
  updateEventProgressiveJackpotSettingApi,
  updateEventProgressiveJackpotTierSettingApi,
  updateEventProgressiveJackpotTitleApi,
  uploadEventProgressiveJackpotImageApi,
} from '#/api';
import ImageInput from '#/components/image-input.vue';
import { tl } from '#/locales';

import TierEditDialog from './components/tier-edit-dialog.vue';

defineOptions({ name: 'EventProgressiveJackpotSetting' });

/** 預設語系在後端以 language_id 0 表示 */
const DEFAULT_LANGUAGE_ID = 0;

const userStore = useUserStore();
/** 全域設定、投注設定與檔位設定只有 Admin(id=1) 或其子帳號(host_id=1) 可儲存 */
const isAdminOrSub = computed(() => {
  const u = userStore.userInfo as any;
  return Number(u?.id) === 1 || Number(u?.host_id) === 1;
});
const uploadAgentId = computed(
  () => Number((userStore.userInfo as any)?.id ?? 0) || 0,
);

const languages = ref<{ id: number; name: string }[]>([]);
const gameTypes = ref<{ id: number; name: string }[]>([]);
const agentOptions = ref<{ id: number; username: string }[]>([]);
const agentSearching = ref(false);

const titleTab = ref(String(DEFAULT_LANGUAGE_ID));
const bannerTab = ref(String(DEFAULT_LANGUAGE_ID));

/** 畫面上的檔位；is_first_deposit 那筆恆排第一 */
interface TierItem extends EventProgressiveJackpotTierPayload {}

const form = reactive({
  agent_id: '' as number | string,
  check_ip: false,
  check_phone: false,
  check_realname: false,
  contents: {} as Record<string, string>,
  exclude_agent: [] as (number | string)[],
  first_deposit_type: 1 as number | string,
  game_type_ids: [] as (number | string)[],
  /** 畫面輸入的是百分比數字（30 代表 30%） */
  game_type_ratio: {} as Record<string, number | string>,
  images: {} as Record<string, string>,
  setting_id: 0 as number | string,
  sort: 0 as number | string,
  status: false,
  titles: {} as Record<string, string>,
});

const tiers = ref<TierItem[]>([]);

const loading = ref(false);
const savingTitle = ref(false);
const savingBanner = ref(false);
const savingSetting = ref(false);
const savingBetSetting = ref(false);
const savingTier = ref(false);

const tierDialog = ref<InstanceType<typeof TierEditDialog>>();

/** 語系分頁：預設語系 + api/languages */
const tabLanguages = computed(() => [
  { id: DEFAULT_LANGUAGE_ID, name: tl('default') },
  ...languages.value,
]);

/** 已勾選的遊戲類別，用來畫解鎖比例欄位 */
const selectedGameTypes = computed(() =>
  gameTypes.value.filter((g) => form.game_type_ids.includes(g.id)),
);

function sortTiers(list: TierItem[]) {
  const idx = list.findIndex((t) => t.is_first_deposit);
  if (idx > 0) {
    const [first] = list.splice(idx, 1);
    if (first) list.unshift(first);
  }
  return list;
}

/** 表格顯示用的檔位名稱：首儲檔顯示「首儲」，其餘依序 Lv1、Lv2... */
function rowTierLabel(index: number) {
  const before = tiers.value.slice(0, index + 1);
  if (before.at(-1)?.is_first_deposit) {
    return tl('event_progressive_jackpot_first_deposit_tier');
  }
  return `Lv${before.filter((t) => !t.is_first_deposit).length}`;
}

function applyView(d: EventProgressiveJackpotViewData) {
  const s = d.setting ?? {};
  form.setting_id = s.id ?? 0;
  form.status = Number(s.status ?? 0) === 1;
  form.sort = Number(s.sort ?? 0);
  form.first_deposit_type = Number(s.first_deposit_type ?? 0) || 1;
  form.check_ip = Number(s.ip_block ?? 0) === 1;
  form.check_phone = Number(s.mobile_block ?? 0) === 1;
  form.check_realname = Number(s.realname_block ?? 0) === 1;

  form.agent_id = s.agent_id ?? '';
  if (s.agent_id && s.agent_username) {
    agentOptions.value = [
      { id: Number(s.agent_id), username: String(s.agent_username) },
    ];
  }

  const excludes = d.exclude_agent ?? [];
  form.exclude_agent = excludes.map((r) => r.agent_id);
  // 已選代理不在搜尋結果內時下拉會只顯示 id，先把它們放進選項
  const known = new Set(agentOptions.value.map((o) => o.id));
  for (const r of excludes) {
    if (!known.has(Number(r.agent_id))) {
      agentOptions.value.push({
        id: Number(r.agent_id),
        username: String(r.agent_username ?? ''),
      });
    }
  }

  form.titles = {};
  form.contents = {};
  for (const row of d.title ?? []) {
    const key = String(row.language_id);
    form.titles[key] = row.title ?? '';
    form.contents[key] = row.content ?? '';
  }

  form.images = {};
  for (const row of d.image ?? []) {
    form.images[String(row.language_id)] = row.image ?? '';
  }

  form.game_type_ids = [];
  form.game_type_ratio = {};
  for (const row of d.bet_setting ?? []) {
    form.game_type_ids.push(Number(row.game_type_id));
    // API 存的 rate 是原始比例（0.3 代表 30%），畫面顯示百分比
    form.game_type_ratio[String(row.game_type_id)] = Number(row.rate) * 100;
  }

  tiers.value = sortTiers(
    (d.tier ?? []).map((row) => ({
      deposit_point: Number(row.deposit_point ?? 0),
      expire_day: Number(row.expire_day ?? 0),
      is_first_deposit: Number(row.level) === 0,
      max_reward: Number(row.max_reward ?? 0),
      reward: Number(row.reward ?? 0),
      reward_percentage: Number(row.reward_percentage ?? 0),
      reward_type: Number(row.reward_type ?? 1),
    })),
  );
}

async function loadData() {
  loading.value = true;
  try {
    const res = await getEventProgressiveJackpotViewApi();
    if (Number(res.code) === 1000) {
      applyView(res.data ?? {});
    } else {
      ElMessage.error(res.message ?? '');
    }
  } finally {
    loading.value = false;
  }
}

async function searchAgents(keyword: string) {
  agentSearching.value = true;
  try {
    const res = await searchAgentOptionsApi(keyword, AGENT_SEARCH_LIMIT);
    agentOptions.value = res.data ?? [];
  } finally {
    agentSearching.value = false;
  }
}

function uploadBanner(base64: string) {
  return uploadEventProgressiveJackpotImageApi({
    agent_id: uploadAgentId.value,
    base64Image: base64,
  });
}

/** 寫入端點回應一致：1000 成功、2002 把訊息放在 data */
function reportResult(res: {
  code: number | string;
  data?: unknown;
  message?: string;
}) {
  const code = String(res.code);
  if (code === '1000') {
    ElMessage.success(tl('save_success'));
    return true;
  }
  ElMessage.error(
    code === '2002' ? String(res.data ?? '') : (res.message ?? ''),
  );
  return false;
}

async function saveTitle() {
  const title: EventProgressiveJackpotTitleRow[] = [];
  for (const lang of tabLanguages.value) {
    const key = String(lang.id);
    const t = (form.titles[key] ?? '').trim();
    const c = (form.contents[key] ?? '').trim();
    if (!t || !c) continue;
    title.push({ content: c, language_id: lang.id, title: t });
  }
  savingTitle.value = true;
  try {
    if (reportResult(await updateEventProgressiveJackpotTitleApi(title))) {
      await loadData();
    }
  } finally {
    savingTitle.value = false;
  }
}

async function saveBanner() {
  const image: EventProgressiveJackpotImageRow[] = [];
  for (const lang of tabLanguages.value) {
    const key = String(lang.id);
    const url = (form.images[key] ?? '').trim();
    if (!url) continue;
    image.push({ image: url, language_id: lang.id });
  }
  savingBanner.value = true;
  try {
    const res = await updateEventProgressiveJackpotImageApi({
      image,
      sort: form.sort,
    });
    if (reportResult(res)) await loadData();
  } finally {
    savingBanner.value = false;
  }
}

async function saveSetting() {
  savingSetting.value = true;
  try {
    const res = await updateEventProgressiveJackpotSettingApi({
      agent_id: form.agent_id,
      exclude_agent: form.exclude_agent,
      first_deposit_type: form.first_deposit_type,
      id: form.setting_id,
      ip_block: form.check_ip ? 1 : 0,
      mobile_block: form.check_phone ? 1 : 0,
      realname_block: form.check_realname ? 1 : 0,
      status: form.status ? 1 : 0,
    });
    if (reportResult(res)) await loadData();
  } finally {
    savingSetting.value = false;
  }
}

async function saveBetSetting() {
  savingBetSetting.value = true;
  try {
    const res = await updateEventProgressiveJackpotBetSettingApi(
      form.game_type_ids.map((id) => ({
        game_type_id: id,
        rate: Number(form.game_type_ratio[String(id)] ?? 0) / 100,
      })),
    );
    if (reportResult(res)) await loadData();
  } finally {
    savingBetSetting.value = false;
  }
}

function openTierDialog(index: number) {
  tierDialog.value?.open(
    index,
    index >= 0 ? tiers.value[index] : undefined,
    tiers.value.some((t, i) => t.is_first_deposit && i !== index),
  );
}

function applyTier(index: number, tier: TierItem) {
  if (index >= 0) {
    tiers.value[index] = tier;
  } else {
    tiers.value.push(tier);
  }
  tiers.value = sortTiers([...tiers.value]);
}

async function removeTier(index: number) {
  try {
    await ElMessageBox.confirm(
      tl('event_progressive_jackpot_delete_tier_confirm'),
      '',
      { type: 'warning' },
    );
  } catch {
    return;
  }
  tiers.value.splice(index, 1);
}

function moveTier(index: number, offset: number) {
  const to = index + offset;
  const from = tiers.value[index];
  const target = tiers.value[to];
  if (!from || !target) return;
  const list = [...tiers.value];
  list[index] = target;
  list[to] = from;
  tiers.value = sortTiers(list);
}

async function saveTierSetting() {
  savingTier.value = true;
  try {
    const res = await updateEventProgressiveJackpotTierSettingApi(tiers.value);
    if (reportResult(res)) await loadData();
  } finally {
    savingTier.value = false;
  }
}

const title = computed(
  () =>
    `${tl('menu_event')} > ${tl('menu_event_progressive_jackpot')} > ${tl('menu_event_progressive_jackpot_setting')}`,
);

onMounted(async () => {
  const [lg, gt] = await Promise.all([
    getLanguagesApi().catch(() => []),
    getEventProgressiveJackpotGameTypesApi().catch(() => []),
  ]);
  languages.value = lg ?? [];
  gameTypes.value = gt ?? [];
  await loadData();
});
</script>

<template>
  <Page :title="title">
    <!-- 活動與文案 -->
    <ElCard v-loading="loading" class="mb-3" shadow="never">
      <div class="mb-3 flex items-center justify-between">
        <span class="font-medium">{{ tl('event_and_content') }}</span>
        <ElButton :loading="savingTitle" type="primary" @click="saveTitle">
          {{ tl('btn_update') }}
        </ElButton>
      </div>
      <ElDivider class="!my-3" />
      <ElTabs v-model="titleTab">
        <ElTabPane
          v-for="lang in tabLanguages"
          :key="lang.id"
          :label="lang.name"
          :name="String(lang.id)"
        >
          <div class="mb-3">
            <div class="text-muted-foreground mb-1 text-xs">
              {{ tl('event_name') }}
            </div>
            <ElInput v-model="form.titles[String(lang.id)]" />
          </div>
          <div>
            <div class="text-muted-foreground mb-1 text-xs">
              {{ tl('event_content') }}
            </div>
            <ElInput
              v-model="form.contents[String(lang.id)]"
              :rows="4"
              type="textarea"
            />
          </div>
        </ElTabPane>
      </ElTabs>
    </ElCard>

    <!-- 活動 Banner -->
    <ElCard v-loading="loading" class="mb-3" shadow="never">
      <div class="mb-3 flex items-center justify-between">
        <span class="font-medium">{{ tl('event') }} Banner</span>
        <ElButton :loading="savingBanner" type="primary" @click="saveBanner">
          {{ tl('btn_update') }}
        </ElButton>
      </div>
      <ElDivider class="!my-3" />
      <div class="mb-3 max-w-xs">
        <div class="text-muted-foreground mb-1 text-xs">{{ tl('sorts') }}</div>
        <ElInput v-model="form.sort" />
      </div>
      <ElTabs v-model="bannerTab">
        <ElTabPane
          v-for="lang in tabLanguages"
          :key="lang.id"
          :label="lang.name"
          :name="String(lang.id)"
        >
          <ImageInput
            v-model="form.images[String(lang.id)]"
            :upload-fn="uploadBanner"
          />
        </ElTabPane>
      </ElTabs>
    </ElCard>

    <!-- 全域設定 -->
    <ElCard v-loading="loading" class="mb-3" shadow="never">
      <div class="mb-3 flex items-center justify-between">
        <span class="font-medium">
          {{ tl('event_progressive_jackpot_global_setting') }}
        </span>
        <ElButton
          v-if="isAdminOrSub"
          :loading="savingSetting"
          type="primary"
          @click="saveSetting"
        >
          {{ tl('btn_update') }}
        </ElButton>
      </div>
      <ElDivider class="!my-3" />
      <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
        <div>
          <div class="text-muted-foreground mb-1 text-xs">
            {{ tl('agent_account') }}
          </div>
          <ElSelect
            v-model="form.agent_id"
            :loading="agentSearching"
            :placeholder="tl('please_choose')"
            :remote-method="searchAgents"
            class="!w-full"
            clearable
            filterable
            remote
            remote-show-suffix
          >
            <ElOption
              v-for="o in agentOptions"
              :key="o.id"
              :label="o.username"
              :value="o.id"
            />
          </ElSelect>
        </div>
        <div>
          <div class="text-muted-foreground mb-1 text-xs">
            {{ tl('exclude_agent') }}
          </div>
          <ElSelect
            v-model="form.exclude_agent"
            :loading="agentSearching"
            :remote-method="searchAgents"
            class="!w-full"
            clearable
            filterable
            multiple
            remote
            remote-show-suffix
          >
            <ElOption
              v-for="o in agentOptions"
              :key="o.id"
              :label="o.username"
              :value="o.id"
            />
          </ElSelect>
        </div>
        <div>
          <div class="text-muted-foreground mb-1 text-xs">
            {{ tl('event_switch') }}
          </div>
          <div class="flex items-center gap-2">
            <ElSwitch v-model="form.status" />
            <span
              :class="form.status ? 'text-green-600' : 'text-muted-foreground'"
            >
              {{ form.status ? tl('active') : tl('disabled') }}
            </span>
          </div>
        </div>
        <div>
          <div class="text-muted-foreground mb-1 text-xs">
            {{ tl('event_progressive_jackpot_first_deposit_type') }}
          </div>
          <ElSelect v-model="form.first_deposit_type" class="!w-full">
            <ElOption
              :label="tl('event_progressive_jackpot_first_deposit_type_1')"
              :value="1"
            />
            <ElOption
              :label="tl('event_progressive_jackpot_first_deposit_type_2')"
              :value="2"
            />
          </ElSelect>
        </div>
      </div>

      <div class="mt-6 mb-3 font-medium">{{ tl('duplicate_check_rule') }}</div>
      <ElDivider class="!my-3" />
      <div class="text-muted-foreground mb-2 text-xs">
        {{ tl('duplicate_check_fields') }}
      </div>
      <div class="grid grid-cols-1 gap-2 md:grid-cols-3">
        <ElCheckbox v-model="form.check_ip" :label="tl('register_ip')" />
        <ElCheckbox v-model="form.check_phone" :label="tl('mobile')" />
        <ElCheckbox v-model="form.check_realname" :label="tl('realname')" />
      </div>
    </ElCard>

    <!-- 指定參與遊戲與解鎖比例 -->
    <ElCard v-loading="loading" class="mb-3" shadow="never">
      <div class="mb-3 flex items-center justify-between">
        <span class="font-medium">
          {{ tl('event_progressive_jackpot_bet_setting') }}
        </span>
        <ElButton
          v-if="isAdminOrSub"
          :loading="savingBetSetting"
          type="primary"
          @click="saveBetSetting"
        >
          {{ tl('btn_update') }}
        </ElButton>
      </div>
      <ElDivider class="!my-3" />
      <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div>
          <div class="text-muted-foreground mb-1 text-xs">
            {{ tl('event_progressive_jackpot_bet_setting') }}
          </div>
          <ElSelect
            v-model="form.game_type_ids"
            class="!w-full"
            clearable
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
        </div>
        <div>
          <div class="text-muted-foreground mb-1 text-xs">
            {{ tl('event_progressive_jackpot_bet_ratio') }}
          </div>
          <div class="grid grid-cols-2 gap-3 lg:grid-cols-3">
            <div v-for="g in selectedGameTypes" :key="g.id">
              <div class="text-muted-foreground mb-1 text-xs">
                {{ g.name }}
              </div>
              <ElInput
                v-model="form.game_type_ratio[String(g.id)]"
                class="text-center"
              >
                <template #append>%</template>
              </ElInput>
            </div>
          </div>
        </div>
      </div>
    </ElCard>

    <!-- 檔位設定 -->
    <ElCard v-loading="loading" shadow="never">
      <div class="mb-3 flex items-center justify-between">
        <span class="font-medium">
          {{ tl('event_progressive_jackpot_tier_setting') }}
        </span>
        <div v-if="isAdminOrSub" class="flex gap-2">
          <ElButton class="!ml-0" type="success" @click="openTierDialog(-1)">
            {{ tl('event_progressive_jackpot_add_tier') }}
          </ElButton>
          <ElButton
            :loading="savingTier"
            class="!ml-0"
            type="primary"
            @click="saveTierSetting"
          >
            {{ tl('btn_update') }}
          </ElButton>
        </div>
      </div>
      <ElDivider class="!my-3" />
      <ElTable :data="tiers" border size="small" stripe>
        <ElTableColumn :label="tl('sorts')" width="70">
          <template #default="{ $index }">{{ $index + 1 }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('level')" width="90">
          <template #default="{ $index }">{{ rowTierLabel($index) }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('event_progressive_jackpot_deposit_rule')"
          width="120"
        >
          <template #default="{ row }">
            <ElTag :type="row.is_first_deposit ? 'primary' : 'info'">
              {{
                row.is_first_deposit
                  ? tl('event_action.first_deposit')
                  : tl('event_progressive_jackpot_deposit_rule_accumulate')
              }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('event_progressive_jackpot_threshold')"
          min-width="120"
        >
          <template #default="{ row }">
            {{
              row.is_first_deposit
                ? tl('event_progressive_jackpot_first_deposit_now')
                : `≥ ${row.deposit_point}`
            }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('jackpot')" min-width="160">
          <template #default="{ row }">
            {{
              Number(row.reward_type) === 1
                ? `${tl('event_progressive_jackpot_reward_fixed_prefix')}${row.reward}`
                : `${row.reward_percentage * 100}${tl('event_progressive_jackpot_max_reward_prefix')}${row.max_reward}`
            }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('event_progressive_jackpot_time_limit')"
          width="110"
        >
          <template #default="{ row }">
            <ElTag v-if="!row.expire_day" type="success">
              {{ tl('event_progressive_jackpot_no_limit') }}
            </ElTag>
            <span v-else>
              {{ row.expire_day
              }}{{ tl('event_progressive_jackpot_day_suffix') }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          v-if="isAdminOrSub"
          :label="tl('operate')"
          align="left"
          width="230"
        >
          <template #default="{ row, $index }">
            <div class="flex flex-wrap items-center gap-2">
              <ElButton
                v-if="!row.is_first_deposit"
                :disabled="$index === 0"
                class="!ml-0"
                size="small"
                @click="moveTier($index, -1)"
              >
                ↑
              </ElButton>
              <ElButton
                v-if="!row.is_first_deposit"
                :disabled="$index === tiers.length - 1"
                class="!ml-0"
                size="small"
                @click="moveTier($index, 1)"
              >
                ↓
              </ElButton>
              <ElButton
                class="!ml-0"
                size="small"
                @click="openTierDialog($index)"
              >
                {{ tl('edit') }}
              </ElButton>
              <ElButton
                class="!ml-0"
                size="small"
                type="danger"
                @click="removeTier($index)"
              >
                {{ tl('delete') }}
              </ElButton>
            </div>
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
    </ElCard>

    <TierEditDialog ref="tierDialog" @confirm="applyTier" />
  </Page>
</template>
