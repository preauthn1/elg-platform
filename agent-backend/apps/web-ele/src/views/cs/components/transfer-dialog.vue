<script lang="ts" setup>
import type {
  CsMemberView,
  CsTransactionAddPayload,
  CsUserView,
} from '#/api/cs';

import { computed, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';

import { usePreferences } from '@vben/preferences';

import {
  ElButton,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElInputNumber,
  ElMessage,
  ElOption,
  ElSelect,
} from 'element-plus';

import { addCsTransactionApi, getCsMemberViewApi } from '#/api/cs';
import { tl } from '#/locales';

import {
  calcAuditAmount,
  parseFlowLimitMultiple,
  resolveSubmitAuditAmount,
  TRANSFER_TYPE_RECEIVE_RED_ENVELOPE,
  TRANSFER_TYPE_SEND_RED_ENVELOPE,
} from './transfer-audit';

defineOptions({ name: 'CsTransferDialog' });

const props = defineProps<{
  agent: CsUserView;
  memberId: number | string;
  memberName?: string;
  modelValue: boolean;
}>();
const emit = defineEmits<{
  success: [];
  'update:modelValue': [boolean];
}>();

// 轉帳種類（依操作者旗標決定可用項目）；label 在模板用 tl(key) 渲染時解析。
const TYPE_DEFS: { flag: keyof CsUserView; key: string; value: number }[] = [
  {
    flag: 'send_red_envelope_button_visible',
    key: 'list.send_redEnvelope',
    value: TRANSFER_TYPE_SEND_RED_ENVELOPE,
  },
  {
    flag: 'receive_red_envelope_button_visible',
    key: 'list.receive_redEnvelope',
    value: TRANSFER_TYPE_RECEIVE_RED_ENVELOPE,
  },
  { flag: 'point_button_visible', key: 'list.transfer_makeUp', value: 4 },
  { flag: 'is_multiple_wallet', key: 'cs.send_game_wallet', value: 5 },
  {
    flag: 'deduct_point_button_visible',
    key: 'cs.deduct_game_wallet',
    value: 6,
  },
];

const { isMobile } = usePreferences();
const router = useRouter();

const member = ref<CsMemberView>({});
const submitting = ref(false);

const form = reactive({
  amount: undefined as number | undefined,
  audit_amount: undefined as number | undefined,
  remark: '',
  type: 1,
  wallet: 1, // Points.Wallet：目前僅「平台」一種(val 1)
});

const pwdDlg = reactive({ value: '', visible: false });

const typeOptions = computed(() =>
  TYPE_DEFS.filter((d) => Number(props.agent?.[d.flag]) === 1),
);
// #4486 營運商（CS role 2）
const isOperator = computed(() => Number(props.agent?.role_id) === 2);
// 顯示稽核量：非「收紅包(2)」且非多錢包；營運商不設稽核流水（#4486）
const showAudit = computed(
  () =>
    form.type !== TRANSFER_TYPE_RECEIVE_RED_ENVELOPE &&
    Number(props.agent?.is_multiple_wallet) !== 1 &&
    !isOperator.value,
);
// type 1 且無流量修改權限 → 稽核量唯讀
const auditDisabled = computed(
  () =>
    form.type === TRANSFER_TYPE_SEND_RED_ENVELOPE &&
    Number(props.agent?.flow_limit_modify_permission) === 0,
);
const showWallet = computed(
  () => form.type === TRANSFER_TYPE_SEND_RED_ENVELOPE || form.type === 3,
);
const isMultiWallet = computed(
  () => Number(props.agent?.is_multiple_wallet) === 1,
);
// 稽核流水默認倍數：非有限數字當 0 倍，設 0 就是 0（target/tickets#4494）
const flowLimitMultiple = computed(() =>
  parseFlowLimitMultiple(props.agent?.transfer_member_flow_limit_multiple),
);
// 會員餘額：由代理後台權限節點 cs_downline_balance_visible（agent_menus
// 「客服後台是否顯示下線餘額」）控管，沒勾到就不顯示。旗標缺漏時一律視為未授權。
const showMemberBalance = computed(
  () => Number(props.agent?.cs_downline_balance_visible) === 1,
);

function onAmountChange(v: null | number | undefined) {
  // 只有發紅包自動算（稽核量 = 金額 × 流量倍率），其他類型保留客服手填值（target/tickets#4494）
  if (form.type !== TRANSFER_TYPE_SEND_RED_ENVELOPE) return;
  form.audit_amount = calcAuditAmount(form.type, v, flowLimitMultiple.value);
}

function close() {
  emit('update:modelValue', false);
}

async function loadMember() {
  member.value = await getCsMemberViewApi(props.memberId).catch(() => ({}));
}

/** 跳去該會員近 24 小時的開洗分/紅包紀錄（比照原本客服的連結，開新分頁）。 */
function goToRecords() {
  const username = member.value.username || props.memberName || '';
  const route = router.resolve({
    path: '/cs/transaction/list',
    query: username ? { username } : {},
  });
  window.open(route.href, '_blank');
}

async function submit(pwd?: string) {
  if (submitting.value) return;
  if (
    form.amount === null ||
    form.amount === undefined ||
    Number(form.amount) <= 0
  ) {
    ElMessage.warning(tl('enter_amount'));
    return;
  }
  if (typeOptions.value.length === 0) {
    ElMessage.warning(tl('cs.no_transfer_permission'));
    return;
  }
  submitting.value = true;
  const payload: CsTransactionAddPayload = {
    amount: form.amount,
    // 營運商不設稽核流水（#4486）；收紅包或多錢包一律 0（target/tickets#4494）
    audit_amount: isOperator.value
      ? 0
      : resolveSubmitAuditAmount(
          form.type,
          isMultiWallet.value,
          form.audit_amount,
        ),
    member_id: props.memberId,
    platform: showWallet.value ? form.wallet : '',
    remark: form.remark,
    type: form.type,
  };
  if (pwd) payload.payment_password = [REDACTED];
  try {
    const res = await addCsTransactionApi(payload);
    const code = Number(res?.code);
    switch (code) {
      case 1000: {
        ElMessage.success(tl('redEnvelope.transfer_success'));
        pwdDlg.visible = false;
        close();
        emit('success');

        break;
      }
      case 1414: {
        ElMessage.error(res?.message || tl('cs.flow_limit_reached'));
        close();

        break;
      }
      case 7001: {
        // 需付款密碼
        pwdDlg.visible = true;

        break;
      }
      default: {
        ElMessage.error(res?.message || tl('cs.transfer_failed'));
      }
    }
  } finally {
    submitting.value = false;
  }
}

function confirmPwd() {
  if (!pwdDlg.value.trim()) {
    ElMessage.warning(tl('cs.enter_payment_password'));
    return;
  }
  submit(pwdDlg.value.trim());
}

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return;
    form.type = typeOptions.value[0]?.value ?? 1;
    form.amount = undefined;
    form.audit_amount = undefined;
    form.remark = '';
    form.wallet = 1;
    pwdDlg.value = '';
    pwdDlg.visible = false;
    loadMember();
  },
);

// 切換類型：發紅包依金額×倍率重算，其他類型清空讓客服手填（target/tickets#4494）
watch(
  () => form.type,
  (type) => {
    form.audit_amount = calcAuditAmount(
      type,
      form.amount,
      flowLimitMultiple.value,
    );
  },
);
</script>

<template>
  <ElDialog
    :model-value="modelValue"
    :title="tl('cs.redenvelope_or_wash')"
    :width="isMobile ? '94%' : '460'"
    @update:model-value="(v: boolean) => emit('update:modelValue', v)"
  >
    <ElForm label-width="92">
      <ElFormItem :label="tl('member_account')">
        <ElInput :model-value="member.username || memberName || ''" disabled />
      </ElFormItem>
      <ElFormItem :label="tl('member_nickname')">
        <ElInput :model-value="member.nickname || ''" disabled />
      </ElFormItem>
      <ElFormItem v-if="showMemberBalance" :label="tl('member_balance')">
        <ElInput :model-value="String(member.balance ?? '')" disabled />
      </ElFormItem>
      <ElFormItem v-if="member.game_wallet != null" :label="tl('gameWallet')">
        <ElInput :model-value="String(member.game_wallet ?? '')" disabled />
      </ElFormItem>

      <ElFormItem :label="tl('type')">
        <ElSelect v-model="form.type" style="width: 100%">
          <ElOption
            v-for="o in typeOptions"
            :key="o.value"
            :label="tl(o.key)"
            :value="o.value"
          />
        </ElSelect>
      </ElFormItem>
      <ElFormItem v-if="showWallet" :label="tl('redEnvelope.transfer_inTo')">
        <ElSelect v-model="form.wallet" style="width: 100%">
          <ElOption :value="1" :label="tl('redEnvelope.platform')" />
        </ElSelect>
      </ElFormItem>

      <ElFormItem :label="tl('operate_agent_username')">
        <ElInput :model-value="agent.username || ''" disabled />
      </ElFormItem>
      <ElFormItem :label="tl('agent_balance')">
        <ElInput :model-value="String(agent.balance ?? '')" disabled />
      </ElFormItem>
      <ElFormItem v-if="isMultiWallet" :label="tl('cs.game_wallet_balance')">
        <ElInput :model-value="String(agent.game_wallet ?? '')" disabled />
      </ElFormItem>

      <ElFormItem :label="tl('form.amount')" required>
        <ElInputNumber
          v-model="form.amount"
          :controls="false"
          :min="0"
          :precision="4"
          class="!w-full"
          @change="onAmountChange"
        />
      </ElFormItem>
      <ElFormItem v-if="showAudit" :label="tl('form.audit_amount')">
        <ElInputNumber
          v-model="form.audit_amount"
          :controls="false"
          :disabled="auditDisabled"
          :min="0"
          :precision="4"
          class="!w-full"
        />
      </ElFormItem>
      <ElFormItem :label="tl('form.description')">
        <ElInput v-model="form.remark" maxlength="50" />
      </ElFormItem>
    </ElForm>

    <template #footer>
      <div class="flex items-center">
        <ElButton link type="primary" @click="goToRecords">
          {{ tl('redEnvelope.24hr_transfer_record') }}
        </ElButton>
        <div class="flex-1"></div>
        <ElButton @click="close">{{ tl('form.cancel') }}</ElButton>
        <ElButton
          type="primary"
          :loading="submitting"
          :disabled="typeOptions.length === 0"
          @click="submit()"
        >
          {{ tl('form.send') }}
        </ElButton>
      </div>
    </template>
  </ElDialog>

  <!-- 付款密碼（後端回 7001 時）-->
  <ElDialog
    v-model="pwdDlg.visible"
    append-to-body
    :title="tl('cs.payment_password')"
    :width="isMobile ? '90%' : '360'"
  >
    <ElInput
      v-model="pwdDlg.value"
      type="password"
      :placeholder="tl('cs.enter_payment_password')"
      show-password
      @keyup.enter="confirmPwd"
    />
    <template #footer>
      <ElButton @click="pwdDlg.visible = false">
        {{ tl('form.cancel') }}
      </ElButton>
      <ElButton type="primary" :loading="submitting" @click="confirmPwd">
        {{ tl('confirm') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
