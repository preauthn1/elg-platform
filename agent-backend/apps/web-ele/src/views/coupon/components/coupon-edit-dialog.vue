<script lang="ts" setup>
import type { CouponPrize } from '#/api';

import { computed, reactive, ref } from 'vue';

import { useUserStore } from '@vben/stores';

import dayjs from 'dayjs';
import {
  ElButton,
  ElCheckbox,
  ElCollapse,
  ElCollapseItem,
  ElDatePicker,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  createCouponApi,
  getLanguagesApi,
  searchAgentOptionsApi,
  uploadCouponImageApi,
} from '#/api';
import { tl } from '#/locales';
import {
  IMAGE_ACCEPT,
  ImageUploadError,
  imageUploadText,
  readImageAsDataUrl,
} from '#/utils/image-upload';

const emit = defineEmits<{ success: [] }>();

const userStore = useUserStore();

const MAX_QUANTITY = 1000;

const visible = ref(false);
const saving = ref(false);

const form = reactive(makeDefaults());

function makeDefaults() {
  return {
    agent_id: '' as number | string,
    all_downline: false,
    amount: '',
    end_time: dayjs().add(1, 'month').format('YYYY-MM-DD 23:59:59'),
    flow_limit_multiple: '',
    mobile_daily_limit: '0',
    quantity: '',
    realname_required: false,
    receive_limit: '1',
    reward_mode: 1 as number | string,
    start_time: dayjs().add(8, 'hour').format('YYYY-MM-DD 00:00:00'),
    type: 1 as number | string,
  };
}

const isRandom = computed(() => Number(form.reward_mode) === 2);

/* ───────── 隨機獎勵獎池 ───────── */
type PrizeRow = {
  amount: string;
  flow_limit_multiple: string;
  images: Record<string, string>;
  point_type: number | string;
  quantity: string;
  weight: string;
};

const languages = ref<Array<{ code: string; id: number; name: string }>>([]);
const prizes = ref<PrizeRow[]>([]);
const prizeInput = reactive({
  amount: '',
  flow_limit_multiple: '',
  point_type: 1 as number | string,
  quantity: '',
  weight: '',
});
const activeImageCards = ref<string[]>([]);

const totalWeight = computed(() =>
  prizes.value.reduce((sum, p) => sum + (Number(p.weight) || 0), 0),
);
function probability(p: { weight?: number | string }) {
  const w = Number(p.weight) || 0;
  return totalWeight.value > 0
    ? `${((w / totalWeight.value) * 100).toFixed(2)}%`
    : '0.00%';
}
function pointTypeText(t: number | string) {
  return Number(t) === 2 ? tl('rolling_chip') : tl('platform_point');
}
function quantityText(q: string) {
  return Number(q) > 0 ? q : tl('unlimited');
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

/** 數量 1~1000 整數 */
function onQuantityInput() {
  const v = String(form.quantity).replaceAll(/\D/g, '');
  form.quantity =
    v === '' ? '' : String(Math.min(Math.max(Number(v), 1), MAX_QUANTITY));
}
/** 金額：數字 + 最多兩位小數 */
function onAmountInput() {
  form.amount = sanitizeAmount(form.amount);
}
/** 領取/每日限制 0~1000 整數 */
function clampLimit(key: 'mobile_daily_limit' | 'receive_limit') {
  const v = String(form[key]).replaceAll(/\D/g, '');
  form[key] =
    v === '' ? '0' : String(Math.min(Math.max(Number(v), 0), MAX_QUANTITY));
}
function onFlowInput() {
  form.flow_limit_multiple = String(form.flow_limit_multiple).replaceAll(
    /\D/g,
    '',
  );
}

/* 獎項輸入欄的清洗 */
function sanitizeAmount(v: string) {
  return String(v)
    .replaceAll(/[^\d.]/g, '')
    .replaceAll(/(\..*?)\..*/g, '$1')
    .replace(/^(\d+\.\d{0,2}).*/, '$1');
}
function onPrizeAmountInput() {
  prizeInput.amount = sanitizeAmount(prizeInput.amount);
}
function onPrizeWeightInput() {
  let v = sanitizeAmount(prizeInput.weight);
  if (v !== '' && Number(v) > 10_000) v = '10000';
  prizeInput.weight = v;
}
function onPrizeIntInput(key: 'flow_limit_multiple' | 'quantity') {
  prizeInput[key] = String(prizeInput[key]).replaceAll(/\D/g, '');
}

function addPrize() {
  if (prizeInput.amount === '' || Number(prizeInput.amount) <= 0) {
    ElMessage.warning(tl('prize_amount'));
    return;
  }
  if (
    prizeInput.weight === '' ||
    Number(prizeInput.weight) < 1 ||
    Number(prizeInput.weight) > 10_000
  ) {
    ElMessage.warning(tl('prize_weight'));
    return;
  }
  prizes.value.push({
    amount: prizeInput.amount,
    flow_limit_multiple:
      Number(prizeInput.point_type) === 1
        ? prizeInput.flow_limit_multiple || '0'
        : '0',
    images: {},
    point_type: prizeInput.point_type,
    quantity: prizeInput.quantity || '0',
    weight: prizeInput.weight,
  });
  prizeInput.amount = '';
  prizeInput.weight = '';
  prizeInput.quantity = '';
  prizeInput.flow_limit_multiple = '';
}

function removePrize(idx: number) {
  prizes.value.splice(idx, 1);
}

/** 上傳獎項圖片：選檔 → base64 → 上傳 → 填入該語系網址 */
async function onPrizeImageFile(
  e: Event,
  idx: number,
  langId: number | string,
) {
  const input = e.target as HTMLInputElement;
  const file = input.files?.[0];
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
  } finally {
    input.value = '';
  }
  const res = await uploadCouponImageApi(base64);
  const target = prizes.value[idx];
  if (Number(res.code) === 1000 && res.data?.path && target) {
    target.images[String(langId)] = res.data.path;
  } else {
    ElMessage.error(res.message || tl('failure'));
  }
}

async function handleSave() {
  if (isRandom.value && prizes.value.length === 0) {
    ElMessage.warning(tl('please_config_prize'));
    return;
  }
  saving.value = true;
  try {
    const payloadPrizes: CouponPrize[] | undefined = isRandom.value
      ? prizes.value.map((p) => ({
          amount: p.amount,
          flow_limit_multiple: p.flow_limit_multiple,
          images: Object.fromEntries(
            Object.entries(p.images).filter(([, url]) => url),
          ),
          point_type: p.point_type,
          quantity: p.quantity,
          weight: p.weight,
        }))
      : undefined;
    const res = await createCouponApi({
      agent_id: form.agent_id,
      all_downline: form.all_downline ? 1 : 0,
      amount: isRandom.value ? 0 : form.amount,
      end_time: form.end_time,
      // type 非平台點數時稽核流水倍數不適用
      flow_limit_multiple:
        !isRandom.value && Number(form.type) === 1
          ? form.flow_limit_multiple
          : '',
      mobile_daily_limit: form.mobile_daily_limit,
      prizes: payloadPrizes,
      quantity: form.quantity,
      realname_required: form.realname_required ? 1 : 0,
      receive_limit: form.receive_limit,
      reward_mode: form.reward_mode,
      start_time: form.start_time,
      type: isRandom.value ? 1 : form.type,
    });
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

async function open() {
  Object.assign(form, makeDefaults());
  prizes.value = [];
  Object.assign(prizeInput, {
    amount: '',
    flow_limit_multiple: '',
    point_type: 1,
    quantity: '',
    weight: '',
  });
  // 預設帶入登入代理本人
  const info = userStore.userInfo as any;
  if (info?.id) {
    form.agent_id = info.id;
    agentOptions.value = [
      {
        id: info.id,
        username: info.username ?? info.realName ?? String(info.id),
      },
    ];
  } else {
    agentOptions.value = [];
  }
  visible.value = true;
  if (languages.value.length === 0) {
    const langs = await getLanguagesApi();
    if (Array.isArray(langs)) languages.value = langs;
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="`${tl('menu_coupon')} - ${tl('add')}`"
    top="5vh"
    width="900"
  >
    <ElForm label-position="top" @submit.prevent="handleSave">
      <div class="grid grid-cols-1 gap-x-4 md:grid-cols-3">
        <ElFormItem :label="tl('agent_account')">
          <ElSelect
            v-model="form.agent_id"
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
        <ElFormItem>
          <ElCheckbox v-model="form.all_downline">
            {{ tl('apply_to_all_downline') }}
          </ElCheckbox>
        </ElFormItem>
        <ElFormItem :label="tl('reward_mode')">
          <ElSelect v-model="form.reward_mode" class="w-full">
            <ElOption :label="tl('fixed_amount_reward')" :value="1" />
            <ElOption :label="tl('random_reward')" :value="2" />
          </ElSelect>
        </ElFormItem>

        <!-- 固定金額欄位 -->
        <template v-if="!isRandom">
          <ElFormItem :label="tl('type')">
            <ElSelect v-model="form.type" class="w-full">
              <ElOption :label="tl('platform_point')" :value="1" />
              <ElOption :label="tl('rolling_chip')" :value="2" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('denomination')">
            <ElInput v-model="form.amount" @input="onAmountInput" />
          </ElFormItem>
          <ElFormItem :label="tl('event_flow_limit_multiple')">
            <ElInput
              v-model="form.flow_limit_multiple"
              :disabled="Number(form.type) !== 1"
              @input="onFlowInput"
            />
          </ElFormItem>
        </template>

        <ElFormItem :label="tl('quantity')">
          <ElInput v-model="form.quantity" @input="onQuantityInput">
            <template #append>≤ {{ MAX_QUANTITY }}</template>
          </ElInput>
        </ElFormItem>
        <ElFormItem :label="tl('member_claim_limit')">
          <ElInput
            v-model="form.receive_limit"
            @input="clampLimit('receive_limit')"
          />
          <div class="text-muted-foreground mt-1 text-xs">
            {{ tl('unlimited_value').replace('{value}', '0') }}
          </div>
        </ElFormItem>
        <ElFormItem :label="tl('mobile_daily_claim_limit')">
          <ElInput
            v-model="form.mobile_daily_limit"
            @input="clampLimit('mobile_daily_limit')"
          />
          <div class="text-muted-foreground mt-1 text-xs">
            {{ tl('unlimited_value').replace('{value}', '0') }}
          </div>
        </ElFormItem>
        <ElFormItem>
          <ElCheckbox v-model="form.realname_required">
            {{ tl('block_duplicate_realname') }}
          </ElCheckbox>
        </ElFormItem>
        <ElFormItem :label="tl('label_start_time')">
          <ElDatePicker
            v-model="form.start_time"
            class="!w-full"
            format="YYYY-MM-DD HH:mm:ss"
            type="datetime"
            value-format="YYYY-MM-DD HH:mm:ss"
          />
        </ElFormItem>
        <ElFormItem :label="tl('label_end_time')">
          <ElDatePicker
            v-model="form.end_time"
            class="!w-full"
            format="YYYY-MM-DD HH:mm:ss"
            type="datetime"
            value-format="YYYY-MM-DD HH:mm:ss"
          />
        </ElFormItem>
      </div>

      <!-- 隨機獎勵設定 -->
      <div v-if="isRandom" class="mt-2">
        <hr class="mb-3" />
        <h4 class="mb-3 font-medium">{{ tl('random_reward') }}</h4>
        <div class="grid grid-cols-2 items-end gap-3 md:grid-cols-6">
          <ElFormItem :label="tl('type')" class="!mb-0">
            <ElSelect v-model="prizeInput.point_type" class="w-full">
              <ElOption :label="tl('platform_point')" :value="1" />
              <ElOption :label="tl('rolling_chip')" :value="2" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('prize_amount')" class="!mb-0">
            <ElInput v-model="prizeInput.amount" @input="onPrizeAmountInput" />
          </ElFormItem>
          <ElFormItem :label="tl('prize_weight')" class="!mb-0">
            <ElInput
              v-model="prizeInput.weight"
              placeholder="1-10000"
              @input="onPrizeWeightInput"
            />
          </ElFormItem>
          <ElFormItem :label="tl('prize_quantity')" class="!mb-0">
            <ElInput
              v-model="prizeInput.quantity"
              placeholder="0"
              @input="onPrizeIntInput('quantity')"
            />
          </ElFormItem>
          <ElFormItem :label="tl('event_flow_limit_multiple')" class="!mb-0">
            <ElInput
              v-model="prizeInput.flow_limit_multiple"
              :disabled="Number(prizeInput.point_type) !== 1"
              placeholder="0"
              @input="onPrizeIntInput('flow_limit_multiple')"
            />
          </ElFormItem>
          <ElButton type="primary" @click="addPrize">
            {{ tl('add_prize') }}
          </ElButton>
        </div>

        <ElTable :data="prizes" border class="mt-3" size="small">
          <ElTableColumn
            :formatter="(row) => pointTypeText(row.point_type)"
            :label="tl('type')"
            width="100"
          />
          <ElTableColumn :label="tl('prize_amount')" prop="amount" />
          <ElTableColumn :label="tl('prize_weight')" prop="weight" />
          <ElTableColumn :label="tl('win_probability')">
            <template #default="{ row }">{{ probability(row) }}</template>
          </ElTableColumn>
          <ElTableColumn
            :formatter="(row) => quantityText(row.quantity)"
            :label="tl('prize_quantity')"
          />
          <ElTableColumn
            :label="tl('event_flow_limit_multiple')"
            prop="flow_limit_multiple"
          />
          <ElTableColumn width="70">
            <template #default="{ $index }">
              <ElButton size="small" type="danger" @click="removePrize($index)">
                -
              </ElButton>
            </template>
          </ElTableColumn>
          <template #empty>{{ tl('please_config_prize') }}</template>
        </ElTable>

        <!-- 獎項圖片（多語系） -->
        <div v-if="prizes.length > 0" class="mt-3">
          <label class="mb-2 block">{{ tl('image') }}</label>
          <ElCollapse v-model="activeImageCards">
            <ElCollapseItem
              v-for="(p, idx) in prizes"
              :key="idx"
              :name="String(idx)"
              :title="`#${idx + 1} ${pointTypeText(p.point_type)} ${p.amount}`"
            >
              <div
                v-for="lang in languages"
                :key="lang.id"
                class="mb-2 flex items-center gap-2"
              >
                <span class="w-20 shrink-0 text-sm">{{ lang.name }}</span>
                <ElInput
                  v-model="p.images[String(lang.id)]"
                  :placeholder="`${tl('image')} URL`"
                />
                <label class="el-button el-button--small cursor-pointer">
                  {{ tl('upload_image') }}
                  <input
                    :accept="IMAGE_ACCEPT"
                    class="hidden"
                    type="file"
                    @change="onPrizeImageFile($event, idx, lang.id)"
                  />
                </label>
                <img
                  v-if="p.images[String(lang.id)]"
                  :src="p.images[String(lang.id)]"
                  class="h-12 rounded border"
                />
              </div>
              <div class="text-muted-foreground mt-1 text-xs">
                {{ imageUploadText('image_upload_hint') }}
              </div>
            </ElCollapseItem>
          </ElCollapse>
        </div>
      </div>
    </ElForm>
    <template #footer>
      <ElButton @click="visible = false">{{ tl('cancel') }}</ElButton>
      <ElButton :loading="saving" type="success" @click="handleSave">
        {{ tl('btn_update') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
