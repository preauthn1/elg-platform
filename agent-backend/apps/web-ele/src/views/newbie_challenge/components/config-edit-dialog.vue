<script lang="ts" setup>
import type {
  NewbieChallengeConfigLang,
  NewbieChallengeConfigPayload,
} from '#/api';

import { computed, reactive, ref } from 'vue';

import { useUserStore } from '@vben/stores';

import {
  ElButton,
  ElDatePicker,
  ElDialog,
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
  AGENT_SEARCH_LIMIT,
  createNewbieChallengeConfigApi,
  getAgentViewByIdApi,
  getGameTypeManageListApi,
  getLanguagesApi,
  getNewbieChallengeConfigDetailApi,
  getVendorListApi,
  searchAgentOptionsApi,
  updateNewbieChallengeConfigApi,
  uploadAdvertiseImageApi,
} from '#/api';
import ImageInput from '#/components/image-input.vue';
import { tl } from '#/locales';

defineOptions({ name: 'NewbieChallengeConfigEditDialog' });

const emit = defineEmits<{ saved: [] }>();

const userStore = useUserStore();
const uploadAgentId = computed(
  () => Number((userStore.userInfo as any)?.id ?? 0) || 0,
);
/** 活動金額、流水倍數、遊戲商限制等設定只有 Admin(id=1) 或其子帳號(host_id=1) 可編輯，
 * 其餘代理即使有選單權限也只能改文案與圖片，後端 configUpdate() 會忽略這些欄位的提交值 */
const isAdminOrSub = computed(() => {
  const u = userStore.userInfo as any;
  return Number(u?.id) === 1 || Number(u?.host_id) === 1;
});

const visible = ref(false);
const loading = ref(false);
const saving = ref(false);
const configId = ref<number | string>('');

const languages = ref<{ id: number; name: string }[]>([]);
const vendors = ref<{ id: number; is_free?: number; name: string }[]>([]);
// 第一階段（挑戰流水）限定免盤費遊戲商，避免混入一般遊戲商後流水判斷失準（見SP_calc_flow_limit.sql的vendors.is_free判斷）
const freeVendors = computed(() => vendors.value.filter((v) => !!v.is_free));
const gameTypes = ref<{ id: number; name: string }[]>([]);
const agentOptions = ref<{ id: number; username: string }[]>([]);
const agentSearching = ref(false);
const langTab = ref('');

const form = reactive({
  account_block: 1,
  activity_amount: '' as number | string,
  agent_id: '' as number | string,
  allowed_vendor_ids: [] as number[],
  allowed_game_type_ids: [] as number[],
  excluded_vendor_ids: [] as number[],
  bonus_flow_multiple: '' as number | string,
  bonus_flow_vendor_ids: [] as number[],
  bonus_flow_game_type_ids: [] as number[],
  excluded_bonus_flow_vendor_ids: [] as number[],
  bonus_window_hours: '' as number | string,
  challenge_flow_multiple: '' as number | string,
  contents: {} as Record<string, string>,
  end_time: '',
  exclude_agent: [] as number[],
  images: {} as Record<string, string>,
  ip_block: 1,
  max_unlock_amount: '' as number | string,
  min_unlock_amount: '' as number | string,
  names: {} as Record<string, string>,
  phone_block: 1,
  restrict_new_member: 1,
  sort: 0 as number | string,
  start_time: '',
  status: 1,
});

// 限制/排除互斥：限制有值就只顯示限制欄位，排除有值就只顯示排除欄位，兩者皆空則都顯示讓管理員擇一填
const allowedVendorMode = computed(() => {
  if (form.allowed_vendor_ids.length > 0) return 'allowed';
  if (form.excluded_vendor_ids.length > 0) return 'excluded';
  return 'both';
});
const bonusFlowVendorMode = computed(() => {
  if (form.bonus_flow_vendor_ids.length > 0) return 'allowed';
  if (form.excluded_bonus_flow_vendor_ids.length > 0) return 'excluded';
  return 'both';
});

function resetForm() {
  form.account_block = 1;
  form.activity_amount = '';
  form.agent_id = '';
  form.allowed_vendor_ids = [];
  form.allowed_game_type_ids = [];
  form.excluded_vendor_ids = [];
  form.bonus_flow_multiple = '';
  form.bonus_flow_vendor_ids = [];
  form.bonus_flow_game_type_ids = [];
  form.excluded_bonus_flow_vendor_ids = [];
  form.bonus_window_hours = '';
  form.challenge_flow_multiple = '';
  form.contents = {};
  form.end_time = '';
  form.exclude_agent = [];
  form.images = {};
  form.ip_block = 1;
  form.max_unlock_amount = '';
  form.min_unlock_amount = '';
  form.names = {};
  form.phone_block = 1;
  form.restrict_new_member = 1;
  form.sort = 0;
  form.start_time = '';
  form.status = 1;
  agentOptions.value = [];
}

async function loadDetail(id: number | string) {
  loading.value = true;
  try {
    const res = await getNewbieChallengeConfigDetailApi(id);
    if (Number(res.code) !== 200) {
      ElMessage.error(res.message ?? '');
      return;
    }
    const d = res.data ?? { id: 0 };
    form.agent_id =
      d.agent_id === null || d.agent_id === undefined ? '' : Number(d.agent_id);
    form.activity_amount = d.activity_amount ?? '';
    form.challenge_flow_multiple = d.challenge_flow_multiple ?? '';
    form.bonus_flow_multiple = d.bonus_flow_multiple ?? '';
    form.min_unlock_amount = d.min_unlock_amount ?? '';
    form.max_unlock_amount = d.max_unlock_amount ?? '';
    form.bonus_window_hours = d.bonus_window_hours ?? '';
    form.allowed_vendor_ids = (d.allowed_vendor_ids ?? []).map(Number);
    form.allowed_game_type_ids = (d.allowed_game_type_ids ?? []).map(Number);
    form.excluded_vendor_ids = (d.excluded_vendor_ids ?? []).map(Number);
    form.bonus_flow_vendor_ids = (d.bonus_flow_vendor_ids ?? []).map(Number);
    form.bonus_flow_game_type_ids = (d.bonus_flow_game_type_ids ?? []).map(
      Number,
    );
    form.excluded_bonus_flow_vendor_ids = (
      d.excluded_bonus_flow_vendor_ids ?? []
    ).map(Number);
    form.ip_block = Number(d.ip_block ?? 1);
    form.phone_block = Number(d.phone_block ?? 1);
    form.account_block = Number(d.account_block ?? 1);
    form.restrict_new_member = Number(d.restrict_new_member ?? 1);
    form.sort = d.sort ?? 0;
    form.status = Number(d.status ?? 1);
    // 後端給到秒，畫面只編到分
    form.start_time = (d.start_time ?? '').slice(0, 16);
    form.end_time = (d.end_time ?? '').slice(0, 16);

    const excludes = d.exclude_agent ?? [];
    form.exclude_agent = excludes.map((r) => Number(r.id));
    agentOptions.value = excludes.map((r) => ({
      id: Number(r.id),
      username: r.agent_username,
    }));
    // config-detail 只回 agent_id，沒有帳號名就配不到下拉選項，會直接顯示 id
    if (form.agent_id !== '') {
      const agent = await getAgentViewByIdApi(Number(form.agent_id)).catch(
        () => null,
      );
      if (agent?.username) {
        agentOptions.value = [
          { id: Number(form.agent_id), username: agent.username },
          ...agentOptions.value.filter((o) => o.id !== Number(form.agent_id)),
        ];
      }
    }

    for (const row of d.config_lang ?? []) {
      const key = String(row.language_id);
      form.names[key] = row.name ?? '';
      form.contents[key] = row.content ?? '';
      form.images[key] = row.image ?? '';
    }
  } finally {
    loading.value = false;
  }
}

async function open(id?: number | string) {
  resetForm();
  configId.value = id ?? '';
  visible.value = true;
  const [lg, vd, gt] = await Promise.all([
    getLanguagesApi().catch(() => []),
    getVendorListApi().catch(() => []),
    getGameTypeManageListApi().catch(() => []),
  ]);
  languages.value = lg ?? [];
  vendors.value = vd ?? [];
  gameTypes.value = (gt ?? []).map((g) => ({
    id: Number(g.id),
    name: g.name ?? '',
  }));
  langTab.value = String(languages.value[0]?.id ?? '');
  if (id) await loadDetail(id);
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

function uploadImage(base64: string) {
  return uploadAdvertiseImageApi({
    agent_id: uploadAgentId.value,
    base64Image: base64,
  }).then((res) => (Number(res.code) === 1000 ? (res.data?.path ?? '') : ''));
}

async function save() {
  const configLang: NewbieChallengeConfigLang[] = languages.value.map((l) => ({
    content: form.contents[String(l.id)] ?? '',
    image: form.images[String(l.id)] ?? '',
    language_id: l.id,
    name: form.names[String(l.id)] ?? '',
  }));
  const payload: NewbieChallengeConfigPayload = {
    account_block: form.account_block,
    activity_amount: form.activity_amount,
    agent_id: form.agent_id === '' ? null : Number(form.agent_id),
    allowed_vendor_ids:
      form.allowed_vendor_ids.length > 0 ? form.allowed_vendor_ids : null,
    allowed_game_type_ids:
      form.allowed_game_type_ids.length > 0 ? form.allowed_game_type_ids : null,
    excluded_vendor_ids:
      form.excluded_vendor_ids.length > 0 ? form.excluded_vendor_ids : null,
    bonus_flow_multiple: form.bonus_flow_multiple,
    bonus_flow_vendor_ids:
      form.bonus_flow_vendor_ids.length > 0 ? form.bonus_flow_vendor_ids : null,
    bonus_flow_game_type_ids:
      form.bonus_flow_game_type_ids.length > 0
        ? form.bonus_flow_game_type_ids
        : null,
    excluded_bonus_flow_vendor_ids:
      form.excluded_bonus_flow_vendor_ids.length > 0
        ? form.excluded_bonus_flow_vendor_ids
        : null,
    bonus_window_hours: form.bonus_window_hours,
    challenge_flow_multiple: form.challenge_flow_multiple,
    config_lang: configLang,
    end_time: form.end_time ? `${form.end_time}:59` : null,
    exclude_agent: form.exclude_agent,
    ip_block: form.ip_block,
    max_unlock_amount: form.max_unlock_amount,
    min_unlock_amount: form.min_unlock_amount,
    phone_block: form.phone_block,
    restrict_new_member: form.restrict_new_member,
    sort: form.sort === '' ? 0 : form.sort,
    start_time: form.start_time ? `${form.start_time}:00` : null,
    status: form.status,
  };

  saving.value = true;
  try {
    const res = configId.value
      ? await updateNewbieChallengeConfigApi({
          ...payload,
          config_id: configId.value,
        })
      : await createNewbieChallengeConfigApi(payload);
    const code = String(res.code);
    if (code === '200') {
      ElMessage.success(tl('save_success'));
      visible.value = false;
      emit('saved');
      return;
    }
    ElMessage.error(
      code === '400' ? (res.message ?? '') : String(res.data ?? ''),
    );
  } finally {
    saving.value = false;
  }
}

const title = computed(() =>
  configId.value ? tl('edit') : tl('event_create'),
);

defineExpose({ open });
</script>

<template>
  <ElDialog v-model="visible" :title="title" width="1000" align-center>
    <div v-loading="loading">
      <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
        <div>
          <div class="text-muted-foreground mb-1 text-xs">
            {{ tl('agent_account') }}（{{ tl('newbie_challenge_agent_hint') }}）
          </div>
          <ElSelect
            v-model="form.agent_id"
            :disabled="!isAdminOrSub"
            :loading="agentSearching"
            :placeholder="tl('newbie_challenge_whole_site')"
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
            :disabled="!isAdminOrSub"
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
            {{ tl('label_start_time') }}
          </div>
          <ElDatePicker
            v-model="form.start_time"
            :disabled="!isAdminOrSub"
            class="!w-full"
            format="YYYY-MM-DD HH:mm"
            type="datetime"
            value-format="YYYY-MM-DD HH:mm"
          />
        </div>
        <div>
          <div class="text-muted-foreground mb-1 text-xs">
            {{ tl('label_end_time') }}
          </div>
          <ElDatePicker
            v-model="form.end_time"
            :disabled="!isAdminOrSub"
            class="!w-full"
            format="YYYY-MM-DD HH:mm"
            type="datetime"
            value-format="YYYY-MM-DD HH:mm"
          />
        </div>
        <div>
          <div class="text-muted-foreground mb-1 text-xs">
            {{ tl('newbie_challenge_sort') }}
          </div>
          <ElInput v-model="form.sort" :disabled="!isAdminOrSub" />
        </div>
        <div>
          <div class="text-muted-foreground mb-1 text-xs">
            <span class="text-red-500">*</span>
            {{ tl('newbie_challenge_activity_amount') }}
          </div>
          <ElInput v-model="form.activity_amount" :disabled="!isAdminOrSub" />
        </div>
        <div>
          <div class="text-muted-foreground mb-1 text-xs">
            <span class="text-red-500">*</span>
            {{ tl('newbie_challenge_challenge_flow_multiple') }}
          </div>
          <ElInput
            v-model="form.challenge_flow_multiple"
            :disabled="!isAdminOrSub"
          />
        </div>
      </div>

      <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
        <div>
          <div class="text-muted-foreground mb-1 text-xs">
            <span class="text-red-500">*</span>
            {{ tl('newbie_challenge_bonus_flow_multiple') }}
          </div>
          <ElInput
            v-model="form.bonus_flow_multiple"
            :disabled="!isAdminOrSub"
          />
        </div>
        <div>
          <div class="text-muted-foreground mb-1 text-xs">
            <span class="text-red-500">*</span>
            {{ tl('newbie_challenge_bonus_window_hours') }}
          </div>
          <ElInput
            v-model="form.bonus_window_hours"
            :disabled="!isAdminOrSub"
          />
        </div>
        <div>
          <div class="text-muted-foreground mb-1 text-xs">
            <span class="text-red-500">*</span>
            {{ tl('newbie_challenge_min_unlock_amount') }}
          </div>
          <ElInput v-model="form.min_unlock_amount" :disabled="!isAdminOrSub" />
        </div>
        <div>
          <div class="text-muted-foreground mb-1 text-xs">
            <span class="text-red-500">*</span>
            {{ tl('newbie_challenge_max_unlock_amount') }}
          </div>
          <ElInput v-model="form.max_unlock_amount" :disabled="!isAdminOrSub" />
        </div>
      </div>

      <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
        <div>
          <div v-if="allowedVendorMode !== 'excluded'">
            <div class="text-muted-foreground mb-1 text-xs">
              {{ tl('newbie_challenge_allowed_vendor') }}
            </div>
            <ElSelect
              v-model="form.allowed_vendor_ids"
              :disabled="!isAdminOrSub"
              class="!w-full"
              clearable
              filterable
              multiple
            >
              <ElOption
                v-for="v in freeVendors"
                :key="v.id"
                :label="v.name"
                :value="v.id"
              />
            </ElSelect>
            <div class="text-muted-foreground mt-1 text-xs">
              只能選免盤費遊戲商，沒設定就是不限制遊戲商；與下方排除遊戲商擇一設定
            </div>
          </div>
          <div v-if="allowedVendorMode !== 'allowed'" class="mt-4">
            <div class="text-muted-foreground mb-1 text-xs">
              第一階段排除遊戲商
            </div>
            <ElSelect
              v-model="form.excluded_vendor_ids"
              :disabled="!isAdminOrSub"
              class="!w-full"
              clearable
              filterable
              multiple
            >
              <ElOption
                v-for="v in freeVendors"
                :key="v.id"
                :label="v.name"
                :value="v.id"
              />
            </ElSelect>
            <div class="text-muted-foreground mt-1 text-xs">
              除了這裡選的，其餘免盤費遊戲商都算流水；沒設定就是不排除
            </div>
          </div>
        </div>
        <div>
          <div v-if="bonusFlowVendorMode !== 'excluded'">
            <div class="text-muted-foreground mb-1 text-xs">
              {{ tl('newbie_challenge_bonus_flow_vendor') }}
            </div>
            <ElSelect
              v-model="form.bonus_flow_vendor_ids"
              :disabled="!isAdminOrSub"
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
            <div class="text-muted-foreground mt-1 text-xs">
              {{
                tl('newbie_challenge_bonus_flow_vendor_hint')
              }}；與下方排除遊戲商擇一設定
            </div>
          </div>
          <div v-if="bonusFlowVendorMode !== 'allowed'" class="mt-4">
            <div class="text-muted-foreground mb-1 text-xs">
              第二階段排除遊戲商
            </div>
            <ElSelect
              v-model="form.excluded_bonus_flow_vendor_ids"
              :disabled="!isAdminOrSub"
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
            <div class="text-muted-foreground mt-1 text-xs">
              除了這裡選的，其餘遊戲商都算流水；沒設定就是不排除
            </div>
          </div>
        </div>
      </div>

      <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
        <div>
          <div class="text-muted-foreground mb-1 text-xs">
            第一階段限制遊戲類型
          </div>
          <ElSelect
            v-model="form.allowed_game_type_ids"
            :disabled="!isAdminOrSub"
            class="!w-full"
            clearable
            filterable
            multiple
          >
            <ElOption
              v-for="gt in gameTypes"
              :key="gt.id"
              :label="gt.name"
              :value="gt.id"
            />
          </ElSelect>
          <div class="text-muted-foreground mt-1 text-xs">
            沒設定就是不限制遊戲類型
          </div>
        </div>
        <div>
          <div class="text-muted-foreground mb-1 text-xs">
            第二階段限制遊戲類型
          </div>
          <ElSelect
            v-model="form.bonus_flow_game_type_ids"
            :disabled="!isAdminOrSub"
            class="!w-full"
            clearable
            filterable
            multiple
          >
            <ElOption
              v-for="gt in gameTypes"
              :key="gt.id"
              :label="gt.name"
              :value="gt.id"
            />
          </ElSelect>
          <div class="text-muted-foreground mt-1 text-xs">
            沒設定就是不限制遊戲類型
          </div>
        </div>
      </div>

      <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
        <div>
          <div class="text-muted-foreground mb-1 text-xs">
            {{ tl('newbie_challenge_restrict_new_member') }}
          </div>
          <ElRadioGroup
            v-model="form.restrict_new_member"
            :disabled="!isAdminOrSub"
          >
            <ElRadio :value="1">{{ tl('enable') }}</ElRadio>
            <ElRadio :value="0">{{ tl('disable') }}</ElRadio>
          </ElRadioGroup>
        </div>
        <div>
          <div class="text-muted-foreground mb-1 text-xs">
            {{ tl('newbie_challenge_status') }}
          </div>
          <ElRadioGroup v-model="form.status" :disabled="!isAdminOrSub">
            <ElRadio :value="1">{{ tl('enable') }}</ElRadio>
            <ElRadio :value="0">{{ tl('disable') }}</ElRadio>
          </ElRadioGroup>
        </div>
        <div>
          <div class="text-muted-foreground mb-1 text-xs">
            {{ tl('block_duplicate_ip') }}
          </div>
          <ElRadioGroup v-model="form.ip_block" :disabled="!isAdminOrSub">
            <ElRadio :value="1">{{ tl('enable') }}</ElRadio>
            <ElRadio :value="0">{{ tl('disable') }}</ElRadio>
          </ElRadioGroup>
        </div>
        <div>
          <div class="text-muted-foreground mb-1 text-xs">
            {{ tl('block_duplicate_phone') }}
          </div>
          <ElRadioGroup v-model="form.phone_block" :disabled="!isAdminOrSub">
            <ElRadio :value="1">{{ tl('enable') }}</ElRadio>
            <ElRadio :value="0">{{ tl('disable') }}</ElRadio>
          </ElRadioGroup>
        </div>
        <div>
          <div class="text-muted-foreground mb-1 text-xs">
            {{ tl('block_duplicate_account') }}
          </div>
          <ElRadioGroup v-model="form.account_block" :disabled="!isAdminOrSub">
            <ElRadio :value="1">{{ tl('enable') }}</ElRadio>
            <ElRadio :value="0">{{ tl('disable') }}</ElRadio>
          </ElRadioGroup>
        </div>
      </div>

      <ElTabs v-model="langTab" class="mt-4">
        <ElTabPane
          v-for="lang in languages"
          :key="lang.id"
          :label="lang.name"
          :name="String(lang.id)"
        >
          <div class="mb-3">
            <div class="text-muted-foreground mb-1 text-xs">
              {{ tl('event_name') }}
            </div>
            <ElInput v-model="form.names[String(lang.id)]" />
          </div>
          <div class="mb-3">
            <div class="text-muted-foreground mb-1 text-xs">
              {{ tl('event_content') }}
            </div>
            <ElInput
              v-model="form.contents[String(lang.id)]"
              :rows="6"
              type="textarea"
            />
          </div>
          <div>
            <div class="text-muted-foreground mb-1 text-xs">
              {{ tl('picture') }}
            </div>
            <ImageInput
              v-model="form.images[String(lang.id)]"
              :upload-fn="uploadImage"
            />
          </div>
        </ElTabPane>
      </ElTabs>
    </div>

    <template #footer>
      <ElButton @click="visible = false">{{ tl('cancel') }}</ElButton>
      <ElButton :loading="saving" type="primary" @click="save">
        {{ tl('btn_update') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
