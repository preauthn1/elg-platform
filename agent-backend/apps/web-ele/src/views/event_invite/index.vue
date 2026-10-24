<script lang="ts" setup>
import type {
  EventInviteImageRow,
  EventInviteTitleRow,
  EventInviteViewData,
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
  ElOption,
  ElSelect,
  ElSwitch,
  ElTabPane,
  ElTabs,
} from 'element-plus';

import {
  AGENT_SEARCH_LIMIT,
  getEventInviteGameTypesApi,
  getEventInviteViewApi,
  getLanguagesApi,
  getVendorListApi,
  searchAgentOptionsApi,
  updateEventInviteBetSettingApi,
  updateEventInviteImageApi,
  updateEventInviteSettingApi,
  updateEventInviteTitleApi,
  uploadEventInviteImageApi,
} from '#/api';
import ImageInput from '#/components/image-input.vue';
import { tl } from '#/locales';

defineOptions({ name: 'EventInviteSetting' });

/** 預設語系在後端以 language_id 0 表示 */
const DEFAULT_LANGUAGE_ID = 0;

const userStore = useUserStore();
/** 獎勵設定與投注設定只有 Admin(id=1) 或其子帳號(host_id=1) 可儲存 */
const isAdminOrSub = computed(() => {
  const u = userStore.userInfo as any;
  return Number(u?.id) === 1 || Number(u?.host_id) === 1;
});
const uploadAgentId = computed(
  () => Number((userStore.userInfo as any)?.id ?? 0) || 0,
);

const languages = ref<{ id: number; name: string }[]>([]);
const vendors = ref<{ id: number; name: string }[]>([]);
const gameTypes = ref<{ id: number; name: string }[]>([]);
const agentOptions = ref<{ id: number; username: string }[]>([]);
const agentSearching = ref(false);

const titleTab = ref(String(DEFAULT_LANGUAGE_ID));
const bannerTab = ref(String(DEFAULT_LANGUAGE_ID));

const form = reactive({
  agent_id: '' as number | string,
  bet_unlock_amount_per_interval: '' as number | string,
  bet_unlock_interval: '' as number | string,
  check_ip: false,
  check_phone: false,
  check_realname: false,
  compare_scope: 1 as number | string,
  contents: {} as Record<string, string>,
  deposit_threshold: '' as number | string,
  deposit_unlock_amount: '' as number | string,
  exclude_agent: [] as (number | string)[],
  exclude_game_type_ids: [] as (number | string)[],
  exclude_vendor_ids: [] as (number | string)[],
  images: {} as Record<string, string>,
  kyc_unlock_amount: '' as number | string,
  reward_total: '' as number | string,
  setting_id: 0 as number | string,
  sort: 0 as number | string,
  status: false,
  titles: {} as Record<string, string>,
  vendor_ids: [] as (number | string)[],
});

const loading = ref(false);
const savingTitle = ref(false);
const savingBanner = ref(false);
const savingSetting = ref(false);
const savingBetSetting = ref(false);

/** 語系分頁：預設語系 + api/languages */
const tabLanguages = computed(() => [
  { id: DEFAULT_LANGUAGE_ID, name: tl('default') },
  ...languages.value,
]);

function applyView(d: EventInviteViewData) {
  const s = d.setting ?? {};
  form.setting_id = s.id ?? 0;
  form.status = Number(s.status ?? 0) === 1;
  form.sort = Number(s.sort ?? 0);
  form.reward_total = Number(s.max_reward ?? 0);
  form.kyc_unlock_amount = Number(s.realname_reward ?? 0);
  form.deposit_threshold = Number(s.deposit_point ?? 0);
  form.deposit_unlock_amount = Number(s.deposit_reward ?? 0);
  form.bet_unlock_interval = Number(s.bet_point ?? 0);
  form.bet_unlock_amount_per_interval = Number(s.bet_reward ?? 0);
  form.check_ip = Number(s.ip_block ?? 0) === 1;
  form.check_phone = Number(s.mobile_block ?? 0) === 1;
  form.check_realname = Number(s.realname_block ?? 0) === 1;
  form.compare_scope = Number(s.block_type ?? 1);

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

  form.vendor_ids = d.bet_setting?.vendor ?? [];
  form.exclude_game_type_ids = d.bet_setting?.game_type ?? [];
  form.exclude_vendor_ids = d.bet_setting?.exclude_vendor ?? [];
}

async function loadData() {
  loading.value = true;
  try {
    const res = await getEventInviteViewApi();
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
  return uploadEventInviteImageApi({
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
  const title: EventInviteTitleRow[] = [];
  for (const lang of tabLanguages.value) {
    const key = String(lang.id);
    const t = (form.titles[key] ?? '').trim();
    const c = (form.contents[key] ?? '').trim();
    if (!t || !c) continue;
    title.push({ content: c, language_id: lang.id, title: t });
  }
  savingTitle.value = true;
  try {
    if (reportResult(await updateEventInviteTitleApi(title))) await loadData();
  } finally {
    savingTitle.value = false;
  }
}

async function saveBanner() {
  const image: EventInviteImageRow[] = [];
  for (const lang of tabLanguages.value) {
    const key = String(lang.id);
    const url = (form.images[key] ?? '').trim();
    if (!url) continue;
    image.push({ image: url, language_id: lang.id });
  }
  savingBanner.value = true;
  try {
    const res = await updateEventInviteImageApi({ image, sort: form.sort });
    if (reportResult(res)) await loadData();
  } finally {
    savingBanner.value = false;
  }
}

async function saveSetting() {
  savingSetting.value = true;
  try {
    const res = await updateEventInviteSettingApi({
      agent_id: form.agent_id,
      bet_point: form.bet_unlock_interval,
      bet_reward: form.bet_unlock_amount_per_interval,
      block_type: form.compare_scope,
      deposit_point: form.deposit_threshold,
      deposit_reward: form.deposit_unlock_amount,
      exclude_agent: form.exclude_agent,
      id: form.setting_id,
      ip_block: form.check_ip ? 1 : 0,
      max_reward: form.reward_total,
      mobile_block: form.check_phone ? 1 : 0,
      realname_block: form.check_realname ? 1 : 0,
      realname_reward: form.kyc_unlock_amount,
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
    const res = await updateEventInviteBetSettingApi({
      exclude_vendor: form.exclude_vendor_ids,
      game_type: form.exclude_game_type_ids,
      vendor: form.vendor_ids,
    });
    if (reportResult(res)) await loadData();
  } finally {
    savingBetSetting.value = false;
  }
}

const title = computed(
  () =>
    `${tl('menu_event')} > ${tl('menu_event_invite')} > ${tl('menu_event_invite_setting')}`,
);

onMounted(async () => {
  const [lg, vd, gt] = await Promise.all([
    getLanguagesApi().catch(() => []),
    getVendorListApi().catch(() => []),
    getEventInviteGameTypesApi().catch(() => []),
  ]);
  languages.value = lg ?? [];
  vendors.value = vd ?? [];
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

    <!-- 獎勵設定 / 解鎖規則 / 重複偵測規則 -->
    <ElCard v-loading="loading" class="mb-3" shadow="never">
      <div class="mb-3 flex items-center justify-between">
        <span class="font-medium">{{ tl('bind_reward_reward_setting') }}</span>
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
            {{ tl('reward_total_per_friend') }}
          </div>
          <ElInput v-model="form.reward_total">
            <template #prepend>RM</template>
          </ElInput>
        </div>
      </div>

      <div class="mt-6 mb-3 font-medium">{{ tl('unlock_rule') }}</div>
      <ElDivider class="!my-3" />
      <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
        <div>
          <div class="text-muted-foreground mb-1 text-xs">
            {{ tl('kyc_unlock_amount') }}
          </div>
          <ElInput v-model="form.kyc_unlock_amount">
            <template #prepend>RM</template>
          </ElInput>
        </div>
        <div>
          <div class="text-muted-foreground mb-1 text-xs">
            {{ tl('deposit_threshold') }}
          </div>
          <ElInput v-model="form.deposit_threshold">
            <template #prepend>
              {{ tl('deposit_threshold_unit') }} RM
            </template>
          </ElInput>
        </div>
        <div>
          <div class="text-muted-foreground mb-1 text-xs">
            {{ tl('deposit_unlock_amount') }}
          </div>
          <ElInput v-model="form.deposit_unlock_amount">
            <template #prepend>RM</template>
          </ElInput>
        </div>
        <div>
          <div class="text-muted-foreground mb-1 text-xs">
            {{ tl('bet_unlock_interval') }}
          </div>
          <ElInput v-model="form.bet_unlock_interval">
            <template #prepend>{{ tl('bet_per_amount_unit') }} RM</template>
          </ElInput>
        </div>
        <div>
          <div class="text-muted-foreground mb-1 text-xs">
            {{ tl('bet_unlock_amount_per_interval') }}
          </div>
          <ElInput v-model="form.bet_unlock_amount_per_interval">
            <template #prepend>RM</template>
          </ElInput>
        </div>
      </div>

      <div class="mt-6 mb-3 font-medium">{{ tl('duplicate_check_rule') }}</div>
      <ElDivider class="!my-3" />
      <div class="text-muted-foreground mb-2 text-xs">
        {{ tl('duplicate_check_fields') }}
      </div>
      <div class="mb-4 grid grid-cols-1 gap-2 md:grid-cols-3">
        <ElCheckbox v-model="form.check_ip" :label="tl('register_ip')" />
        <ElCheckbox v-model="form.check_phone" :label="tl('mobile')" />
        <ElCheckbox v-model="form.check_realname" :label="tl('realname')" />
      </div>
      <div class="max-w-sm">
        <div class="text-muted-foreground mb-1 text-xs">
          {{ tl('compare_scope') }}
        </div>
        <ElSelect v-model="form.compare_scope" class="!w-full">
          <ElOption :label="tl('compare_scope_whole_site')" :value="1" />
          <ElOption :label="tl('compare_scope_this_event')" :value="2" />
        </ElSelect>
      </div>
    </ElCard>

    <!-- 有效投注遊戲範圍 -->
    <ElCard v-loading="loading" shadow="never">
      <div class="mb-3 flex items-center justify-between">
        <span class="font-medium">{{ tl('valid_bet_scope') }}</span>
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
      <div v-if="form.exclude_vendor_ids.length === 0" class="mb-4">
        <div class="text-muted-foreground mb-1 text-xs">
          {{ tl('participating_vendor') }}
        </div>
        <ElSelect
          v-model="form.vendor_ids"
          class="!w-full"
          clearable
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
      </div>
      <!-- 排除遊戲商（target/tickets#4504）：與參與遊戲商擇一，任一邊有值就隱藏另一邊 -->
      <div v-if="form.vendor_ids.length === 0" class="mb-4">
        <div class="text-muted-foreground mb-1 text-xs">
          {{ tl('exclude_vendor') }}
        </div>
        <ElSelect
          v-model="form.exclude_vendor_ids"
          class="!w-full"
          clearable
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
      </div>
      <div>
        <div class="text-muted-foreground mb-1 text-xs">
          {{ tl('exclude_game_type') }}
        </div>
        <ElSelect
          v-model="form.exclude_game_type_ids"
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
    </ElCard>
  </Page>
</template>
