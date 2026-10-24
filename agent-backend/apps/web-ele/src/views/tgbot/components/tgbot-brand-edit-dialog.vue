<script lang="ts" setup>
import { computed, reactive, ref } from 'vue';

import { useUserStore } from '@vben/stores';

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
  ElRadioButton,
  ElRadioGroup,
  ElSelect,
  ElTabPane,
  ElTabs,
} from 'element-plus';

import {
  createTgbotBrandApi,
  getLanguagesApi,
  getTgbotBrandInfoApi,
  searchAgentOptionsApi,
  updateTgbotBrandApi,
  uploadTgbotMediaApi,
} from '#/api';
import ImageInput from '#/components/image-input.vue';
import { tl } from '#/locales';

const emit = defineEmits<{ success: [] }>();

const userStore = useUserStore();

const editId = ref<string>('');
const copyId = ref<string>('');
const isEdit = computed(() => editId.value !== '');

/** config 連結欄位（label key + 預設值） */
const LINK_FIELDS: Array<{
  def: string;
  key: string;
  label: string;
  required?: boolean;
}> = [
  { def: 'https://example.invalid', key: 'LOGIN_DOMAIN', label: 'login_domain' },
  {
    def: '%s/tg-login?brand_code=%s&redirect=/withdrawal/bank-bind',
    key: 'WITHDRAWAL_LINK',
    label: 'withdrawal_link',
  },
  {
    def: '%s/tg-login?brand_code=%s&redirect=/agent',
    key: 'AGENT_LINK',
    label: 'agent_link',
  },
  {
    def: '%s/tg-login?brand_code=%s&redirect=/reward',
    key: 'EVENT_LINK',
    label: 'event_link',
  },
  {
    def: '%s/tg-login?brand_code=%s&redirect=/game/%s&lang=%s',
    key: 'GAME_LINK',
    label: 'game_link',
    required: true,
  },
  {
    def: '%s/tg-login?brand_code=%s&redirect=/&lang=%s',
    key: 'LOBBY_LINK',
    label: 'lobby_link',
    required: true,
  },
  {
    def: '%s/tg-login?brand_code=%s&redirect=/wallet',
    key: 'WALLET_LINK',
    label: 'wallet_link',
    required: true,
  },
  {
    def: '%s/tg-login?brand_code=%s&redirect=/vip/introduce',
    key: 'VIP_LINK',
    label: 'vip_link',
  },
  {
    def: '%s/tg-login?brand_code=%s&redirect=/record/list',
    key: 'HISTORY_LINK',
    label: 'history_link',
  },
  {
    def: '%s/tg-login?brand_code=%s',
    key: 'WEBAPP_LOGIN_LINK',
    label: 'webapp_login_link',
  },
];

const DEFAULT_LANGS = ['en', 'zh-tw', 'zh-cn', 'my-mm', 'ms-my', 'ru'];

/** 登入前按鈕（v1+v2 皆顯示） */
const PRE_BUTTONS = [
  { key: 'MAIN_BUTTON_CS_PRE_LOGIN', label: 'cs_button', modes: ['v1', 'v2'] },
  {
    key: 'MAIN_BUTTON_OFFICIAL',
    label: 'official_tg_button',
    modes: ['v1', 'v2'],
  },
];
/** 登入後按鈕（依 BOT_MODE 顯示 v1/v2） */
const POST_BUTTONS = [
  { key: 'MAIN_BUTTON_WALLET', label: 'wallet_button', modes: ['v1'] },
  { key: 'MAIN_BUTTON_VIP', label: 'vip_button', modes: ['v1'] },
  { key: 'MAIN_BUTTON_EVENT', label: 'event_button', modes: ['v1', 'v2'] },
  { key: 'MAIN_BUTTON_INVITE', label: 'invite_button', modes: ['v1'] },
  { key: 'MAIN_BUTTON_CHECKIN', label: 'checkin_button', modes: ['v1'] },
  { key: 'MAIN_BUTTON_MISSION', label: 'mission_button', modes: ['v1'] },
  { key: 'MAIN_BUTTON_CS_POST_LOGIN', label: 'cs_button', modes: ['v1'] },
  { key: 'MAIN_BUTTON_HISTORY', label: 'history_button', modes: ['v1'] },
  { key: 'MAIN_BUTTON_HOMEPAGE', label: 'homepage_button', modes: ['v1'] },
  { key: 'MAIN_BUTTON_SHARE', label: 'share_button', modes: ['v2'] },
];
const ALL_BUTTON_KEYS = [...PRE_BUTTONS, ...POST_BUTTONS].map((b) => b.key);

const visible = ref(false);
const activeTab = ref('basic');
const activeLang = ref('');

const basic = reactive({
  agent_id: '' as number | string,
  bot_id: '',
  bot_token: '',
  code: '',
  name: '',
});

const links = reactive<Record<string, string>>(
  Object.fromEntries(LINK_FIELDS.map((f) => [f.key, f.def])),
);
const botMode = ref<number | string>(1);
const defaultLanguage = ref('en');
const buttons = reactive<Record<string, boolean>>(
  Object.fromEntries(ALL_BUTTON_KEYS.map((k) => [k, false])),
);

interface LangContent {
  link_buttons_config: string;
  restart_media: { type: string; url: string };
  restart_message: string;
  share_button_name: string;
  share_content: string;
  start_media: { type: string; url: string };
  start_message: string;
}
const languages = ref<Array<{ code: string; name: string }>>([]);
const langContent = reactive<Record<string, LangContent>>({});
let languagesLoaded = false;

function blankLang(): LangContent {
  return {
    link_buttons_config: '',
    restart_media: { type: 'photo', url: '' },
    restart_message: '',
    share_button_name: '',
    share_content: '',
    start_media: { type: 'photo', url: '' },
    start_message: '',
  };
}

const agentOptions = ref<Array<{ id: number | string; username: string }>>([]);
const agentLoading = ref(false);
async function searchAgents(q: string) {
  if (!q) return;
  agentLoading.value = true;
  try {
    const res = await searchAgentOptionsApi(q);
    agentOptions.value = Number(res.code) === 1000 ? (res.data ?? []) : [];
  } finally {
    agentLoading.value = false;
  }
}

function genUUID() {
  basic.code =
    typeof crypto !== 'undefined' && crypto.randomUUID
      ? crypto.randomUUID()
      : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replaceAll(/[xy]/g, (c) => {
          const r = Math.trunc(Math.random() * 16);
          const v = c === 'x' ? r : (r & 0x3) | 0x8;
          return v.toString(16);
        });
}

/** 該按鈕在目前 BOT_MODE 下是否顯示 */
function buttonVisible(modes: string[]) {
  const mode = Number(botMode.value) === 2 ? 'v2' : 'v1';
  return modes.includes(mode);
}

/** 上傳媒體：回傳 file_url 給 ImageInput 綁定 */
async function uploadMedia(base64: string): Promise<string> {
  const res = await uploadTgbotMediaApi(base64);
  if (Number(res.code) === 1000 && res.data?.file_url) {
    ElMessage.success(tl('success'));
    return res.data.file_url;
  }
  ElMessage.error(res.message);
  return '';
}

const saving = ref(false);

function validate(): boolean {
  const errs: string[] = [];
  if (!basic.agent_id) errs.push(tl('agent_account'));
  if (!basic.name) errs.push(tl('bot_name'));
  if (!basic.code) errs.push(tl('bot_code'));
  if (!basic.bot_id) errs.push(tl('telegram_bot_id'));
  if (!basic.bot_token) errs.push(tl('bot_token'));
  if (!links.GAME_LINK) errs.push(tl('game_link'));
  if (!links.LOBBY_LINK) errs.push(tl('lobby_link'));
  if (!links.WALLET_LINK) errs.push(tl('wallet_link'));
  for (const l of languages.value) {
    const v = langContent[l.code]?.link_buttons_config?.trim();
    if (v) {
      try {
        JSON.parse(v);
      } catch {
        errs.push(`link_buttons_config (${l.code}) JSON`);
      }
    }
  }
  if (errs.length > 0) {
    ElMessage.warning(errs.join('、'));
    return false;
  }
  return true;
}

async function handleSave() {
  if (!validate()) return;
  const config: Record<string, unknown> = {
    ...links,
    BOT_MODE: botMode.value,
    DEFAULT_LANGUAGE: defaultLanguage.value,
  };
  for (const k of ALL_BUTTON_KEYS) config[k] = buttons[k] ? 1 : 0;

  const mlc: Record<string, unknown> = {};
  for (const l of languages.value) {
    mlc[l.code] = langContent[l.code];
  }

  const payload = {
    agent_id: basic.agent_id,
    bot_id: basic.bot_id,
    bot_token: [REDACTED],
    code: basic.code,
    config,
    multi_language_content: mlc,
    name: basic.name,
  };
  saving.value = true;
  try {
    const res = isEdit.value
      ? await updateTgbotBrandApi({ ...payload, id: editId.value })
      : await createTgbotBrandApi(payload);
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('save_success'));
      visible.value = false;
      emit('success');
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    saving.value = false;
  }
}

function parseJson(v: unknown): Record<string, any> {
  if (!v) return {};
  if (typeof v === 'object') return v as Record<string, any>;
  try {
    return JSON.parse(String(v));
  } catch {
    return {};
  }
}

function applyBrand(data: Record<string, any>, copy: boolean) {
  basic.agent_id = data.agent_id ?? '';
  if (data.agent_id) {
    agentOptions.value = [
      {
        id: data.agent_id,
        username: data.agent_username ?? String(data.agent_id),
      },
    ];
  }
  basic.name = copy ? `${data.name ?? ''} (Copy)` : (data.name ?? '');
  basic.bot_id = copy ? `${data.bot_id ?? ''} (Copy)` : (data.bot_id ?? '');
  basic.bot_token = [REDACTED] ?? '';
  if (copy) {
    genUUID();
  } else {
    basic.code = data.code ?? '';
  }
  const cfg = parseJson(data.config);
  for (const f of LINK_FIELDS) {
    const v = cfg[f.key];
    if (v !== null && v !== undefined) links[f.key] = v;
  }
  if (cfg.BOT_MODE !== null && cfg.BOT_MODE !== undefined)
    botMode.value = cfg.BOT_MODE;
  if (cfg.DEFAULT_LANGUAGE !== null && cfg.DEFAULT_LANGUAGE !== undefined)
    defaultLanguage.value = cfg.DEFAULT_LANGUAGE;
  for (const k of ALL_BUTTON_KEYS) buttons[k] = Number(cfg[k]) === 1;
  const mlc = parseJson(data.multi_language_content);
  for (const code of Object.keys(mlc)) {
    const src = mlc[code] ?? {};
    langContent[code] = {
      link_buttons_config: src.link_buttons_config ?? '',
      restart_media: {
        type: src.restart_media?.type ?? 'photo',
        url: src.restart_media?.url ?? '',
      },
      restart_message: src.restart_message ?? '',
      share_button_name: src.share_button_name ?? '',
      share_content: src.share_content ?? '',
      start_media: {
        type: src.start_media?.type ?? 'photo',
        url: src.start_media?.url ?? '',
      },
      start_message: src.start_message ?? '',
    };
  }
}

function resetForm() {
  basic.agent_id = '';
  basic.bot_id = '';
  basic.bot_token = '';
  basic.code = '';
  basic.name = '';
  for (const f of LINK_FIELDS) links[f.key] = f.def;
  botMode.value = 1;
  defaultLanguage.value = 'en';
  for (const k of ALL_BUTTON_KEYS) buttons[k] = false;
  for (const k of Object.keys(langContent)) delete langContent[k];
  agentOptions.value = [];
  activeTab.value = 'basic';
}

async function open(
  opts: { copyId?: number | string; id?: number | string } = {},
) {
  editId.value = opts.id ? String(opts.id) : '';
  copyId.value = opts.copyId ? String(opts.copyId) : '';
  resetForm();
  visible.value = true;
  if (!languagesLoaded) {
    const langRes = await getLanguagesApi().catch(() => []);
    languages.value = (langRes ?? []).map((l) => ({
      code: l.code,
      name: l.name,
    }));
    languagesLoaded = true;
  }
  for (const l of languages.value) {
    if (!langContent[l.code]) langContent[l.code] = blankLang();
  }
  if (languages.value.length > 0)
    activeLang.value = languages.value[0]?.code ?? activeLang.value;

  if (editId.value || copyId.value) {
    const res = await getTgbotBrandInfoApi(editId.value || copyId.value);
    if (Number(res.code) === 1000 && res.data) {
      applyBrand(res.data, !editId.value && Boolean(copyId.value));
    }
  } else {
    // 新增：預設帶登入本人
    const info = userStore.userInfo as any;
    if (info?.id) {
      basic.agent_id = info.id;
      agentOptions.value = [
        { id: info.id, username: info.username ?? String(info.id) },
      ];
    }
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="`${tl('menu_tgbot_management')} - ${
      isEdit ? tl('edit_bot') : tl('add_bot')
    }`"
    top="5vh"
    width="1000"
  >
    <div class="max-h-[74vh] overflow-y-auto pr-1">
      <ElTabs v-model="activeTab" type="border-card">
        <!-- 基本資訊 -->
        <ElTabPane :label="tl('basic_info')" name="basic">
          <ElForm label-width="140px">
            <ElFormItem :label="tl('agent_account')">
              <ElSelect
                v-model="basic.agent_id"
                :debounce="500"
                :loading="agentLoading"
                :remote-method="searchAgents"
                class="w-full"
                filterable
                remote
              >
                <ElOption
                  v-for="a in agentOptions"
                  :key="a.id"
                  :label="a.username"
                  :value="a.id"
                />
              </ElSelect>
            </ElFormItem>
            <ElFormItem :label="tl('bot_name')">
              <ElInput v-model="basic.name" />
            </ElFormItem>
            <ElFormItem :label="tl('bot_code')">
              <div class="flex w-full gap-2">
                <ElInput
                  v-model="basic.code"
                  placeholder="e.g. 550e8400-e29b-41d4-a716-446655440000"
                />
                <ElButton type="primary" @click="genUUID">Random</ElButton>
              </div>
            </ElFormItem>
            <ElFormItem :label="tl('telegram_bot_id')">
              <ElInput v-model="basic.bot_id" />
            </ElFormItem>
            <ElFormItem :label="tl('bot_token')">
              <ElInput v-model="basic.bot_token" />
            </ElFormItem>
          </ElForm>
        </ElTabPane>

        <!-- 自訂配置 -->
        <ElTabPane :label="tl('custom_config')" name="config">
          <ElForm label-width="180px">
            <ElFormItem
              v-for="f in LINK_FIELDS"
              :key="f.key"
              :label="tl(f.label)"
            >
              <ElInput v-model="links[f.key]">
                <template v-if="f.required" #prefix>
                  <span class="text-red-500">*</span>
                </template>
              </ElInput>
            </ElFormItem>
            <ElFormItem :label="tl('bot_mode')">
              <ElRadioGroup v-model="botMode">
                <ElRadioButton :value="1">{{ tl('bot_mode.1') }}</ElRadioButton>
                <ElRadioButton :value="2">{{ tl('bot_mode.2') }}</ElRadioButton>
              </ElRadioGroup>
            </ElFormItem>
            <ElFormItem :label="tl('default_language')">
              <ElRadioGroup v-model="defaultLanguage">
                <ElRadioButton v-for="l in DEFAULT_LANGS" :key="l" :value="l">
                  {{ l }}
                </ElRadioButton>
              </ElRadioGroup>
            </ElFormItem>
            <ElFormItem :label="tl('pre_login_buttons')">
              <div class="flex flex-col gap-1">
                <ElCheckbox
                  v-for="b in PRE_BUTTONS"
                  v-show="buttonVisible(b.modes)"
                  :key="b.key"
                  v-model="buttons[b.key]"
                >
                  {{ tl(b.label) }}
                </ElCheckbox>
              </div>
            </ElFormItem>
            <ElFormItem :label="tl('post_login_buttons')">
              <div class="flex flex-col gap-1">
                <ElCheckbox
                  v-for="b in POST_BUTTONS"
                  v-show="buttonVisible(b.modes)"
                  :key="b.key"
                  v-model="buttons[b.key]"
                >
                  {{ tl(b.label) }}
                </ElCheckbox>
              </div>
            </ElFormItem>
          </ElForm>
        </ElTabPane>

        <!-- 多語系內容 -->
        <ElTabPane :label="tl('multi_language_content')" name="mlc">
          <ElTabs v-model="activeLang">
            <ElTabPane
              v-for="l in languages"
              :key="l.code"
              :label="l.name"
              :name="l.code"
            >
              <template v-if="langContent[l.code]">
                <ElCard class="mb-3" shadow="never">
                  <template #header>
                    {{ tl('start_function_settings') }}
                  </template>
                  <ElFormItem
                    :label="tl('message_content')"
                    label-width="120px"
                  >
                    <ElInput
                      v-model="langContent[l.code]!.start_message"
                      :rows="3"
                      type="textarea"
                    />
                  </ElFormItem>
                  <ElFormItem
                    :label="tl('image_animation')"
                    label-width="120px"
                  >
                    <div class="flex w-full flex-col gap-2">
                      <ElSelect
                        v-model="langContent[l.code]!.start_media.type"
                        class="!w-40"
                      >
                        <ElOption :label="tl('image')" value="photo" />
                        <ElOption :label="tl('animation')" value="animation" />
                      </ElSelect>
                      <ImageInput
                        v-model="langContent[l.code]!.start_media.url"
                        :max-size-mb="10"
                        :upload-fn="uploadMedia"
                        accept="image/*,video/*"
                      />
                    </div>
                  </ElFormItem>
                </ElCard>

                <ElCard class="mb-3" shadow="never">
                  <template #header>
                    {{ tl('restart_function_settings') }}
                  </template>
                  <ElFormItem
                    :label="tl('message_content')"
                    label-width="120px"
                  >
                    <ElInput
                      v-model="langContent[l.code]!.restart_message"
                      :rows="3"
                      type="textarea"
                    />
                  </ElFormItem>
                  <ElFormItem
                    :label="tl('image_animation')"
                    label-width="120px"
                  >
                    <div class="flex w-full flex-col gap-2">
                      <ElSelect
                        v-model="langContent[l.code]!.restart_media.type"
                        class="!w-40"
                      >
                        <ElOption :label="tl('image')" value="photo" />
                        <ElOption :label="tl('animation')" value="animation" />
                      </ElSelect>
                      <ImageInput
                        v-model="langContent[l.code]!.restart_media.url"
                        :max-size-mb="10"
                        :upload-fn="uploadMedia"
                        accept="image/*,video/*"
                      />
                    </div>
                  </ElFormItem>
                </ElCard>

                <ElCard class="mb-3" shadow="never">
                  <template #header>
                    {{ tl('share_function_settings') }}
                  </template>
                  <ElFormItem :label="tl('button_name')" label-width="120px">
                    <ElInput v-model="langContent[l.code]!.share_button_name" />
                  </ElFormItem>
                  <ElFormItem :label="tl('share_content')" label-width="120px">
                    <ElInput
                      v-model="langContent[l.code]!.share_content"
                      :rows="3"
                      type="textarea"
                    />
                  </ElFormItem>
                </ElCard>

                <ElCard shadow="never">
                  <template #header>
                    {{ tl('link_buttons_settings') }}
                  </template>
                  <ElFormItem
                    :label="tl('link_buttons_config')"
                    label-width="120px"
                  >
                    <ElInput
                      v-model="langContent[l.code]!.link_buttons_config"
                      :rows="5"
                      type="textarea"
                    />
                  </ElFormItem>
                </ElCard>
              </template>
            </ElTabPane>
          </ElTabs>
        </ElTabPane>
      </ElTabs>
    </div>

    <template #footer>
      <ElButton @click="visible = false">{{ tl('cancel') }}</ElButton>
      <ElButton :loading="saving" type="primary" @click="handleSave">
        {{ tl('save') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
