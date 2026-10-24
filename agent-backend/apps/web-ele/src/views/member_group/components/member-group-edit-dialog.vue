<script lang="ts" setup>
import type { MemberGroupBankAccountRow } from '#/api';

import { computed, reactive, ref } from 'vue';

import {
  ElButton,
  ElCheckbox,
  ElDialog,
  ElForm,
  ElFormItem,
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
} from 'element-plus';

import {
  getMemberGroupBankAccountsApi,
  getMemberGroupViewApi,
  saveMemberGroupApi,
} from '#/api';
import { useAgentOptions } from '#/composables/use-agent-options';
import { tl } from '#/locales';

defineOptions({ name: 'MemberGroupEditDialog' });

const emit = defineEmits<{ saved: [] }>();

const visible = ref(false);
const loading = ref(false);
const saving = ref(false);
const editId = ref<null | number | string>(null);
const activeTab = ref('group');

const form = reactive({
  agent_id: undefined as number | undefined,
  bank_whitelist_enabled: false,
  default_setting: false,
  name: '',
  remark: '',
  sequence: '',
  status: true,
});

/** 擁有者代理下拉（建立時可選；沿用後台代理下拉端點，回傳當前代理的下線） */
const {
  loading: agentSearching,
  options: agentOptions,
  pin: pinAgent,
  search: searchAgents,
  unpin: unpinAgent,
} = useAgentOptions();

/** 限額欄位（依區塊渲染）；值為 '' 或數字字串，勾「無限制」= -1 */
const LIMIT_SECTIONS: Array<{
  fields: Array<{ key: string; labelKey: string; noLabelKey: string }>;
  titleKey: string;
}> = [
  {
    titleKey: 'member_group_deposit_section',
    fields: [
      {
        key: 'deposit_min',
        labelKey: 'member_group_min_deposit',
        noLabelKey: 'member_group_no_min',
      },
      {
        key: 'deposit_max',
        labelKey: 'member_group_max_deposit',
        noLabelKey: 'member_group_no_max',
      },
      {
        key: 'deposit_daily_count',
        labelKey: 'member_group_daily_deposit_count',
        noLabelKey: 'member_group_no_max',
      },
      {
        key: 'deposit_daily_amount',
        labelKey: 'member_group_daily_deposit_amount',
        noLabelKey: 'member_group_no_max',
      },
    ],
  },
  {
    titleKey: 'member_group_withdrawal_section',
    fields: [
      {
        key: 'withdraw_min',
        labelKey: 'member_group_min_withdrawal',
        noLabelKey: 'member_group_no_min',
      },
      {
        key: 'withdraw_max',
        labelKey: 'member_group_max_withdrawal',
        noLabelKey: 'member_group_no_max',
      },
      {
        key: 'withdraw_daily_count',
        labelKey: 'member_group_daily_withdrawal_count',
        noLabelKey: 'member_group_no_max',
      },
      {
        key: 'withdraw_daily_amount',
        labelKey: 'daily_withdrawal_amount_limit',
        noLabelKey: 'member_group_no_max',
      },
    ],
  },
  {
    titleKey: 'member_group_transfer_section',
    fields: [
      {
        key: 'transfer_min',
        labelKey: 'member_group_transfer_min',
        noLabelKey: 'member_group_no_min',
      },
      {
        key: 'transfer_max',
        labelKey: 'member_group_transfer_max',
        noLabelKey: 'member_group_no_max',
      },
      {
        key: 'transfer_daily_count',
        labelKey: 'member_group_daily_transfer_count',
        noLabelKey: 'member_group_no_max',
      },
      {
        key: 'transfer_daily_amount',
        labelKey: 'member_group_daily_transfer_amount',
        noLabelKey: 'member_group_no_max',
      },
    ],
  },
];

const LIMIT_KEYS = LIMIT_SECTIONS.flatMap((s) => s.fields.map((f) => f.key));

const limits = reactive<Record<string, string>>({});
const noLimit = reactive<Record<string, boolean>>({});

/** 平台提款手續費階梯（輸入框值為字串）；type 0 = 固定金額、1 = 百分比 */
const FEE_TIER_MAX = 10;
const feeTiers = ref<Array<{ from: string; type: 0 | 1; value: string }>>([]);

function addFeeTier() {
  if (feeTiers.value.length >= FEE_TIER_MAX) return;
  feeTiers.value.push({ from: '', type: 1, value: '' });
}

function removeFeeTier(index: number) {
  feeTiers.value.splice(index, 1);
}

/** 實際效果：非最後一階顯示「第 from～(下一階 from−1) 筆」，最後一階顯示「第 from 筆起」 */
function feeTierEffect(index: number): string {
  const row = feeTiers.value[index];
  if (!row || row.from === '' || row.value === '') return '-';
  const next = feeTiers.value[index + 1];
  // 下一階起點未大於本階（順序錯誤，儲存時會被擋）→ 不算區間
  if (next && next.from !== '' && Number(next.from) <= Number(row.from)) {
    return '-';
  }
  const isPercent = row.type === 1;
  let key: string;
  let to = '';
  if (next && next.from !== '') {
    key = isPercent
      ? 'member_group_fee_tier_effect_percent_range'
      : 'member_group_fee_tier_effect_fixed_range';
    to = String(Number(next.from) - 1);
  } else {
    key = isPercent
      ? 'member_group_fee_tier_effect_percent_open'
      : 'member_group_fee_tier_effect_fixed_open';
  }
  return tl(key)
    .replace('{from}', row.from)
    .replace('{to}', to)
    .replace('{value}', row.value);
}

/** 逐列驗證階梯；回傳錯誤語系鍵，全數通過回傳 null */
function validateFeeTiers(): null | string {
  let prevFrom = 0;
  for (const row of feeTiers.value) {
    const from = Number(row.from);
    if (row.from === '' || !Number.isInteger(from) || from < 1) {
      return 'member_group_fee_tier_err_from';
    }
    if (from <= prevFrom) return 'member_group_fee_tier_err_order';
    prevFrom = from;
    const value = Number(row.value);
    const valueInvalid = row.value === '' || !Number.isFinite(value);
    if (row.type === 1) {
      if (valueInvalid || value < 0 || value > 100) {
        return 'member_group_fee_tier_err_percent';
      }
    } else if (valueInvalid || value < 0) {
      return 'member_group_fee_tier_err_fixed';
    }
  }
  return null;
}

// 銀行管理 tab
const bankRows = ref<MemberGroupBankAccountRow[]>([]);
const selectedBankIds = ref<number[]>([]);

const allChecked = computed({
  get() {
    return (
      bankRows.value.length > 0 &&
      bankRows.value.every((r) => selectedBankIds.value.includes(r.bank_id))
    );
  },
  set(val: boolean) {
    selectedBankIds.value = val ? bankRows.value.map((r) => r.bank_id) : [];
  },
});

function toggleBank(id: number) {
  selectedBankIds.value = selectedBankIds.value.includes(id)
    ? selectedBankIds.value.filter((v) => v !== id)
    : [...selectedBankIds.value, id];
}

function resetForm() {
  form.agent_id = undefined;
  form.name = '';
  form.remark = '';
  form.sequence = '';
  form.status = true;
  form.default_setting = false;
  form.bank_whitelist_enabled = false;
  for (const key of LIMIT_KEYS) {
    limits[key] = '';
    noLimit[key] = true;
  }
  feeTiers.value = [];
  selectedBankIds.value = [];
  activeTab.value = 'group';
}

async function open(id?: number | string) {
  visible.value = true;
  editId.value = id ?? null;
  resetForm();
  unpinAgent();

  loading.value = true;
  try {
    const bankRes = await getMemberGroupBankAccountsApi().catch(() => null);
    bankRows.value =
      bankRes && Number(bankRes.code) === 200 ? (bankRes.data ?? []) : [];

    await searchAgents('');

    if (id) {
      const res = await getMemberGroupViewApi(id);
      if (Number(res.code) === 200 && res.data) {
        const d = res.data;
        if (d.agent_id) {
          form.agent_id = Number(d.agent_id);
          pinAgent([
            {
              id: Number(d.agent_id),
              username: d.agent_name ?? String(d.agent_id),
            },
          ]);
        }
        form.name = d.name ?? '';
        form.remark = d.remark ?? '';
        form.sequence = String(d.sequence ?? '');
        form.status = Number(d.status) === 1;
        form.default_setting = Number(d.default_setting) === 1;
        form.bank_whitelist_enabled = Number(d.bank_whitelist_enabled) === 1;
        for (const key of LIMIT_KEYS) {
          const v = Number(d[key] ?? -1);
          if (v < 0) {
            noLimit[key] = true;
            limits[key] = '';
          } else {
            noLimit[key] = false;
            limits[key] = String(d[key]);
          }
        }
        feeTiers.value = (d.withdraw_fee_tiers ?? []).map((t) => ({
          from: String(t.from),
          type: Number(t.type) === 0 ? 0 : 1,
          // 後端存 5 位小數字串（"3.00000"），回填去掉尾零
          value: String(Number(t.value)),
        }));
        selectedBankIds.value = (d.bank_account_ids ?? []).map(Number);
      }
    }
  } finally {
    loading.value = false;
  }
}

async function handleSave() {
  if (!form.name.trim()) {
    ElMessage.error(`${tl('please_enter')}${tl('name')}`);
    return;
  }
  if (!editId.value && form.agent_id === undefined) {
    ElMessage.error(`${tl('please_choose')}${tl('agent_account')}`);
    return;
  }

  const tierError = validateFeeTiers();
  if (tierError) {
    ElMessage.error(tl(tierError));
    return;
  }
  // 階梯起點超過每日提款次數上限 → 該階永遠不會生效，先提示確認
  if (
    !noLimit.withdraw_daily_count &&
    limits.withdraw_daily_count !== '' &&
    limits.withdraw_daily_count !== undefined
  ) {
    const limit = Number(limits.withdraw_daily_count);
    const conflictIndex = feeTiers.value.findIndex(
      (r) => Number(r.from) > limit,
    );
    const conflict = feeTiers.value[conflictIndex];
    if (conflict) {
      try {
        await ElMessageBox.confirm(
          tl('member_group_fee_tier_conflict_confirm')
            .replace('{n}', String(conflictIndex + 1))
            .replace('{from}', conflict.from)
            .replace('{limit}', String(limits.withdraw_daily_count)),
          { type: 'warning' },
        );
      } catch {
        return;
      }
    }
  }

  saving.value = true;
  try {
    const data: Record<string, any> = {
      bank_account_ids: selectedBankIds.value,
      bank_whitelist_enabled: form.bank_whitelist_enabled ? 1 : 0,
      default_setting: form.default_setting ? 1 : 0,
      name: form.name.trim(),
      remark: form.remark,
      status: form.status ? 1 : 0,
    };
    if (form.sequence !== '') data.sequence = form.sequence;
    // 勾「無限制」或留空 = -1（不限制）
    for (const key of LIMIT_KEYS) {
      data[key] = noLimit[key] || limits[key] === '' ? -1 : Number(limits[key]);
    }
    // 擁有者僅建立時指定（編輯不可變更擁有者）
    if (!editId.value && form.agent_id !== undefined) {
      data.agent_id = form.agent_id;
    }
    if (editId.value) data.id = editId.value;
    // 平台提款手續費階梯；空陣列 = 清空（後端存 null）
    data.withdraw_fee_tiers = feeTiers.value.map((r) => ({
      from: Number(r.from),
      type: r.type,
      // 以字串送出：極小的小數若走 JSON number 會變科學記號、被後端 decimal 規則擋下
      value: r.value.trim(),
    }));

    const res = await saveMemberGroupApi(data, Boolean(editId.value));
    if (Number(res.code) === 200) {
      ElMessage.success(tl('success'));
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
    :title="`${tl('menu_member_group')} > ${editId ? tl('edit') : tl('add')}`"
    width="820"
    align-center
  >
    <div class="max-h-[74vh] overflow-y-auto pr-1">
      <ElForm v-loading="loading" :model="form" label-position="top">
        <ElTabs v-model="activeTab">
          <!-- 群組設定 -->
          <ElTabPane :label="tl('menu_member_group')" name="group">
            <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
              <ElFormItem :label="tl('agent_account')" required>
                <ElSelect
                  v-model="form.agent_id"
                  :debounce="500"
                  :disabled="!!editId"
                  :loading="agentSearching"
                  :remote-method="searchAgents"
                  class="w-full"
                  clearable
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
              <ElFormItem :label="tl('name')" required>
                <ElInput v-model="form.name" maxlength="50" />
              </ElFormItem>
            </div>
            <ElFormItem :label="tl('member_group_internal_remark')">
              <ElInput
                v-model="form.remark"
                :rows="3"
                maxlength="191"
                type="textarea"
              />
            </ElFormItem>
            <ElFormItem :label="tl('sorts')">
              <ElInput v-model="form.sequence" type="number" />
            </ElFormItem>

            <template v-for="section in LIMIT_SECTIONS" :key="section.titleKey">
              <hr class="mb-4 mt-6" />
              <div class="mb-2 font-bold">{{ tl(section.titleKey) }}</div>
              <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
                <ElFormItem
                  v-for="f in section.fields"
                  :key="f.key"
                  :label="tl(f.labelKey)"
                >
                  <div class="w-full">
                    <ElInput
                      v-model="limits[f.key]"
                      :disabled="noLimit[f.key]"
                      type="number"
                    />
                    <ElCheckbox v-model="noLimit[f.key]" size="small">
                      {{ tl(f.noLabelKey) }}
                    </ElCheckbox>
                  </div>
                </ElFormItem>
              </div>

              <!-- 平台提款手續費階梯（屬提款區塊，不另加分隔線） -->
              <div
                v-if="section.titleKey === 'member_group_withdrawal_section'"
                class="mb-4"
              >
                <div class="mb-1 font-bold">
                  {{ tl('member_group_withdraw_fee_tiers') }}
                </div>
                <div class="mb-2 text-xs text-gray-400">
                  {{ tl('member_group_fee_tier_hint') }}
                </div>
                <ElTable :data="feeTiers" border size="small">
                  <ElTableColumn align="center" label="#" width="50">
                    <template #default="{ $index }">{{ $index + 1 }}</template>
                  </ElTableColumn>
                  <ElTableColumn
                    :label="tl('member_group_fee_tier_from')"
                    min-width="120"
                  >
                    <template #default="{ row }">
                      <ElInput v-model="row.from" type="number" />
                    </template>
                  </ElTableColumn>
                  <ElTableColumn
                    :label="tl('member_group_fee_tier_type')"
                    min-width="120"
                  >
                    <template #default="{ row }">
                      <ElSelect v-model="row.type" class="w-full">
                        <ElOption
                          :label="tl('member_group_fee_tier_type_percent')"
                          :value="1"
                        />
                        <ElOption
                          :label="tl('member_group_fee_tier_type_fixed')"
                          :value="0"
                        />
                      </ElSelect>
                    </template>
                  </ElTableColumn>
                  <ElTableColumn
                    :label="tl('member_group_fee_tier_value')"
                    min-width="120"
                  >
                    <template #default="{ row }">
                      <ElInput v-model="row.value" type="number">
                        <template v-if="row.type === 1" #suffix>%</template>
                      </ElInput>
                    </template>
                  </ElTableColumn>
                  <ElTableColumn
                    :label="tl('member_group_fee_tier_effect')"
                    min-width="160"
                  >
                    <template #default="{ $index }">
                      {{ feeTierEffect($index) }}
                    </template>
                  </ElTableColumn>
                  <ElTableColumn
                    :label="tl('action')"
                    align="center"
                    width="80"
                  >
                    <template #default="{ $index }">
                      <ElButton
                        size="small"
                        type="danger"
                        @click="removeFeeTier($index)"
                      >
                        {{ tl('delete') }}
                      </ElButton>
                    </template>
                  </ElTableColumn>
                  <template #empty>{{ tl('no_record') }}</template>
                </ElTable>
                <div class="mt-2 flex items-center gap-2">
                  <ElButton
                    :disabled="feeTiers.length >= FEE_TIER_MAX"
                    size="small"
                    @click="addFeeTier"
                  >
                    + {{ tl('member_group_fee_tier_add') }}
                  </ElButton>
                  <span class="text-xs text-gray-400">
                    {{ tl('member_group_fee_tier_max_hint') }}
                  </span>
                </div>
              </div>
            </template>

            <hr class="mb-4 mt-6" />
            <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
              <ElFormItem :label="tl('status')">
                <ElSwitch v-model="form.status" />
              </ElFormItem>
              <ElFormItem :label="tl('member_group_set_default')">
                <ElSwitch v-model="form.default_setting" />
              </ElFormItem>
            </div>
          </ElTabPane>

          <!-- 銀行管理 -->
          <ElTabPane :label="tl('bank_management')" name="bank">
            <ElFormItem :label="tl('member_group_bank_whitelist')">
              <div class="w-full">
                <ElSwitch v-model="form.bank_whitelist_enabled" />
                <div class="text-xs text-gray-400">
                  {{ tl('member_group_bank_whitelist_hint') }}
                </div>
              </div>
            </ElFormItem>
            <ElTable
              :data="bankRows"
              border
              max-height="420"
              size="small"
              stripe
            >
              <ElTableColumn align="center" width="50">
                <template #header>
                  <ElCheckbox v-model="allChecked" />
                </template>
                <template #default="{ row }">
                  <ElCheckbox
                    :model-value="selectedBankIds.includes(row.bank_id)"
                    @change="() => toggleBank(row.bank_id)"
                  />
                </template>
              </ElTableColumn>
              <ElTableColumn
                :label="tl('bank_name')"
                align="center"
                prop="bank_name"
                min-width="120"
              />
              <ElTableColumn
                :label="tl('account_name')"
                align="center"
                prop="account_name"
                min-width="120"
              />
              <ElTableColumn
                :label="tl('account_no')"
                align="center"
                prop="account_no"
                min-width="140"
              />
              <template #empty>{{ tl('no_record') }}</template>
            </ElTable>
          </ElTabPane>
        </ElTabs>
      </ElForm>
    </div>
    <template #footer>
      <ElButton @click="visible = false">{{ tl('cancel') }}</ElButton>
      <ElButton :loading="saving" type="primary" @click="handleSave">
        {{ tl('btn_update') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
