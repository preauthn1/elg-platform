<script lang="ts" setup>
import { computed, reactive, ref, watch } from 'vue';

import {
  ElButton,
  ElDatePicker,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElInputNumber,
  ElMessage,
  ElOption,
  ElRadio,
  ElRadioGroup,
  ElSelect,
  ElSwitch,
  ElTooltip,
} from 'element-plus';

import {
  getAgentGroupDropdownApi,
  getGgrConditionDropdownApi,
  getGgrPlanViewApi,
  getGgrVendorSettingDropdownApi,
  saveGgrPlanApi,
} from '#/api';
import { useAgentOptions } from '#/composables/use-agent-options';
import { tl } from '#/locales';

import ConditionEditDialog from '../../condition_setting/components/condition-edit-dialog.vue';
import VendorSettingEditDialog from '../../vendor_setting/components/vendor-setting-edit-dialog.vue';
import {
  blankForm,
  firstError,
  formToPayload,
  SWITCH_SECTIONS,
  viewToForm,
} from '../form-model';

/**
 * 4121 建立/編輯彈窗，版位對齊參考站 /affiliate/ggr-setting/create：
 * 類型列在最上、雙欄卡片（基本資料｜金額設定、帳戶驗證限制含領取前驗證
 * 子區跨全寬）、成員下線設置與特殊用戶設定為全寬表格卡。發放方式卡
 * （自動審核/自動發放/有效天數）已隨審核概念移除（2026-08-31 定案）。
 *
 * 次數欄的操作規則照參考站實測：上限＝該區已開啟的開關數，該區全關時停用並
 * 歸零；關掉開關使上限下降時，已填值自動夾回新上限。參考站的「留空」在這裡
 * 以 0 表示（欄位不可為 null），所以下限留 0 讓使用者能退回未設定。
 *
 * ⊞ 在表頭旁：就地開啟批次一的建立彈窗快速新增（成員下線成功後自動選中；
 * 特殊用戶表頭的 ⊞ 只重載選項）。選中值後選擇框旁出現 ✎＝就地編輯該筆。
 * 特殊用戶＝可增刪列（代理下拉共用 useAgentOptions，回填時整批 pin 避免
 * label 掉成 id）。copy 模式回填後清 id 走 create。
 */

const emit = defineEmits<{ success: [] }>();

const visible = ref(false);
const editId = ref<number | string>('');
const mode = ref<'copy' | 'edit'>('edit');
const isEdit = computed(() => mode.value === 'edit' && Boolean(editId.value));
const saving = ref(false);
const loading = ref(false);

const form = reactive(blankForm());

/**
 * 違規次數上限＝已開啟的唯一限制開關數（參考站規則）：全關時停用並歸零，
 * 上限下降時把已填值夾回去，避免留下大於開關數的無效設定。
 */
const kycEnabledCount = computed(
  () => SWITCH_SECTIONS.kyc.filter((item) => form[item.field]).length,
);

/** 把違規次數夾回上限（開關切換時、以及編輯/複製回填舊資料後都要跑） */
function clampCounts() {
  if (form.kycViolateCount > kycEnabledCount.value) {
    form.kycViolateCount = kycEnabledCount.value;
  }
}

watch(kycEnabledCount, clampCounts);

/**
 * 開始日期不可回填過去（後端同規則）：回填會讓方案首輪一次補出全部歷史期間，
 * 並在下一輪發放排程全數入帳，已發放的列無法再由重跑修正。
 * 編輯既有方案時原本的日期仍會顯示，只是不能再往前挑。
 */
function disableBackDated(date: Date) {
  const today = new Date();
  today.setHours(0, 0, 0, 0);

  return date.getTime() < today.getTime();
}

/** 代理群組（個性化設置組別 type=1；API 走舊端點、回裸陣列） */
const agentGroups = ref<{ id: number; name: string }[]>([]);
/** 4123/4124 下拉（啟用中、限本總代） */
const vendorSettingOptions = ref<{ id: number; name: string }[]>([]);
const conditionOptions = ref<
  { id: number; max_percentage?: string; name: string }[]
>([]);

/**
 * 有效分潤率唯讀試算（2026-09-01 工單新增）：條件設置最高階應得百分比 ×
 * 代理設置GGR%，另算損益兩平點＝手續費 ÷ (有效分潤率 × (1−管理費%))。
 * 純顯示不進 payload；pinMissingOption 注入的停用項沒有 max_percentage，
 * 視同未綁定。任何來源變動即時重算，不得 NaN／除零。
 */
const effectiveRatePreview = computed(() => {
  const bound = conditionOptions.value.find(
    (option) => option.id === Number(form.downlineConditionId),
  );
  const maxPercentage = Number(bound?.max_percentage);
  if (!bound || !Number.isFinite(maxPercentage)) {
    return { state: 'unbound' as const };
  }
  const ggrRate = Number(form.ggrRate);
  const rate =
    Number.isFinite(ggrRate) && ggrRate >= 0
      ? (maxPercentage / 100) * (ggrRate / 100)
      : 0;
  if (rate <= 0) {
    return {
      maxPercentage,
      rate: 0,
      state: 'unreachable' as const,
    };
  }
  const adminFee = Number(form.adminFeeRate);
  const netFactor =
    1 - (Number.isFinite(adminFee) && adminFee > 0 ? adminFee / 100 : 0);
  const handlingFee = Number(form.handlingFee);
  const breakeven =
    netFactor > 0 && Number.isFinite(handlingFee) && handlingFee > 0
      ? handlingFee / (rate * netFactor)
      : 0;
  return {
    breakeven,
    handlingFee: Number.isFinite(handlingFee) ? handlingFee : 0,
    maxPercentage,
    rate,
    state: 'ok' as const,
  };
});

const {
  loading: agentSearching,
  options: agentOptions,
  pin: pinAgents,
  search: searchAgents,
} = useAgentOptions();

/** 就地新增/編輯 4123/4124 設定的嵌入彈窗 */
const vendorDialogRef = ref<InstanceType<typeof VendorSettingEditDialog>>();
const conditionDialogRef = ref<InstanceType<typeof ConditionEditDialog>>();
/** ⊞ 新增成功後要自動選中新項：記住開啟前的 id 集合，成功後找出新增的那筆 */
let vendorIdsBeforeCreate: Set<number> = new Set();
let conditionIdsBeforeCreate: Set<number> = new Set();

async function loadAgentGroups() {
  const groups = await getAgentGroupDropdownApi().catch(() => []);
  agentGroups.value = (groups ?? [])
    .filter((group) => Number(group.agent_group_type) === 1)
    .map((group) => ({ id: group.id, name: group.name }));
}

async function loadVendorSettingOptions() {
  const res = await getGgrVendorSettingDropdownApi().catch(() => null);
  vendorSettingOptions.value =
    res && Number(res.code) === 200 ? (res.data ?? []) : [];
}

async function loadConditionOptions() {
  const res = await getGgrConditionDropdownApi().catch(() => null);
  conditionOptions.value =
    res && Number(res.code) === 200 ? (res.data ?? []) : [];
}

/** ⊞：就地新增遊戲商設定；成功後重載下拉，assign 有給才自動選中新項 */
function quickCreateVendorSetting(assign: ((id: number) => void) | null) {
  vendorIdsBeforeCreate = new Set(vendorSettingOptions.value.map((o) => o.id));
  pendingVendorAssign = assign;
  vendorDialogRef.value?.open();
}

function quickCreateCondition(assign: ((id: number) => void) | null) {
  conditionIdsBeforeCreate = new Set(conditionOptions.value.map((o) => o.id));
  pendingConditionAssign = assign;
  conditionDialogRef.value?.open();
}

let pendingVendorAssign: ((id: number) => void) | null = null;
let pendingConditionAssign: ((id: number) => void) | null = null;

async function onVendorDialogSuccess() {
  await loadVendorSettingOptions();
  const added = vendorSettingOptions.value.find(
    (option) => !vendorIdsBeforeCreate.has(option.id),
  );
  if (added && pendingVendorAssign) {
    pendingVendorAssign(added.id);
  }
  pendingVendorAssign = null;
}

async function onConditionDialogSuccess() {
  await loadConditionOptions();
  const added = conditionOptions.value.find(
    (option) => !conditionIdsBeforeCreate.has(option.id),
  );
  if (added && pendingConditionAssign) {
    pendingConditionAssign(added.id);
  }
  pendingConditionAssign = null;
}

/** ✎：就地編輯當前選中的設定（未選中不動作） */
function quickEditVendorSetting(id: null | number) {
  if (!id) return;
  pendingVendorAssign = null;
  vendorDialogRef.value?.open(id);
}

function quickEditCondition(id: null | number) {
  if (!id) return;
  pendingConditionAssign = null;
  conditionDialogRef.value?.open(id);
}

function addSpecialRow() {
  form.specialAgents.push({
    agentId: null,
    conditionId: null,
    vendorSettingId: null,
  });
}

function removeSpecialRow(index: number) {
  form.specialAgents.splice(index, 1);
}

async function open(
  id: number | string = '',
  openMode: 'copy' | 'edit' = 'edit',
) {
  editId.value = id || '';
  mode.value = openMode;
  Object.assign(form, blankForm());
  form.specialAgents = [];
  visible.value = true;
  loading.value = true;

  try {
    const [viewRes] = await Promise.all([
      id ? getGgrPlanViewApi(id).catch(() => null) : null,
      loadAgentGroups(),
      loadVendorSettingOptions(),
      loadConditionOptions(),
    ]);

    if (id) {
      const detail =
        viewRes && Number(viewRes.code) === 200 ? viewRes.data : null;
      if (!detail) {
        ElMessage.error(viewRes?.message ?? tl('operation_failed'));
        visible.value = false;
        return;
      }
      Object.assign(form, viewToForm(detail));
      clampCounts();
      // copy 模式沿用內容但走 create（標題留白讓使用者重新命名）
      if (openMode === 'copy') {
        form.name = '';
      }
      // 已停用的既有綁定不在 dropdown 內，補進選項避免顯示裸 id（沿用原名）
      pinMissingOption(
        vendorSettingOptions,
        detail.downline_vendor_setting_id,
        detail.downline_vendor_setting_name,
      );
      pinMissingOption(
        conditionOptions,
        detail.downline_condition_id,
        detail.downline_condition_name,
      );
      for (const row of detail.special_agents ?? []) {
        pinMissingOption(
          vendorSettingOptions,
          row.vendor_setting_id,
          row.vendor_setting_name,
        );
        pinMissingOption(
          conditionOptions,
          row.condition_id,
          row.condition_name,
        );
      }
      // 特殊用戶的代理帳號 pin 進共用選項，否則 label 會掉成 id
      pinAgents(
        (detail.special_agents ?? []).map((row) => ({
          id: row.agent_id,
          username: row.agent_username ?? String(row.agent_id),
        })),
      );
    }
  } finally {
    loading.value = false;
  }
}

function pinMissingOption(
  target: { value: { id: number; name: string }[] },
  id?: number,
  name?: string,
) {
  if (!id) return;
  if (!target.value.some((option) => option.id === id)) {
    target.value = [{ id, name: name ?? String(id) }, ...target.value];
  }
}

async function handleSave() {
  const message = firstError(form);
  if (message) {
    ElMessage.error(message);
    return;
  }

  saving.value = true;
  try {
    const payload = formToPayload(form);
    if (isEdit.value) {
      payload.id = editId.value;
    }

    const res = await saveGgrPlanApi(payload, isEdit.value);
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
    :title="`${isEdit ? tl('edit') : tl('add')}${tl('menu_ggr_plan_setting')}`"
    top="4vh"
    width="1080"
  >
    <div v-loading="loading" class="max-h-[74vh] overflow-y-auto pr-1">
      <ElForm label-position="left" label-width="150px">
        <!-- 類型（獨立於卡片之上，對齊參考站） -->
        <div class="mb-2 font-bold">{{ tl('ggr_cycle') }}</div>
        <div class="border-border bg-muted/30 mb-3 rounded border px-4 py-3">
          <ElRadioGroup v-model="form.settlementCycle">
            <ElRadio :value="1">{{ tl('ggr_cycle_daily') }}</ElRadio>
            <ElRadio :value="2">{{ tl('ggr_cycle_weekly') }}</ElRadio>
            <ElRadio :value="3">{{ tl('ggr_cycle_monthly') }}</ElRadio>
          </ElRadioGroup>
        </div>

        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
          <!-- 基本資料 -->
          <div class="border-border bg-muted/30 rounded border p-4">
            <div class="mb-3 font-bold">{{ tl('basic_information') }}</div>
            <ElFormItem :label="tl('status')">
              <ElSwitch
                v-model="form.status"
                :active-text="tl('enable')"
                :inactive-text="tl('disable')"
              />
            </ElFormItem>
            <ElFormItem :label="tl('ggr_agent_group')" required>
              <ElSelect
                v-model="form.agentGroupId"
                :placeholder="tl('please_choose')"
                class="!w-full"
                clearable
                filterable
              >
                <ElOption
                  v-for="group in agentGroups"
                  :key="group.id"
                  :label="group.name"
                  :value="group.id"
                />
              </ElSelect>
            </ElFormItem>
            <ElFormItem :label="tl('ggr_plan_title')" required>
              <ElInput v-model="form.name" :placeholder="tl('please_enter')" />
            </ElFormItem>
            <ElFormItem :label="tl('ggr_start_date')" required>
              <ElDatePicker
                v-model="form.startDate"
                :disabled-date="disableBackDated"
                class="!w-full"
                format="YYYY-MM-DD"
                placement="bottom-start"
                type="date"
                value-format="YYYY-MM-DD"
              />
            </ElFormItem>
            <ElFormItem :label="tl('ggr_carry_negative')">
              <ElSwitch
                v-model="form.carryNegative"
                :active-text="tl('yes')"
                :inactive-text="tl('no')"
              />
            </ElFormItem>
          </div>

          <!-- 金額設定 -->
          <div class="border-border bg-muted/30 rounded border p-4">
            <div class="mb-3 font-bold">{{ tl('ggr_amount_section') }}</div>
            <ElFormItem>
              <template #label>
                <span class="flex items-center gap-1">
                  {{ tl('ggr_admin_fee') }}
                  <ElTooltip :content="tl('ggr_tip_admin_fee')" placement="top">
                    <span class="text-muted-foreground cursor-help">ⓘ</span>
                  </ElTooltip>
                </span>
              </template>
              <ElInput
                v-model="form.adminFeeRate"
                :placeholder="tl('please_enter')"
              />
            </ElFormItem>
            <ElFormItem>
              <template #label>
                <span class="flex items-center gap-1">
                  {{ tl('handling_fee') }}
                  <ElTooltip
                    :content="tl('ggr_tip_handling_fee')"
                    placement="top"
                  >
                    <span class="text-muted-foreground cursor-help">ⓘ</span>
                  </ElTooltip>
                </span>
              </template>
              <ElInput
                v-model="form.handlingFee"
                :placeholder="tl('please_enter')"
              />
            </ElFormItem>
            <ElFormItem>
              <template #label>
                <span class="flex items-center gap-1">
                  {{ tl('ggr_rate_label') }}
                  <ElTooltip :content="tl('ggr_tip_ggr_rate')" placement="top">
                    <span class="text-muted-foreground cursor-help">ⓘ</span>
                  </ElTooltip>
                </span>
              </template>
              <ElInput
                v-model="form.ggrRate"
                :placeholder="tl('please_enter')"
              />
              <!-- 有效分潤率唯讀試算：不影響任何計算，藏起來也不改結算數字 -->
              <div class="text-muted-foreground mt-1 text-xs leading-5">
                <template v-if="effectiveRatePreview.state === 'unbound'">
                  {{ tl('ggr_no_condition_bound') }}
                </template>
                <template v-else>
                  <div>
                    {{ tl('ggr_effective_rate') }}:
                    <b>{{ (effectiveRatePreview.rate! * 100).toFixed(2) }}%</b>
                    （{{ tl('ggr_effective_rate_basis') }}
                    {{ effectiveRatePreview.maxPercentage }}%）
                  </div>
                  <div v-if="effectiveRatePreview.state === 'unreachable'">
                    {{ tl('ggr_breakeven_unreachable') }}
                  </div>
                  <div v-else-if="effectiveRatePreview.breakeven! > 0">
                    {{ tl('ggr_breakeven_prefix') }}
                    <b>{{ effectiveRatePreview.breakeven!.toFixed(2) }}</b>
                    {{ tl('ggr_breakeven_suffix') }}
                    {{ effectiveRatePreview.handlingFee }}
                  </div>
                </template>
              </div>
            </ElFormItem>
            <ElFormItem>
              <template #label>
                <span class="flex items-center gap-1">
                  {{ tl('ggr_bonus_rate') }}
                  <ElTooltip
                    :content="tl('ggr_tip_bonus_rate')"
                    placement="top"
                  >
                    <span class="text-muted-foreground cursor-help">ⓘ</span>
                  </ElTooltip>
                </span>
              </template>
              <ElInput
                v-model="form.bonusRate"
                :placeholder="tl('please_enter')"
              />
            </ElFormItem>
          </div>

          <!-- 帳戶驗證限制（系統可驗的 5 項）＋領取前驗證條件子區（發放方式卡已隨審核概念移除，本卡跨兩欄補位） -->
          <div
            class="border-border bg-muted/30 rounded border p-4 md:col-span-2"
          >
            <div class="mb-3 flex items-center gap-1 font-bold">
              {{ tl('ggr_kyc_section') }}
              <ElTooltip :content="tl('ggr_tip_kyc')" placement="top">
                <span class="text-muted-foreground cursor-help font-normal">
                  ⓘ
                </span>
              </ElTooltip>
            </div>
            <div class="grid grid-cols-1 gap-x-6 gap-y-3 md:grid-cols-2">
              <div
                v-for="item in SWITCH_SECTIONS.kyc"
                :key="item.field"
                class="flex items-center justify-between gap-2"
              >
                <span class="text-sm">{{ tl(item.labelKey) }}</span>
                <ElSwitch v-model="form[item.field]" />
              </div>
            </div>
            <div class="mt-3 md:w-1/2 md:pr-3">
              <div class="flex items-center justify-between gap-2">
                <span class="text-sm">{{ tl('ggr_violate_count') }}</span>
                <ElInputNumber
                  v-model="form.kycViolateCount"
                  :disabled="kycEnabledCount === 0"
                  :max="kycEnabledCount"
                  :min="0"
                  :value-on-clear="0"
                  class="!w-28"
                  controls-position="right"
                />
              </div>
            </div>
          </div>
        </div>
      </ElForm>

      <!-- 成員下線設置：⊞ 在表頭（新增後自動選中）、選中後選擇框旁出現 ✎ -->
      <div class="border-border bg-muted/30 mt-3 rounded border p-4">
        <div class="mb-3 flex items-center gap-1 font-bold">
          {{ tl('ggr_downline_section') }}
          <ElTooltip :content="tl('ggr_tip_downline')" placement="top">
            <span class="text-muted-foreground cursor-help font-normal">
              ⓘ
            </span>
          </ElTooltip>
        </div>
        <div
          class="border-border bg-muted text-muted-foreground grid grid-cols-2 gap-2 rounded-t border px-3 py-2 text-sm font-medium"
        >
          <div class="flex items-center gap-2">
            <span>{{ tl('menu_ggr_vendor_setting') }}</span>
            <ElTooltip :content="tl('ggr_quick_create')" placement="top">
              <ElButton
                circle
                plain
                size="small"
                type="success"
                @click="
                  quickCreateVendorSetting(
                    (id) => (form.downlineVendorSettingId = id),
                  )
                "
              >
                +
              </ElButton>
            </ElTooltip>
          </div>
          <div class="flex items-center gap-2">
            <span>{{ tl('menu_ggr_condition_setting') }}</span>
            <ElTooltip :content="tl('ggr_quick_create')" placement="top">
              <ElButton
                circle
                plain
                size="small"
                type="success"
                @click="
                  quickCreateCondition((id) => (form.downlineConditionId = id))
                "
              >
                +
              </ElButton>
            </ElTooltip>
          </div>
        </div>
        <div
          class="border-border grid grid-cols-2 gap-2 rounded-b border border-t-0 px-3 py-3"
        >
          <div class="flex items-center gap-1">
            <ElSelect
              v-model="form.downlineVendorSettingId"
              :placeholder="tl('please_choose')"
              class="!w-full"
              clearable
              filterable
            >
              <ElOption
                v-for="option in vendorSettingOptions"
                :key="option.id"
                :label="option.name"
                :value="option.id"
              />
            </ElSelect>
            <ElButton
              v-if="form.downlineVendorSettingId"
              :title="tl('ggr_quick_edit')"
              plain
              size="small"
              type="success"
              @click="quickEditVendorSetting(form.downlineVendorSettingId)"
            >
              ✎
            </ElButton>
          </div>
          <div class="flex items-center gap-1">
            <ElSelect
              v-model="form.downlineConditionId"
              :placeholder="tl('please_choose')"
              class="!w-full"
              clearable
              filterable
            >
              <ElOption
                v-for="option in conditionOptions"
                :key="option.id"
                :label="option.name"
                :value="option.id"
              />
            </ElSelect>
            <ElButton
              v-if="form.downlineConditionId"
              :title="tl('ggr_quick_edit')"
              plain
              size="small"
              type="success"
              @click="quickEditCondition(form.downlineConditionId)"
            >
              ✎
            </ElButton>
          </div>
        </div>
      </div>

      <!-- 特殊用戶設定：⊞ 在表頭（只重載選項）、列尾紅色刪除、新增列在表格下方 -->
      <div class="border-border bg-muted/30 mt-3 rounded border p-4">
        <div class="mb-3 flex items-center gap-1 font-bold">
          {{ tl('ggr_special_section') }}
          <ElTooltip :content="tl('ggr_tip_special')" placement="top">
            <span class="text-muted-foreground cursor-help font-normal">
              ⓘ
            </span>
          </ElTooltip>
        </div>
        <div
          class="border-border bg-muted text-muted-foreground grid grid-cols-[1fr_1fr_1fr_50px] gap-2 rounded-t border px-3 py-2 text-sm font-medium"
        >
          <span class="flex items-center">{{ tl('ggr_special_agent') }}</span>
          <div class="flex items-center gap-2">
            <span>{{ tl('menu_ggr_vendor_setting') }}</span>
            <ElTooltip :content="tl('ggr_quick_create')" placement="top">
              <ElButton
                circle
                plain
                size="small"
                type="success"
                @click="quickCreateVendorSetting(null)"
              >
                +
              </ElButton>
            </ElTooltip>
          </div>
          <div class="flex items-center gap-2">
            <span>{{ tl('menu_ggr_condition_setting') }}</span>
            <ElTooltip :content="tl('ggr_quick_create')" placement="top">
              <ElButton
                circle
                plain
                size="small"
                type="success"
                @click="quickCreateCondition(null)"
              >
                +
              </ElButton>
            </ElTooltip>
          </div>
          <span></span>
        </div>
        <div class="border-border rounded-b border border-t-0">
          <div
            v-if="form.specialAgents.length === 0"
            class="p-4 text-center text-sm"
          >
            {{ tl('no_record') }}
          </div>
          <div
            v-for="(row, index) in form.specialAgents"
            :key="index"
            class="border-border grid grid-cols-[1fr_1fr_1fr_50px] items-center gap-2 border-b px-3 py-3 last:border-b-0"
          >
            <ElSelect
              v-model="row.agentId"
              :loading="agentSearching"
              :placeholder="tl('ggr_special_agent')"
              class="!w-full"
              clearable
              filterable
              remote
              :remote-method="searchAgents"
            >
              <ElOption
                v-for="agent in agentOptions"
                :key="agent.id"
                :label="agent.username"
                :value="agent.id"
              />
            </ElSelect>
            <div class="flex items-center gap-1">
              <ElSelect
                v-model="row.vendorSettingId"
                :placeholder="tl('please_choose')"
                class="!w-full"
                clearable
                filterable
              >
                <ElOption
                  v-for="option in vendorSettingOptions"
                  :key="option.id"
                  :label="option.name"
                  :value="option.id"
                />
              </ElSelect>
              <ElButton
                v-if="row.vendorSettingId"
                :title="tl('ggr_quick_edit')"
                plain
                size="small"
                type="success"
                @click="quickEditVendorSetting(row.vendorSettingId)"
              >
                ✎
              </ElButton>
            </div>
            <div class="flex items-center gap-1">
              <ElSelect
                v-model="row.conditionId"
                :placeholder="tl('please_choose')"
                class="!w-full"
                clearable
                filterable
              >
                <ElOption
                  v-for="option in conditionOptions"
                  :key="option.id"
                  :label="option.name"
                  :value="option.id"
                />
              </ElSelect>
              <ElButton
                v-if="row.conditionId"
                :title="tl('ggr_quick_edit')"
                plain
                size="small"
                type="success"
                @click="quickEditCondition(row.conditionId)"
              >
                ✎
              </ElButton>
            </div>
            <ElButton
              circle
              plain
              size="small"
              type="danger"
              @click="removeSpecialRow(index)"
            >
              −
            </ElButton>
          </div>
        </div>
        <div class="mt-3">
          <ElButton type="success" @click="addSpecialRow">
            ⊞ {{ tl('ggr_add_row') }}
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

    <!-- 就地新增/編輯 4123/4124 設定（複用批次一彈窗） -->
    <VendorSettingEditDialog
      ref="vendorDialogRef"
      @success="onVendorDialogSuccess"
    />
    <ConditionEditDialog
      ref="conditionDialogRef"
      @success="onConditionDialogSuccess"
    />
  </ElDialog>
</template>
