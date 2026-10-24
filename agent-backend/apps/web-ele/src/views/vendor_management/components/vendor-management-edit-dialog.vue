<script lang="ts" setup>
import type { HasAgentVendorRow, VendorManagementItem } from '#/api';

import { computed, reactive, ref } from 'vue';

import {
  ElButton,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElRadio,
  ElRadioGroup,
} from 'element-plus';

import {
  agentVendorSettingBackendApi,
  getHasAgentVendorListApi,
  getVendorViewApi,
  saveVendorManagementApi,
  updateVendorCallbackPathApi,
} from '#/api';
import { tl } from '#/locales';
import {
  IMAGE_ACCEPT,
  ImageUploadError,
  imageUploadText,
  readImageAsDataUrl,
} from '#/utils/image-upload';

import {
  channelText,
  EMPTY_TEXT,
  gameListText,
  walletModeText,
} from '../vendor-management-text';

defineOptions({ name: 'VendorManagementEditDialog' });

const props = defineProps<{
  /** 回調網址主機，用來預覽完整回調網址 */
  callbackBaseUrl?: string;
  /** 能否看到與修改回調路徑（父頁依後端 callback_visible 傳入，僅 admin 角色為 true） */
  callbackVisible?: boolean;
}>();

const emit = defineEmits<{ saved: [] }>();

const visible = ref(false);
const loading = ref(false);
const saving = ref(false);
const editId = ref(0);

const imagePreview = ref('');
const appIconPreview = ref('');
const imageData = ref('');
const appIconData = ref('');

/** 以下取自列表列（apis），彈窗內唯讀顯示 */
const walletMode = ref<VendorManagementItem['wallet_mode']>('transfer');
const channel = ref<null | string>(null);
const hasGameList = ref<boolean | null>(null);
const callbackApplicable = ref(false);
/** 開啟時的回調路徑後綴，存檔時比對有沒有改 */
const callbackPath = ref<null | string>(null);
/** 預設回調路徑後綴（回調路徑留空時生效） */
const defaultCallbackPath = ref<null | string>(null);

/**
 * 回調路徑不可編輯時顯示的文字：沒掛 driver（channel 為 null）顯示 —，
 * 掛了 driver 但不適用（轉帳錢包）顯示「不適用」；與列表的 callbackUrlText 規則一致。
 */
const callbackFallbackText = computed(() =>
  channel.value === null ? EMPTY_TEXT : tl('not_applicable'),
);

/** 回調路徑輸入框提示：有預設後綴就顯示它 */
const callbackPlaceholder = computed(
  () => defaultCallbackPath.value || '/external/api/sw/...',
);

/** 去掉前後空白的回調路徑後綴；預覽、比對有沒有改、送出都用這一份 */
const normalizedCallbackPath = computed(() => form.callback_path.trim());

/** 完整回調網址預覽：輸入框留空時改預覽預設後綴 */
const callbackPreview = computed(
  () =>
    (props.callbackBaseUrl ?? '') +
    (normalizedCallbackPath.value || defaultCallbackPath.value || ''),
);

/** 從回應或例外取後端訊息 */
function errorMessageOf(error: unknown): string {
  const e = error as {
    message?: string;
    response?: { data?: { message?: string } };
  };
  return e?.response?.data?.message ?? e?.message ?? '';
}

/**
 * 回調路徑存檔（apis，成功碼 200）。回傳是否成功；失敗時已提示
 * 「基本資料已儲存，回調路徑未儲存」並附後端訊息。
 */
async function saveCallbackPath(): Promise<boolean> {
  let message: string;
  try {
    const res = await updateVendorCallbackPathApi({
      callback_path: normalizedCallbackPath.value,
      id: editId.value,
    });
    if (Number(res.code) === 200) return true;
    message = res.message;
  } catch (error) {
    message = errorMessageOf(error);
  }
  ElMessage.error(
    message
      ? `${tl('callback_path_save_failed')}：${message}`
      : tl('callback_path_save_failed'),
  );
  return false;
}

const agents = ref<HasAgentVendorRow[]>([]);
/** 各代理是否顯示：agent_id -> 0/1 */
const agentStatus = reactive<Record<number, number>>({});

const form = reactive({
  calc_on_profit_share: 1 as number | string,
  callback_path: '',
  name: '',
  open_window: 1 as number | string,
  percentage: '' as number | string,
  rate: '' as number | string,
  remark: '',
  show_on_profit_share: 1 as number | string,
  sort: '' as number | string,
  status: 1 as number | string,
  valid_bet_rate: '' as number | string,
});

async function onPickImage(e: Event, kind: 'app_icon' | 'image') {
  const file = (e.target as HTMLInputElement).files?.[0];
  if (!file) return;
  let data: string;
  try {
    data = await readImageAsDataUrl(file);
  } catch (error) {
    if (error instanceof ImageUploadError) {
      ElMessage.error(error.message);
      return;
    }
    throw error;
  }
  if (kind === 'image') {
    imageData.value = data;
    imagePreview.value = data;
  } else {
    appIconData.value = data;
    appIconPreview.value = data;
  }
}

async function open(row: VendorManagementItem) {
  const id = row.id;
  visible.value = true;
  editId.value = id;
  walletMode.value = row.wallet_mode;
  channel.value = row.channel;
  hasGameList.value = row.has_game_list;
  // 回調欄位在非 admin 時後端不回傳，缺值一律視為不可設定
  callbackApplicable.value = row.callback_applicable ?? false;
  callbackPath.value = row.callback_path ?? null;
  defaultCallbackPath.value = row.default_callback_path ?? null;
  form.callback_path = row.callback_path ?? '';
  loading.value = true;
  imageData.value = '';
  appIconData.value = '';
  agents.value = [];
  for (const k of Object.keys(agentStatus)) delete agentStatus[Number(k)];
  try {
    const v = (await getVendorViewApi(id)) as any;
    form.name = v?.name ?? '';
    form.rate = v?.rate ?? '';
    form.percentage = v?.percentage ?? '';
    form.sort = v?.sort ?? '';
    form.valid_bet_rate = v?.valid_bet_rate ?? '';
    form.show_on_profit_share = Number(v?.show_on_profit_share) === 1 ? 1 : 0;
    form.calc_on_profit_share = Number(v?.calc_on_profit_share) === 1 ? 1 : 0;
    form.status = Number(v?.status);
    form.open_window = Number(v?.open_window) === 1 ? 1 : 0;
    form.remark = v?.remark ?? '';
    imagePreview.value = v?.image ?? '';
    appIconPreview.value = v?.app_icon ?? '';

    const list = (await getHasAgentVendorListApi(id)) ?? [];
    agents.value = list;
    for (const a of list) {
      agentStatus[a.agent_id] = a.vendor_status === null ? 0 : 1;
    }
  } finally {
    loading.value = false;
  }
}

async function handleSave() {
  saving.value = true;
  try {
    const payload: Record<string, any> = {
      calc_on_profit_share: form.calc_on_profit_share,
      id: editId.value,
      name: form.name,
      open_window: form.open_window,
      percentage: form.percentage,
      rate: form.rate,
      remark: form.remark,
      show_on_profit_share: form.show_on_profit_share,
      sort: form.sort,
      status: form.status,
      valid_bet_rate: form.valid_bet_rate,
    };
    if (imageData.value) payload.image = imageData.value;
    if (appIconData.value) payload.app_icon = appIconData.value;
    const res = await saveVendorManagementApi(payload);

    // 設定各第一層代理是否顯示遊戲商
    await Promise.all(
      agents.value.map((a) =>
        agentVendorSettingBackendApi({
          agent_id: a.agent_id,
          status: agentStatus[a.agent_id] ?? 0,
          vendor_id: editId.value,
        }),
      ),
    );

    if (Number(res.code) === 1000) {
      // 回調路徑走 apis，有改才送。失敗時基本資料已存，先讓父頁重載列表，
      // 彈窗維持開啟讓使用者修正回調路徑。沒有回調權限（非 admin）時一律不送
      if (
        props.callbackVisible &&
        callbackApplicable.value &&
        normalizedCallbackPath.value !== (callbackPath.value ?? '') &&
        !(await saveCallbackPath())
      ) {
        emit('saved');
        return;
      }
      ElMessage.success(res.message);
      visible.value = false;
      emit('saved');
    } else {
      ElMessage.error(
        Number(res.code) === 2002 ? String(res.data) : res.message,
      );
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
    :title="`${tl('menu_vendor_management')} > ${tl('edit')}`"
    align-center
    width="860"
  >
    <ElForm v-loading="loading" :model="form" label-width="auto">
      <ElFormItem :label="tl('name')">
        <ElInput v-model="form.name" />
      </ElFormItem>

      <div class="grid grid-cols-1 gap-x-6 md:grid-cols-2">
        <ElFormItem :label="tl('wallet_mode')">
          <span>{{ walletModeText(walletMode) }}</span>
        </ElFormItem>
        <ElFormItem :label="tl('vendor_channel')">
          <span>{{ channelText(channel) }}</span>
        </ElFormItem>
        <ElFormItem :label="tl('gamelist_api')">
          <span>{{ gameListText(hasGameList) }}</span>
        </ElFormItem>
      </div>

      <div class="grid grid-cols-1 gap-x-6 md:grid-cols-2">
        <ElFormItem :label="tl('turnover_exchange_rate')">
          <ElInput v-model="form.rate" placeholder="1:xx" />
        </ElFormItem>
        <ElFormItem :label="tl('debt_ratio')">
          <ElInput v-model="form.percentage" placeholder="ex: 20%" />
        </ElFormItem>
        <ElFormItem :label="tl('sorts')">
          <ElInput v-model="form.sort" placeholder="ex: 1" />
        </ElFormItem>
        <ElFormItem :label="tl('agent_unlimit_valid_bet_rate')">
          <ElInput v-model="form.valid_bet_rate" placeholder="ex: 1.00" />
        </ElFormItem>
      </div>

      <div class="grid grid-cols-1 gap-x-6 md:grid-cols-2">
        <ElFormItem :label="tl('game_provider_picture')">
          <div class="min-w-0">
            <img
              v-if="imagePreview"
              :src="imagePreview"
              class="mb-2 max-h-[150px] max-w-[min(150px,100%)]"
            />
            <label class="cursor-pointer">
              <span class="el-button el-button--default">
                {{ tl('choose_file') }}
              </span>
              <input
                :accept="IMAGE_ACCEPT"
                class="hidden"
                type="file"
                @change="(e) => onPickImage(e, 'image')"
              />
            </label>
            <div class="text-muted-foreground mt-1 text-xs">
              {{ imageUploadText('image_upload_hint') }}
            </div>
          </div>
        </ElFormItem>
        <ElFormItem :label="tl('game_provider_icon')">
          <div class="min-w-0">
            <img
              v-if="appIconPreview"
              :src="appIconPreview"
              class="mb-2 max-h-[150px] max-w-[min(150px,100%)]"
            />
            <label class="cursor-pointer">
              <span class="el-button el-button--default">
                {{ tl('choose_file') }}
              </span>
              <input
                :accept="IMAGE_ACCEPT"
                class="hidden"
                type="file"
                @change="(e) => onPickImage(e, 'app_icon')"
              />
            </label>
            <div class="text-muted-foreground mt-1 text-xs">
              {{ imageUploadText('image_upload_hint') }}
            </div>
          </div>
        </ElFormItem>
      </div>

      <div class="grid grid-cols-1 gap-x-6 md:grid-cols-2">
        <ElFormItem :label="tl('show_on_profit_share')">
          <ElRadioGroup v-model="form.show_on_profit_share">
            <ElRadio :value="1">{{ tl('enable') }}</ElRadio>
            <ElRadio :value="0">{{ tl('disable') }}</ElRadio>
          </ElRadioGroup>
        </ElFormItem>
        <ElFormItem :label="tl('calc_on_profit_share')">
          <ElRadioGroup v-model="form.calc_on_profit_share">
            <ElRadio :value="1">{{ tl('enable') }}</ElRadio>
            <ElRadio :value="0">{{ tl('disable') }}</ElRadio>
          </ElRadioGroup>
        </ElFormItem>
        <ElFormItem :label="tl('status')">
          <ElRadioGroup v-model="form.status">
            <ElRadio :value="1">{{ tl('enable') }}</ElRadio>
            <ElRadio :value="0">{{ tl('disable') }}</ElRadio>
            <ElRadio :value="2">{{ tl('in_development') }}</ElRadio>
          </ElRadioGroup>
        </ElFormItem>
        <ElFormItem :label="tl('open_window')">
          <ElRadioGroup v-model="form.open_window">
            <ElRadio :value="1">{{ tl('enable') }}</ElRadio>
            <ElRadio :value="0">{{ tl('disable') }}</ElRadio>
          </ElRadioGroup>
        </ElFormItem>
      </div>

      <ElFormItem v-if="callbackVisible" :label="tl('callback_path')">
        <div v-if="callbackApplicable" class="w-full">
          <ElInput
            v-model="form.callback_path"
            :placeholder="callbackPlaceholder"
          />
          <div class="text-muted-foreground mt-1 break-all text-xs">
            {{ callbackPreview }}
          </div>
          <div class="text-muted-foreground text-xs">
            {{ tl('callback_path_hint') }}
          </div>
        </div>
        <span v-else>{{ callbackFallbackText }}</span>
      </ElFormItem>

      <ElFormItem :label="tl('memo')">
        <ElInput v-model="form.remark" />
      </ElFormItem>

      <ElFormItem :label="tl('display_game_provider')">
        <div class="max-h-[260px] w-full overflow-auto">
          <div
            v-for="a in agents"
            :key="a.agent_id"
            class="flex items-center gap-4 py-1"
          >
            <span class="w-32 truncate">{{ a.username }}</span>
            <ElRadioGroup v-model="agentStatus[a.agent_id]">
              <ElRadio :value="1">{{ tl('show') }}</ElRadio>
              <ElRadio :value="0">{{ tl('not_show') }}</ElRadio>
            </ElRadioGroup>
          </div>
        </div>
      </ElFormItem>
    </ElForm>
    <template #footer>
      <ElButton @click="visible = false">{{ tl('btn_back') }}</ElButton>
      <ElButton :loading="saving" type="primary" @click="handleSave">
        {{ tl('submit') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
