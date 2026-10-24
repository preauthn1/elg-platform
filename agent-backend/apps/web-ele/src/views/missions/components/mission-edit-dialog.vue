<script lang="ts" setup>
import type { MissionAvailableTypes, MissionSavePayload } from '#/api';

import { computed, reactive, ref } from 'vue';

import dayjs from 'dayjs';
import {
  ElButton,
  ElDatePicker,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElOption,
  ElSelect,
  ElTabPane,
  ElTabs,
} from 'element-plus';

import {
  getGameTypeListApi,
  getLanguagesApi,
  getMissionAvailableTypesApi,
  getMissionViewApi,
  getVendorListApi,
  saveMissionApi,
  searchAgentOptionsApi,
} from '#/api';
import { tl } from '#/locales';

const emit = defineEmits<{ success: [] }>();

/** 一次性行為類型（reset_type 須為 one_time 才可選；條件固定） */
const ONE_TIME_ACTION_TYPES = new Set([
  'bind_bank_card',
  'bind_email',
  'bind_mobile',
  'fill_birthday',
  'realname_validation',
]);

const visible = ref(false);
const editId = ref('');
const isEdit = computed(() => Boolean(editId.value));

const loading = ref(false);
const activeTab = ref('basic');
const submitting = ref(false);

const types = reactive<MissionAvailableTypes>({});
const vendors = ref<Array<{ id: number | string; name: string }>>([]);
const gameTypes = ref<Array<{ id: number | string; name: string }>>([]);
const languages = ref<Array<{ id: number | string; name: string }>>([]);
const agentOptions = ref<Array<{ id: number | string; username: string }>>([]);
const agentLoading = ref(false);

/** 任務已開始 → 結構欄位鎖定（名稱/描述仍可編輯） */
const locked = ref(false);

function blankForm() {
  return {
    action_games: [] as Array<{
      game_type: number | string;
      game_type_name: string;
      vendor_id: number | string;
      vendor_name: string;
    }>,
    action_type: '',
    agent_ids: [] as Array<number | string>,
    condition_fix_number: '' as number | string,
    condition_type: '',
    condition_value: 0 as number | string,
    description: '',
    end_time: dayjs().format('YYYY-MM-DD 23:59'),
    lang_datas: [] as Array<{
      description: string;
      id: number | string;
      langName: string;
      name: string;
    }>,
    name: '',
    reset_type: '',
    reward_flow_limit_multiple: '' as number | string,
    reward_type: '',
    reward_value: '' as number | string,
    start_time: dayjs().format('YYYY-MM-DD 00:00'),
  };
}

const form = reactive(blankForm());

/** 行為新增區暫存的下拉值 */
const pickVendor = ref<number | string>(0);
const pickGameType = ref<number | string>(0);

const isOneTimeAction = computed(() =>
  ONE_TIME_ACTION_TYPES.has(form.action_type),
);
const isBetAction = computed(() => form.action_type === 'bet');

/** 條件區呈現模式 */
const conditionMode = computed<'bet' | 'deposit' | 'one_time'>(() => {
  if (isBetAction.value) return 'bet';
  if (isOneTimeAction.value) return 'one_time';
  return 'deposit';
});

/** 條件類型下拉（bet 不含 fix_number_times） */
const conditionTypeOptions = computed(() => {
  const all = Object.entries(types.condition_types ?? {});
  return conditionMode.value === 'bet'
    ? all.filter(([k]) => k !== 'fix_number_times')
    : all;
});

/** 某行為類型是否因 reset_type 而停用 */
function actionDisabled(key: string) {
  return ONE_TIME_ACTION_TYPES.has(key) && form.reset_type !== 'one_time';
}

function vendorName(id: number | string) {
  if (Number(id) === 0) return tl('unlimited');
  return vendors.value.find((v) => String(v.id) === String(id))?.name ?? '';
}
function gameTypeName(id: number | string) {
  if (Number(id) === 0) return tl('unlimited');
  return gameTypes.value.find((g) => String(g.id) === String(id))?.name ?? '';
}

function addActionGame() {
  form.action_games.push({
    game_type: pickGameType.value,
    game_type_name: gameTypeName(pickGameType.value),
    vendor_id: pickVendor.value,
    vendor_name: vendorName(pickVendor.value),
  });
}
function removeActionGame(idx: number) {
  form.action_games.splice(idx, 1);
}

async function searchAgent(q: string) {
  if (!q) return;
  agentLoading.value = true;
  try {
    const res = await searchAgentOptionsApi(q);
    if (Number(res.code) === 1000) {
      agentOptions.value = (res.data ?? []).map((a) => ({
        id: a.id,
        username: a.username,
      }));
    }
  } finally {
    agentLoading.value = false;
  }
}

function ensureLangSlots() {
  if (form.lang_datas.length === 0) {
    form.lang_datas = languages.value.map((l) => ({
      description: '',
      id: l.id,
      langName: l.name,
      name: '',
    }));
  }
}

async function populateFromView() {
  const res = await getMissionViewApi(editId.value);
  if (Number(res.code) !== 1000 || !res.data) {
    ElMessage.error(res.message || tl('failure'));
    return;
  }
  const d = res.data;
  form.name = d.name ?? '';
  form.description = d.description ?? '';
  form.reset_type = d.reset_type ?? '';
  form.action_type = d.action_type ?? '';
  form.condition_type = d.condition_type ?? '';
  form.condition_value = d.condition_value ?? 0;
  if (d.start_time) form.start_time = d.start_time;
  if (d.end_time) form.end_time = d.end_time;

  form.agent_ids = (d.available_agents ?? []).map((a) => a.id);
  agentOptions.value = (d.available_agents ?? []).map((a) => ({
    id: a.id,
    username: a.username,
  }));

  for (const [langId, v] of Object.entries(d.mission_names ?? {})) {
    const slot = form.lang_datas.find((s) => String(s.id) === String(langId));
    if (slot) {
      slot.name = v.name ?? '';
      slot.description = v.description ?? '';
    }
  }

  const info = example.invalid ?? {};
  // 行為設定（bet 的遊戲商/類型清單）
  const game = info.action_config?.game;
  if (form.action_type === 'bet' && game?.vendor_id) {
    form.action_games = (game.vendor_id as Array<number | string>).map(
      (vid, i) => ({
        game_type: game.game_type?.[i] ?? 0,
        game_type_name: gameTypeName(game.game_type?.[i] ?? 0),
        vendor_id: vid,
        vendor_name: vendorName(vid),
      }),
    );
  }
  // 條件設定
  form.condition_fix_number = info.condition_config?.fix_number ?? '';
  // 獎勵設定
  const reward = info.reward_config ?? {};
  form.reward_type = reward.type ?? '';
  form.reward_value = reward.value ?? '';
  form.reward_flow_limit_multiple = reward.flow_limit_multiple ?? '';

  locked.value = form.start_time
    ? form.start_time <= dayjs().format('YYYY-MM-DD HH:mm:ss')
    : false;
}

/** 靜態下拉只載一次（跨多次開窗共用） */
let staticLoaded = false;

function resetState() {
  Object.assign(form, blankForm());
  agentOptions.value = [];
  pickVendor.value = 0;
  pickGameType.value = 0;
  locked.value = false;
  activeTab.value = 'basic';
}

async function open(id: number | string = '') {
  editId.value = String(id || '');
  resetState();
  visible.value = true;
  loading.value = true;
  try {
    if (!staticLoaded) {
      const [t, vs, gts, langs] = await Promise.all([
        getMissionAvailableTypesApi(),
        getVendorListApi().catch(() => []),
        getGameTypeListApi().catch(() => []),
        getLanguagesApi().catch(() => []),
      ]);
      if (Number(t.code) === 1000 && t.data) Object.assign(types, t.data);
      vendors.value = vs ?? [];
      gameTypes.value = gts ?? [];
      languages.value = langs ?? [];
      staticLoaded = true;
    }
    ensureLangSlots();
    if (isEdit.value) await populateFromView();
  } finally {
    loading.value = false;
  }
}

/** 清掉空白語系資料（對齊舊版 mission_ajax 過濾） */
function cleanLangDatas() {
  const out: Record<string, { description?: string; name?: string }> = {};
  for (const s of form.lang_datas) {
    const name = s.name.trim();
    const desc = s.description.trim();
    if (!name && !desc) continue;
    const entry: { description?: string; name?: string } = {};
    if (name) entry.name = name;
    if (desc) entry.description = desc;
    out[String(s.id)] = entry;
  }
  return out;
}

async function handleSave() {
  const action: MissionSavePayload['action'] = { type: form.action_type };
  if (isBetAction.value) {
    action.game = {
      game_type: form.action_games.map((g) => g.game_type),
      vendor_id: form.action_games.map((g) => g.vendor_id),
    };
  }

  let condition: MissionSavePayload['condition'];
  if (conditionMode.value === 'one_time') {
    condition = { type: 'times', value: 1 };
  } else {
    condition = {
      type: form.condition_type,
      value: form.condition_value,
    };
    if (form.condition_type === 'fix_number_times') {
      condition.fix_number = form.condition_fix_number;
    }
  }

  const reward: MissionSavePayload['reward'] = {
    type: form.reward_type,
    value: form.reward_value,
  };
  if (form.reward_type === 'balance') {
    reward.flow_limit_multiple = form.reward_flow_limit_multiple;
  }

  submitting.value = true;
  try {
    const res = await saveMissionApi({
      action,
      agent_ids: form.agent_ids,
      condition,
      description: form.description,
      end_time: form.end_time,
      lang_datas: cleanLangDatas(),
      ...(editId.value ? { mission_id: editId.value } : {}),
      name: form.name,
      reset_type: form.reset_type,
      reward,
      start_time: form.start_time,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      visible.value = false;
      emit('success');
    } else {
      ElMessage.error(res.message || tl('failure'));
    }
  } finally {
    submitting.value = false;
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="`${tl('missions')} > ${isEdit ? tl('edit') : tl('add')}`"
    align-center
    width="900"
  >
    <div v-loading="loading" class="max-h-[74vh] overflow-y-auto pr-1">
      <ElTabs v-model="activeTab">
        <!-- 1. 基本訊息 -->
        <ElTabPane :label="tl('mission.edit.basic_info')" name="basic">
          <ElForm label-position="top">
            <ElFormItem :label="tl('agent_account')" required>
              <ElSelect
                v-model="form.agent_ids"
                :debounce="500"
                :loading="agentLoading"
                :remote-method="searchAgent"
                class="!w-full md:!w-1/2"
                filterable
                multiple
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

            <ElTabs class="mt-2" type="border-card">
              <ElTabPane :label="tl('default')">
                <ElFormItem :label="tl('mission.edit.name')" required>
                  <ElInput v-model="form.name" />
                </ElFormItem>
                <ElFormItem :label="tl('mission.edit.description')">
                  <ElInput
                    v-model="form.description"
                    :rows="3"
                    type="textarea"
                  />
                </ElFormItem>
              </ElTabPane>
              <ElTabPane
                v-for="ld in form.lang_datas"
                :key="ld.id"
                :label="ld.langName"
              >
                <ElFormItem :label="tl('mission.edit.name')">
                  <ElInput v-model="ld.name" />
                </ElFormItem>
                <ElFormItem :label="tl('mission.edit.description')">
                  <ElInput v-model="ld.description" :rows="3" type="textarea" />
                </ElFormItem>
              </ElTabPane>
            </ElTabs>
          </ElForm>
        </ElTabPane>

        <!-- 2. 時間設定 -->
        <ElTabPane :label="tl('mission.edit.time_setting')" name="time">
          <ElForm label-position="top">
            <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
              <ElFormItem :label="tl('mission.edit.reset_type')">
                <ElSelect
                  v-model="form.reset_type"
                  :disabled="locked"
                  class="!w-full"
                >
                  <ElOption
                    v-for="(name, key) in types.reset_types"
                    :key="key"
                    :label="name"
                    :value="key"
                  />
                </ElSelect>
              </ElFormItem>
              <div></div>
              <ElFormItem :label="tl('mission.edit.start_time')">
                <ElDatePicker
                  v-model="form.start_time"
                  :disabled="locked"
                  class="!w-full"
                  format="YYYY-MM-DD HH:mm"
                  type="datetime"
                  value-format="YYYY-MM-DD HH:mm"
                />
              </ElFormItem>
              <ElFormItem :label="tl('mission.edit.end_time')">
                <ElDatePicker
                  v-model="form.end_time"
                  :disabled="locked"
                  class="!w-full"
                  format="YYYY-MM-DD HH:mm"
                  type="datetime"
                  value-format="YYYY-MM-DD HH:mm"
                />
              </ElFormItem>
            </div>
          </ElForm>
        </ElTabPane>

        <!-- 3. 行為設定 -->
        <ElTabPane :label="tl('mission.edit.action_setting')" name="action">
          <ElForm label-position="top">
            <ElFormItem :label="tl('mission.edit.action_type')">
              <ElSelect
                v-model="form.action_type"
                :disabled="locked"
                class="!w-full md:!w-1/2"
              >
                <ElOption
                  v-for="(name, key) in types.action_types"
                  :key="key"
                  :disabled="actionDisabled(key)"
                  :label="name"
                  :value="key"
                />
              </ElSelect>
            </ElFormItem>

            <template v-if="isBetAction">
              <div class="grid grid-cols-1 items-end gap-x-4 md:grid-cols-12">
                <ElFormItem
                  :label="tl('mission.edit.action_setting.game.vendor')"
                  class="md:col-span-5"
                >
                  <ElSelect
                    v-model="pickVendor"
                    :disabled="locked"
                    class="!w-full"
                  >
                    <ElOption :label="tl('unlimited')" :value="0" />
                    <ElOption
                      v-for="v in vendors"
                      :key="v.id"
                      :label="v.name"
                      :value="v.id"
                    />
                  </ElSelect>
                </ElFormItem>
                <ElFormItem
                  :label="tl('mission.edit.action_setting.game.game_type')"
                  class="md:col-span-5"
                >
                  <ElSelect
                    v-model="pickGameType"
                    :disabled="locked"
                    class="!w-full"
                  >
                    <ElOption :label="tl('unlimited')" :value="0" />
                    <ElOption
                      v-for="g in gameTypes"
                      :key="g.id"
                      :label="g.name"
                      :value="g.id"
                    />
                  </ElSelect>
                </ElFormItem>
                <ElFormItem class="md:col-span-2">
                  <ElButton
                    :disabled="locked"
                    type="primary"
                    @click="addActionGame"
                  >
                    +
                  </ElButton>
                </ElFormItem>
              </div>

              <div
                v-for="(g, i) in form.action_games"
                :key="i"
                class="mb-2 grid grid-cols-1 items-center gap-x-4 md:grid-cols-12"
              >
                <ElInput
                  :model-value="g.vendor_name"
                  class="md:col-span-5"
                  disabled
                />
                <ElInput
                  :model-value="g.game_type_name"
                  class="md:col-span-5"
                  disabled
                />
                <div class="md:col-span-2">
                  <ElButton
                    :disabled="locked"
                    type="danger"
                    @click="removeActionGame(i)"
                  >
                    -
                  </ElButton>
                </div>
              </div>
            </template>
          </ElForm>
        </ElTabPane>

        <!-- 4. 條件設定 -->
        <ElTabPane
          :label="tl('mission.edit.condition_setting')"
          name="condition"
        >
          <ElForm label-position="top">
            <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
              <ElFormItem :label="tl('mission.edit.condition_type')">
                <ElSelect
                  v-if="conditionMode !== 'one_time'"
                  v-model="form.condition_type"
                  :disabled="locked"
                  class="!w-full"
                >
                  <ElOption
                    v-for="[key, name] in conditionTypeOptions"
                    :key="key"
                    :label="name"
                    :value="key"
                  />
                </ElSelect>
                <ElInput
                  v-else
                  :model-value="types.condition_types?.times ?? 'times'"
                  disabled
                />
              </ElFormItem>
              <ElFormItem :label="tl('mission.edit.condition_value')">
                <ElInput
                  v-if="conditionMode !== 'one_time'"
                  v-model="form.condition_value"
                  :disabled="locked"
                />
                <ElInput v-else model-value="1" disabled />
              </ElFormItem>
              <ElFormItem
                v-if="
                  conditionMode === 'deposit' &&
                  form.condition_type === 'fix_number_times'
                "
                :label="tl('mission.edit.condition_setting.fix_number')"
              >
                <ElInput
                  v-model="form.condition_fix_number"
                  :disabled="locked"
                />
              </ElFormItem>
            </div>
          </ElForm>
        </ElTabPane>

        <!-- 5. 獎勵設定 -->
        <ElTabPane :label="tl('mission.edit.reward_setting')" name="reward">
          <ElForm label-position="top">
            <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
              <ElFormItem :label="tl('mission.edit.reward_type')">
                <ElSelect
                  v-model="form.reward_type"
                  :disabled="locked"
                  class="!w-full"
                >
                  <ElOption
                    v-for="(name, key) in types.reward_types"
                    :key="key"
                    :label="name"
                    :value="key"
                  />
                </ElSelect>
              </ElFormItem>
              <ElFormItem :label="tl('mission.edit.reward_value')">
                <ElInput v-model="form.reward_value" :disabled="locked" />
              </ElFormItem>
              <ElFormItem
                v-if="form.reward_type === 'balance'"
                :label="tl('event_flow_limit_multiple')"
              >
                <ElInput
                  v-model="form.reward_flow_limit_multiple"
                  :disabled="locked"
                />
              </ElFormItem>
            </div>
          </ElForm>
        </ElTabPane>
      </ElTabs>
    </div>
    <template #footer>
      <ElButton @click="visible = false">{{ tl('cancel') }}</ElButton>
      <ElButton :loading="submitting" type="success" @click="handleSave">
        {{ tl('btn_update') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
