<script lang="ts" setup>
import { computed, ref } from 'vue';

import {
  ElButton,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElRadio,
  ElRadioGroup,
  ElSwitch,
  ElTooltip,
} from 'element-plus';

import { getGgrConditionViewApi, saveGgrConditionApi } from '#/api';
import { tl } from '#/locales';

import {
  isNonNegativeInt,
  isNonNegativeMoney,
  isWithinMax,
  MAX_AMOUNT,
  MAX_USER_COUNT,
} from '../../validation';

/**
 * 4124 建立/編輯彈窗：基本資料（狀態/標題/僅計算活躍用戶）＋類型（全 OR／全 AND）
 * ＋階梯列（用戶人數為必要條件；四項門檻為單一會員各自須達的值，0=無要求）。
 *
 * user_count 與 percentage 依列序嚴格遞增（欄位下方即時紅字），第一列不可刪。
 * copy 模式回填後清 id 走 create。
 */

interface TierForm {
  depositAmount: string;
  gameRevenueAmount: string;
  percentage: string;
  totalBetAmount: string;
  userCount: string;
  validBetAmount: string;
}

interface TierFormErrors {
  depositAmount?: string;
  gameRevenueAmount?: string;
  percentage?: string;
  totalBetAmount?: string;
  userCount?: string;
  validBetAmount?: string;
}

const emit = defineEmits<{ success: [] }>();

const visible = ref(false);
const editId = ref<number | string>('');
const mode = ref<'copy' | 'edit'>('edit');
const isEdit = computed(() => mode.value === 'edit' && Boolean(editId.value));
const saving = ref(false);
const loading = ref(false);

const name = ref('');
const status = ref(false);
const activeUserOnly = ref(false);
const logicType = ref(1);
const tiers = ref<TierForm[]>([makeDefaultTier()]);

/** 預設值對齊示意圖（各欄 1） */
function makeDefaultTier(): TierForm {
  return {
    depositAmount: '1',
    gameRevenueAmount: '1',
    percentage: '1',
    totalBetAmount: '1',
    userCount: '1',
    validBetAmount: '1',
  };
}

/** 四項門檻間的連接詞跟著類型走（或者/和） */
const logicLabel = computed(() =>
  logicType.value === 2 ? tl('ggr_logic_and') : tl('ggr_logic_or'),
);

/**
 * 單列的即時驗證。上限與後端 Request 對齊（user_count 為 signed int、
 * 金額為 decimal(18,2)、percentage 上限 100），超出時要說「超出範圍」
 * 而不是含糊的「必填」——欄位明明有填。
 */
function tierErrorsAt(index: number): TierFormErrors {
  const tier = tiers.value[index];
  if (!tier) return {};
  const prev = index === 0 ? null : tiers.value[index - 1];
  const errors: TierFormErrors = {};

  // 用戶人數 0/留空＝不限制（2026-09-01 定案），不設逐階遞增——
  // 排序約束只綁應得百分比（工單需求1）
  const userCount = tier.userCount.trim();
  if (userCount !== '' && !isNonNegativeInt(userCount)) {
    errors.userCount = tl('ggr_field_required');
  } else if (userCount !== '' && !isWithinMax(userCount, MAX_USER_COUNT)) {
    errors.userCount = tl('ggr_value_out_of_range');
  }

  for (const field of [
    'depositAmount',
    'totalBetAmount',
    'gameRevenueAmount',
    'validBetAmount',
  ] as const) {
    // 金額 0/留空＝不限制（與填 0 行為一致），僅驗格式與上限
    const value = tier[field].trim();
    if (value !== '' && !isNonNegativeMoney(value)) {
      errors[field] = tl('ggr_field_required');
    } else if (value !== '' && !isWithinMax(value, MAX_AMOUNT)) {
      errors[field] = tl('ggr_value_out_of_range');
    }
  }

  const percentage = tier.percentage.trim();
  if (!isNonNegativeMoney(percentage)) {
    errors.percentage = tl('ggr_field_required');
  } else if (Number(percentage) > 100) {
    errors.percentage = tl('ggr_value_out_of_range');
  } else if (prev && Number(percentage) <= Number(prev.percentage)) {
    errors.percentage = tl('ggr_must_exceed_prev');
  }

  return errors;
}

/** 送出前總擋一次用：第一個欄位級錯誤訊息，無錯回 null */
function firstError(): null | string {
  for (let index = 0; index < tiers.value.length; index += 1) {
    const message = Object.values(tierErrorsAt(index))[0];
    if (message) {
      return message;
    }
  }

  return null;
}

function addTier() {
  tiers.value.push(makeDefaultTier());
}

function removeTier(index: number) {
  tiers.value.splice(index, 1);
}

async function open(
  id: number | string = '',
  openMode: 'copy' | 'edit' = 'edit',
) {
  editId.value = id || '';
  mode.value = openMode;
  name.value = '';
  status.value = false;
  activeUserOnly.value = false;
  logicType.value = 1;
  tiers.value = [makeDefaultTier()];
  visible.value = true;

  if (!id) return;

  loading.value = true;
  try {
    const res = await getGgrConditionViewApi(id).catch(() => null);
    const detail = res && Number(res.code) === 200 ? res.data : null;
    if (!detail) {
      ElMessage.error(res?.message ?? tl('operation_failed'));
      visible.value = false;
      return;
    }

    // copy 模式沿用內容但走 create（標題留白讓使用者重新命名）
    name.value = openMode === 'copy' ? '' : detail.name;
    status.value = Number(detail.status) === 1;
    activeUserOnly.value = Number(detail.active_user_only) === 1;
    logicType.value = Number(detail.logic_type) === 2 ? 2 : 1;
    tiers.value = (detail.tiers ?? []).map((tier) => ({
      depositAmount: String(tier.deposit_amount),
      gameRevenueAmount: String(tier.game_revenue_amount),
      percentage: String(tier.percentage),
      totalBetAmount: String(tier.total_bet_amount),
      userCount: String(tier.user_count),
      validBetAmount: String(tier.valid_bet_amount),
    }));
    if (tiers.value.length === 0) {
      tiers.value = [makeDefaultTier()];
    }
  } finally {
    loading.value = false;
  }
}

async function handleSave() {
  if (!name.value.trim()) {
    ElMessage.error(`${tl('please_enter')} ${tl('ggr_condition_title')}`);
    return;
  }
  // 欄位級紅字已即時顯示，送出前整體再擋一次；報出第一個真正的錯誤原因，
  // 不要一律報「必須超過前一行」而蓋掉必填／超出範圍等其他情況。
  const error = firstError();
  if (error) {
    ElMessage.error(error);
    return;
  }

  saving.value = true;
  try {
    const payload: Record<string, unknown> = {
      active_user_only: activeUserOnly.value ? 1 : 0,
      logic_type: logicType.value,
      name: name.value.trim(),
      status: status.value ? 1 : 0,
      // 留空一律以 0（=不限制）送出，行為與填 0 完全一致（工單需求7）
      tiers: tiers.value.map((tier) => ({
        deposit_amount: tier.depositAmount.trim() || '0',
        game_revenue_amount: tier.gameRevenueAmount.trim() || '0',
        percentage: tier.percentage,
        total_bet_amount: tier.totalBetAmount.trim() || '0',
        user_count: tier.userCount.trim() || '0',
        valid_bet_amount: tier.validBetAmount.trim() || '0',
      })),
    };
    if (isEdit.value) {
      payload.id = editId.value;
    }

    const res = await saveGgrConditionApi(payload, isEdit.value);
    if (Number(res.code) === 200) {
      ElMessage.success(tl('operation_success'));
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
    :title="`${isEdit ? tl('edit') : tl('add')}${tl('menu_ggr_condition_setting')}`"
    top="6vh"
    width="980"
  >
    <div v-loading="loading">
      <!-- 版位對齊參考站：基本資料卡片（標籤靠左）＋ 類型/設置卡片 -->
      <div class="border-border mb-4 rounded border p-4">
        <div class="mb-3 text-sm font-semibold">
          {{ tl('basic_information') }}
        </div>
        <ElForm label-position="left" label-width="140px">
          <ElFormItem :label="tl('status')">
            <ElSwitch
              v-model="status"
              :active-text="tl('enable')"
              :inactive-text="tl('disable')"
            />
          </ElFormItem>
          <ElFormItem :label="tl('ggr_condition_title')" required>
            <ElInput v-model="name" :placeholder="tl('please_enter')" />
          </ElFormItem>
          <ElFormItem :label="tl('ggr_active_user_only')" class="!mb-0">
            <ElSwitch
              v-model="activeUserOnly"
              :active-text="tl('yes')"
              :inactive-text="tl('no')"
            />
          </ElFormItem>
        </ElForm>
      </div>

      <div class="border-border rounded border p-4">
        <div class="mb-2 text-sm font-semibold">{{ tl('type') }}</div>
        <ElRadioGroup v-model="logicType" class="mb-4">
          <ElRadio :value="1">{{ tl('ggr_logic_or') }}</ElRadio>
          <ElRadio :value="2">{{ tl('ggr_logic_and') }}</ElRadio>
        </ElRadioGroup>

        <div class="mb-2 text-sm font-semibold">
          {{ tl('ggr_settings_section') }}
        </div>

        <!-- 階梯列（版位對齊參考站）：欄位間以 ≈ 與 或者/和 分隔，連接詞跟著類型；用戶人數為必要條件、門檻 0=無要求 -->
        <div
          class="border-border bg-muted text-muted-foreground grid grid-cols-[1fr_24px_1fr_32px_1fr_32px_1fr_32px_1fr_24px_1fr_50px] items-center gap-1 rounded-t border px-3 py-2 text-sm font-medium"
        >
          <span class="flex items-center gap-1">
            {{ tl('ggr_user_count') }}
            <ElTooltip :content="tl('ggr_zero_no_user_hint')" placement="top">
              <span class="cursor-help text-xs">ⓘ</span>
            </ElTooltip>
          </span>
          <span></span>
          <span>{{ tl('ggr_deposit') }}</span>
          <span></span>
          <span>{{ tl('ggr_total_bet') }}</span>
          <span></span>
          <span>{{ tl('ggr_game_revenue') }}</span>
          <span></span>
          <span>{{ tl('ggr_valid_bet') }}</span>
          <span></span>
          <span>{{ tl('ggr_percentage') }}</span>
          <span></span>
        </div>
        <div class="border-border rounded-b border border-t-0">
          <div
            v-for="(tier, index) in tiers"
            :key="index"
            class="border-border grid grid-cols-[1fr_24px_1fr_32px_1fr_32px_1fr_32px_1fr_24px_1fr_50px] items-start gap-1 border-b px-3 py-3 last:border-b-0"
          >
            <div>
              <ElInput v-model="tier.userCount" />
              <div
                v-if="tierErrorsAt(index).userCount"
                class="text-destructive mt-1 text-xs"
              >
                {{ tierErrorsAt(index).userCount }}
              </div>
            </div>
            <span class="pt-1.5 text-center text-sm">≈</span>
            <div>
              <ElInput v-model="tier.depositAmount" />
              <div
                v-if="tierErrorsAt(index).depositAmount"
                class="text-destructive mt-1 text-xs"
              >
                {{ tierErrorsAt(index).depositAmount }}
              </div>
            </div>
            <span class="pt-1.5 text-center text-xs">{{ logicLabel }}</span>
            <div>
              <ElInput v-model="tier.totalBetAmount" />
              <div
                v-if="tierErrorsAt(index).totalBetAmount"
                class="text-destructive mt-1 text-xs"
              >
                {{ tierErrorsAt(index).totalBetAmount }}
              </div>
            </div>
            <span class="pt-1.5 text-center text-xs">{{ logicLabel }}</span>
            <div>
              <ElInput v-model="tier.gameRevenueAmount" />
              <div
                v-if="tierErrorsAt(index).gameRevenueAmount"
                class="text-destructive mt-1 text-xs"
              >
                {{ tierErrorsAt(index).gameRevenueAmount }}
              </div>
            </div>
            <span class="pt-1.5 text-center text-xs">{{ logicLabel }}</span>
            <div>
              <ElInput v-model="tier.validBetAmount" />
              <div
                v-if="tierErrorsAt(index).validBetAmount"
                class="text-destructive mt-1 text-xs"
              >
                {{ tierErrorsAt(index).validBetAmount }}
              </div>
            </div>
            <span class="pt-1.5 text-center text-sm">≈</span>
            <div>
              <ElInput v-model="tier.percentage" />
              <div
                v-if="tierErrorsAt(index).percentage"
                class="text-destructive mt-1 text-xs"
              >
                {{ tierErrorsAt(index).percentage }}
              </div>
            </div>
            <div class="pt-1">
              <ElButton
                v-if="index > 0"
                circle
                size="small"
                type="danger"
                @click="removeTier(index)"
              >
                -
              </ElButton>
            </div>
          </div>
        </div>

        <div class="mt-3">
          <ElButton type="success" @click="addTier">
            {{ tl('ggr_add_row') }}
          </ElButton>
        </div>
      </div>
    </div>

    <template #footer>
      <ElButton @click="visible = false">{{ tl('cancel') }}</ElButton>
      <ElButton :loading="saving" type="primary" @click="handleSave">
        {{ tl('save') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
