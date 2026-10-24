<script lang="ts" setup>
import type {
  CsBankAccount,
  CsBankCard,
  CsBankCardData,
  CsBankCardSetting,
  CsBankOption,
} from '#/api/cs';

import { computed, reactive, ref, watch } from 'vue';

import { usePreferences } from '@vben/preferences';

import {
  ElButton,
  ElDialog,
  ElEmpty,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElMessageBox,
  ElOption,
  ElSelect,
} from 'element-plus';

import {
  deleteCsBankCardApi,
  getCsBankAccountApi,
  getCsBankCardApi,
  getCsBankCardSettingApi,
  getCsBankOptionsApi,
  saveCsBankAccountApi,
  saveCsBankCardApi,
} from '#/api/cs';
import { tl } from '#/locales';

defineOptions({ name: 'CsBankDialog' });

const props = defineProps<{
  editable: boolean;
  /** #4486 營運商唯讀，只顯示銀行；隱藏戶名、戶口號碼、BSB、複製鈕與寫入按鈕 */
  maskPrivate: boolean;
  modelValue: boolean;
  operatorId: number | string;
  username: string;
}>();
const emit = defineEmits<{ 'update:modelValue': [boolean] }>();

const { isMobile } = usePreferences();

const banks = ref<CsBankOption[]>([]);
const mode = ref<'account' | 'card'>('account');
const loading = ref(false);
const saving = ref(false);

// 單筆銀行帳號
const acc = reactive({
  account_name: '',
  account_no: '',
  bank_id: '' as number | string,
  bsb_code: '',
  bsb_code_alias: 'BSB',
  enable_bsb_code: 0,
  exist_data_id: '' as number | string,
});

// 多張銀行卡
interface CardRow {
  account_name: string;
  account_no: string;
  bank_id: '' | number | string;
  exist_data_id?: number | string;
}
const cards = ref<CardRow[]>([]);
const cardNameEditable = ref(true);

const showBsb = computed(() => Number(acc.enable_bsb_code) === 1);
// 遮罩時只列有資料的卡（#4486）：後端遮罩後 account_no 為空字串，改看 exist_data_id
const visibleCards = computed(() =>
  props.maskPrivate
    ? cards.value.filter(
        (c) =>
          c.exist_data_id !== undefined &&
          c.exist_data_id !== null &&
          c.exist_data_id !== '',
      )
    : cards.value,
);

function close() {
  emit('update:modelValue', false);
}

async function copy(text?: string) {
  if (!text) return;
  try {
    await navigator.clipboard.writeText(text);
    ElMessage.success(tl('cs.copied'));
  } catch {
    ElMessage.error(tl('cs.copy_failed'));
  }
}

async function load() {
  loading.value = true;
  try {
    if (banks.value.length === 0) {
      banks.value = await getCsBankOptionsApi().catch(() => []);
    }
    const setting = await getCsBankCardSettingApi(props.username).catch(
      () => ({}) as CsBankCardSetting,
    );
    if (Number(setting?.has_bank_card_setting) === 1) {
      mode.value = 'card';
      const data = await getCsBankCardApi(props.username).catch(
        () => ({}) as CsBankCardData,
      );
      const amount = Number(setting?.bank_card_amount ?? 0) || 1;
      const existing = data?.account ?? [];
      cardNameEditable.value =
        Number(data?.is_first) === 1 || Number(data?.account_name_modify) === 1;
      cards.value = Array.from({ length: amount }, (_, i) => {
        const c: CsBankCard = existing[i] ?? {};
        return {
          account_name: c.account_name ?? '',
          account_no: c.account_no ?? '',
          bank_id: c.bank_id ?? '',
          exist_data_id: c.id,
        };
      });
    } else {
      mode.value = 'account';
      const data = await getCsBankAccountApi(props.username).catch(
        () => ({}) as CsBankAccount,
      );
      acc.exist_data_id = data?.id ?? '';
      acc.bank_id = data?.bank_id ?? '';
      acc.account_name = data?.account_name ?? '';
      acc.account_no = data?.account_no ?? '';
      acc.enable_bsb_code = Number(data?.enable_bsb_code ?? 0);
      acc.bsb_code_alias = data?.bsb_code_alias || 'BSB';
      acc.bsb_code = '';
      // enable_bsb_code 時帳號以 bsb__account 形式儲存，拆開顯示
      if (acc.enable_bsb_code === 1 && acc.account_no.includes('__')) {
        const [bsb, no] = acc.account_no.split('__');
        acc.bsb_code = bsb ?? '';
        acc.account_no = no ?? '';
      }
    }
  } finally {
    loading.value = false;
  }
}

async function saveAccount() {
  if (saving.value) return;
  if (!acc.bank_id || !acc.account_name.trim() || !acc.account_no.trim()) {
    ElMessage.warning(tl('cs.enter_bank_fields'));
    return;
  }
  let accountNo = acc.account_no.trim();
  if (acc.enable_bsb_code === 1 && acc.bsb_code.trim()) {
    accountNo = `${acc.bsb_code.trim()}__${accountNo}`;
  }
  saving.value = true;
  try {
    await saveCsBankAccountApi({
      account_name: acc.account_name.trim(),
      account_no: accountNo,
      bank_id: acc.bank_id,
      exist_data_id: acc.exist_data_id,
      operator_cs: props.operatorId,
      username: props.username,
    });
    ElMessage.success(tl('cs.saved'));
    close();
  } finally {
    saving.value = false;
  }
}

async function saveCards() {
  if (saving.value) return;
  const filled = cards.value.filter(
    (c) => c.bank_id && c.account_name.trim() && c.account_no.trim(),
  );
  if (filled.length === 0) {
    ElMessage.warning(tl('cs.enter_one_complete_bank_card'));
    return;
  }
  saving.value = true;
  try {
    await saveCsBankCardApi({
      account: filled.map((c) => ({
        account_name: c.account_name.trim(),
        account_no: c.account_no.trim(),
        bank_id: c.bank_id,
        exist_data_id: c.exist_data_id,
      })),
      operator_cs: props.operatorId,
      username: props.username,
    });
    ElMessage.success(tl('cs.saved'));
    await load();
  } finally {
    saving.value = false;
  }
}

async function deleteCards() {
  if (saving.value) return;
  try {
    await ElMessageBox.confirm(
      tl('cs.confirm_delete_all_bank_cards'),
      tl('form.delete'),
      { type: 'warning' },
    );
  } catch {
    return;
  }
  saving.value = true;
  try {
    await deleteCsBankCardApi({
      operator_cs: props.operatorId,
      username: props.username,
    });
    ElMessage.success(tl('cs.deleted'));
    await load();
  } finally {
    saving.value = false;
  }
}

watch(
  () => props.modelValue,
  (open) => {
    if (open) load();
  },
);
</script>

<template>
  <ElDialog
    :model-value="modelValue"
    :title="tl('list.bank_account')"
    :width="isMobile ? '94%' : '560'"
    @update:model-value="(v: boolean) => emit('update:modelValue', v)"
  >
    <div v-loading="loading">
      <!-- 包在 ElForm 裡才吃得到表單的標籤樣式，否則與下方欄位的標籤對不齊 -->
      <ElForm label-width="80">
        <ElFormItem :label="tl('form.username')">
          <ElInput :model-value="username" disabled />
        </ElFormItem>
      </ElForm>

      <!-- 單筆銀行帳號 -->
      <ElForm v-if="mode === 'account'" label-width="80">
        <ElFormItem :label="tl('form.bank')" required>
          <ElSelect
            v-model="acc.bank_id"
            :disabled="!editable"
            filterable
            :placeholder="tl('cs.pick_bank')"
            style="width: 100%"
          >
            <ElOption
              v-for="b in banks"
              :key="b.id"
              :label="b.name"
              :value="b.id"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem
          v-if="!maskPrivate"
          :label="tl('form.account_name')"
          required
        >
          <ElInput
            v-model="acc.account_name"
            :disabled="!editable"
            maxlength="50"
          >
            <template #append>
              <ElButton @click="copy(acc.account_name)">
                {{ tl('copy') }}
              </ElButton>
            </template>
          </ElInput>
        </ElFormItem>
        <ElFormItem v-if="!maskPrivate" :label="tl('form.account_no')" required>
          <ElInput
            v-model="acc.account_no"
            :disabled="!editable"
            maxlength="50"
          >
            <template #append>
              <ElButton @click="copy(acc.account_no)">
                {{ tl('copy') }}
              </ElButton>
            </template>
          </ElInput>
        </ElFormItem>
        <ElFormItem v-if="showBsb && !maskPrivate" :label="acc.bsb_code_alias">
          <ElInput v-model="acc.bsb_code" :disabled="!editable" maxlength="50">
            <template #append>
              <ElButton @click="copy(acc.bsb_code)">{{ tl('copy') }}</ElButton>
            </template>
          </ElInput>
        </ElFormItem>
      </ElForm>

      <!-- 多張銀行卡 -->
      <div v-else>
        <div
          v-for="(card, i) in visibleCards"
          :key="i"
          class="mb-3 rounded border p-3"
        >
          <div class="mb-2 text-sm font-medium text-muted-foreground">
            {{ tl('cs.bank_card') }} {{ i + 1 }}
          </div>
          <ElForm label-width="80">
            <ElFormItem :label="tl('form.bank')">
              <ElSelect
                v-model="card.bank_id"
                :disabled="maskPrivate"
                filterable
                :placeholder="tl('cs.pick_bank')"
                style="width: 100%"
              >
                <ElOption
                  v-for="b in banks"
                  :key="b.id"
                  :label="b.name"
                  :value="b.id"
                />
              </ElSelect>
            </ElFormItem>
            <ElFormItem v-if="!maskPrivate" :label="tl('form.account_name')">
              <ElInput
                v-model="card.account_name"
                :disabled="!cardNameEditable"
                maxlength="50"
              >
                <template #append>
                  <ElButton @click="copy(card.account_name)">
                    {{ tl('copy') }}
                  </ElButton>
                </template>
              </ElInput>
            </ElFormItem>
            <ElFormItem v-if="!maskPrivate" :label="tl('form.account_no')">
              <ElInput v-model="card.account_no" maxlength="50">
                <template #append>
                  <ElButton @click="copy(card.account_no)">
                    {{ tl('copy') }}
                  </ElButton>
                </template>
              </ElInput>
            </ElFormItem>
          </ElForm>
        </div>
        <ElEmpty
          v-if="visibleCards.length === 0"
          :description="tl('cs.no_bank_card')"
        />
      </div>
    </div>

    <template #footer>
      <ElButton @click="close">{{ tl('form.cancel') }}</ElButton>
      <!-- 營運商唯讀（maskPrivate）不渲染任何寫入按鈕（#4486） -->
      <template v-if="mode === 'card' && !maskPrivate">
        <ElButton type="danger" :loading="saving" @click="deleteCards">
          {{ tl('form.delete') }}
        </ElButton>
        <ElButton type="primary" :loading="saving" @click="saveCards">
          {{ tl('form.save') }}
        </ElButton>
      </template>
      <ElButton
        v-if="mode === 'account' && !maskPrivate"
        type="primary"
        :loading="saving"
        :disabled="!editable"
        @click="saveAccount"
      >
        {{ tl('form.save') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
