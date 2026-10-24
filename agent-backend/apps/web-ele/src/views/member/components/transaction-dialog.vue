<script lang="ts" setup>
import type { BankAccountRow, MemberRow } from '#/api';

import { computed, reactive, ref, watch } from 'vue';

import { useAccess } from '@vben/access';
import { useUserStore } from '@vben/stores';

import dayjs from 'dayjs';
import {
  ElButton,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElInputNumber,
  ElMessage,
  ElMessageBox,
  ElOption,
  ElSelect,
  ElSkeleton,
} from 'element-plus';

import {
  deductMemberScoreApi,
  getBankAccountListApi,
  getMemberViewApi,
  grantMemberScoreApi,
  memberTransactionApi,
  verifyAgentPaymentPasswordApi,
} from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'MemberTransactionDialog' });

const props = defineProps<{
  /** 代理自訂設定（6601 流水倍數 / 6602 流水可改 / 5304 支付密碼） */
  settings: Map<number, any>;
}>();

const emit = defineEmits<{ success: [] }>();

const { hasAccessByCodes } = useAccess();
const userStore = useUserStore();

const visible = ref(false);
const loading = ref(false);
const submitting = ref(false);

const member = ref<MemberRow | null>(null);
const depositBanks = ref<BankAccountRow[]>([]);
const withdrawalBanks = ref<BankAccountRow[]>([]);

const form = reactive({
  account_deposit: 0,
  account_withdrawal: 0,
  audit_amount: 0,
  memo: '',
  point: 0,
  /** 積分有效期（小時），null＝無限期；僅 type 8 使用（工單4233） */
  score_hours: null as null | number,
  type: 0,
});

/** 操作型別 */
const typeOptions = computed(() => {
  const opts: Array<{ label: string; value: number }> = [];
  if (hasAccessByCodes(['agent_backend_deposit']))
    opts.push({ label: tl('deposit'), value: 1 });
  if (hasAccessByCodes(['agent_backend_withdrawal']))
    opts.push({ label: tl('withdrawal'), value: 2 });
  // 工單4308/4334：補點開關已由 agent_personal_settings(95104) 遷移為 agent_menus 權限節點 member_make_up_point
  if (hasAccessByCodes(['member_make_up_point']))
    opts.push({ label: tl('compensate'), value: 4 });
  if (hasAccessByCodes(['agent_backend_send_rolling_chip']))
    opts.push({ label: tl('send_rolling_chip'), value: 5 });
  if (hasAccessByCodes(['member_deduct_point']))
    opts.push({ label: tl('reduce_event_v2_balance'), value: 6 });
  if (hasAccessByCodes(['agent_backend_send_rolling_chip']))
    opts.push({ label: tl('deduct_rolling_chip'), value: 7 });
  // 工單4233：積分發放走 apis 新接口（非 memberTransaction），權限節點 member_add_score；本期只做增加
  if (hasAccessByCodes(['member_add_score']))
    opts.push({ label: tl('grant_score'), value: 8 });
  // 積分扣除：填總量，後端依到期日由近到遠自動拆批（PM 2026-09-16 定案）
  if (hasAccessByCodes(['member_deduct_score']))
    opts.push({ label: tl('deduct_score'), value: 9 });
  return opts;
});

const flowMultiple = computed(() => Number(props.settings.get(6601) ?? 1));
const flowEditable = computed(
  () => Number(props.settings.get(6602) ?? 0) === 1,
);

/** 是否需要代理支付密碼（5304 開啟且角色不在 1/11/12） */
const needPaymentPassword = [REDACTED] => {
  const enabled = Number(props.settings.get(5304) ?? 0) === 1;
  const roleId = Number((userStore.userInfo as any)?.role_id ?? 0);
  return enabled && ![1, 11, 12].includes(roleId);
});

/** 顯示存款帳戶選擇（type 1/4）；取款帳戶（type 2）；稽核流水（type 1） */
const showDepositSelect = computed(() => [1, 4].includes(form.type));
const showWithdrawalSelect = computed(() => form.type === 2);
const showAudit = computed(() => form.type === 1);
/** 積分發放（type 8）：多一個有效期欄位、備註必填、送 apis */
const isScoreGrant = computed(() => form.type === 8);
/** 積分扣除（type 9）：備註必填、送 apis；不選批次，後端 FIFO */
const isScoreDeduct = computed(() => form.type === 9);
const isScoreOp = computed(() => isScoreGrant.value || isScoreDeduct.value);

/**
 * 有效期換算的到期時間（後台時區 +8）；空＝無限期。
 * 與 apis MemberScoreService::ceilToTenMinuteGrid() 同一條規則：進位到下一個 10 分鐘刻度
 * （14:15→14:20、14:55→15:00，剛好在刻度上不動），對齊每 10 分鐘的到期排程，畫面與 DB 一致。
 */
const scoreExpiryHint = computed(() => {
  if (!isScoreGrant.value) return '';
  const h = Number(form.score_hours);
  if (!(h > 0)) return tl('unlimited');
  const raw = dayjs().add(h, 'hour').utcOffset(8);
  const remainder = raw.minute() % 10;
  const hasSubMinute = raw.second() > 0 || raw.millisecond() > 0;
  const bump = remainder !== 0 || hasSubMinute ? 10 - remainder : 0;
  const expiry = raw.startOf('minute').add(bump, 'minute');
  return `${expiry.format('YYYY/MM/DD HH:mm')} (UTC+8) ${tl('expired')}`;
});

// 點數變動 → 稽核流水 = 點數 * 倍數
watch(
  () => form.point,
  (v) => {
    form.audit_amount = (Number(v) || 0) * flowMultiple.value;
  },
);

async function open(row: MemberRow) {
  visible.value = true;
  loading.value = true;
  member.value = null;
  Object.assign(form, {
    account_deposit: 0,
    account_withdrawal: 0,
    audit_amount: 0,
    memo: '',
    point: 0,
    score_hours: null,
    type: 0,
  });
  try {
    const [view, banks] = await Promise.all([
      getMemberViewApi(row.id),
      getBankAccountListApi(),
    ]);
    member.value = view;
    depositBanks.value = (banks ?? []).filter((b) => b.is_deposit === 1);
    withdrawalBanks.value = (banks ?? []).filter((b) => b.is_withdrawal === 1);
    form.type = typeOptions.value[0]?.value ?? 0;
  } finally {
    loading.value = false;
  }
}

/** 確認摘要 → （支付密碼）→ 送出 */
async function submit() {
  if (!(Number(form.point) > 0)) {
    ElMessage.warning('Please enter a number greater than 0');
    return;
  }
  // 積分發放／扣除的備註會寫進積分異動紀錄，工單要求必填
  if (isScoreOp.value && form.memo.trim() === '') {
    ElMessage.warning(tl('enter_memo'));
    return;
  }
  const typeLabel =
    typeOptions.value.find((o) => o.value === form.type)?.label ?? form.type;
  const accountId =
    form.type === 1 ? form.account_deposit : form.account_withdrawal;
  const accountName =
    form.type === 1
      ? depositBanks.value.find((b) => b.account_id === form.account_deposit)
          ?.account_nickname
      : withdrawalBanks.value.find(
          (b) => b.account_id === form.account_withdrawal,
        )?.account_nickname;

  // 確認摘要
  const lines = [
    `${tl('member_account')}：${member.value?.username ?? ''}`,
    [1, 2].includes(form.type)
      ? `${tl('account_no')}：${accountName ?? tl('choose_bank_account')}`
      : '',
    `${tl('operate')}：${typeLabel}`,
    `${isScoreOp.value ? tl('member_score') : tl('point')}：${form.point}`,
    isScoreGrant.value
      ? `${tl('score_valid_hours')}：${form.score_hours ?? tl('unlimited')}（${scoreExpiryHint.value}）`
      : '',
    showAudit.value ? `${tl('audit_amount')}：${form.audit_amount}` : '',
    `${tl('memo')}：${form.memo}`,
  ].filter(Boolean);

  try {
    await ElMessageBox.confirm(lines.join('\n'), tl('confirm'), {
      cancelButtonText: tl('cancel'),
      confirmButtonText: tl('confirm'),
      customStyle: { whiteSpace: 'pre-line' } as any,
    });
  } catch {
    return;
  }

  // 代理支付密碼驗證（5304）
  if (needPaymentPassword.value) {
    try {
      const { value: pwd } = await ElMessageBox.prompt(
        tl('agent_payment_password'),
        tl('auth'),
        {
          cancelButtonText: tl('cancel'),
          confirmButtonText: tl('auth'),
          inputType: 'password',
        },
      );
      const vr = await verifyAgentPaymentPasswordApi(pwd ?? '');
      if (Number(vr.code) !== 200) {
        ElMessage.error(vr.message || tl('failure'));
        return;
      }
    } catch {
      return;
    }
  }

  const m = member.value;
  if (!m) return;
  submitting.value = true;
  try {
    // 積分走 apis（成功碼 200）；其餘型別走 API_Agent memberTransaction（成功碼 1000）
    let res: any;
    if (isScoreGrant.value) {
      res = await grantMemberScoreApi({
        hours: form.score_hours ?? null,
        member_id: m.id,
        remark: form.memo,
        score: form.point,
      });
    } else if (isScoreDeduct.value) {
      res = await deductMemberScoreApi({
        member_id: m.id,
        remark: form.memo,
        score: form.point,
      });
    } else {
      res = await memberTransactionApi({
        account_id: accountId,
        audit_amount: showAudit.value ? form.audit_amount : 0,
        member_id: m.id,
        memo: form.memo,
        point: form.point,
        type: form.type,
      });
    }
    const succeeded = isScoreOp.value ? res.code === 200 : res.code === 1000;
    if (succeeded) {
      ElMessage.success(res.message || tl('success'));
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
  <ElDialog v-model="visible" :title="tl('transfer')" width="560" align-center>
    <ElSkeleton v-if="loading" :rows="5" animated />
    <template v-else-if="member">
      <div class="mb-4 space-y-1 text-sm">
        <div>{{ tl('member_account') }}：{{ member.username }}</div>
        <div>{{ tl('member_balance') }}：{{ member.balance }}</div>
        <div>{{ tl('rolling_chip') }}：{{ member.rolling_chip }}</div>
        <div>{{ tl('member_score') }}：{{ member.score ?? 0 }}</div>
      </div>
      <ElForm label-position="top">
        <div class="grid grid-cols-2 gap-x-4">
          <ElFormItem :label="tl('operate')">
            <ElSelect v-model="form.type" class="w-full">
              <ElOption
                v-for="o in typeOptions"
                :key="o.value"
                :label="o.label"
                :value="o.value"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem v-if="showDepositSelect" :label="tl('account_code')">
            <ElSelect v-model="form.account_deposit" class="w-full">
              <ElOption :label="tl('choose_bank_account')" :value="0" />
              <ElOption
                v-for="b in depositBanks"
                :key="b.account_id"
                :label="b.account_nickname"
                :value="b.account_id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem v-if="showWithdrawalSelect" :label="tl('account_no')">
            <ElSelect v-model="form.account_withdrawal" class="w-full">
              <ElOption :label="tl('choose_bank_account')" :value="0" />
              <ElOption
                v-for="b in withdrawalBanks"
                :key="b.account_id"
                :label="b.account_nickname"
                :value="b.account_id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="isScoreOp ? tl('member_score') : tl('point')">
            <ElInputNumber
              v-model="form.point"
              :min="0"
              :precision="2"
              class="!w-full"
              controls-position="right"
            />
          </ElFormItem>
          <ElFormItem v-if="isScoreGrant" :label="tl('score_valid_hours')">
            <div class="w-full">
              <ElInputNumber
                v-model="form.score_hours"
                :min="1"
                :placeholder="tl('unlimited')"
                :precision="0"
                class="!w-full"
                controls-position="right"
              />
              <!-- 換算後的到期時間（+8），例：2026/09/18 12:00 過期；留空顯示無限制 -->
              <div class="text-muted-foreground mt-1 text-xs">
                {{ scoreExpiryHint }}
              </div>
            </div>
          </ElFormItem>
          <ElFormItem v-if="showAudit" :label="tl('audit_amount')">
            <ElInputNumber
              v-model="form.audit_amount"
              :disabled="!flowEditable"
              :min="0"
              class="!w-full"
              controls-position="right"
            />
          </ElFormItem>
        </div>
        <ElFormItem :label="tl('memo')">
          <ElInput v-model="form.memo" :rows="3" type="textarea" />
        </ElFormItem>
      </ElForm>
    </template>
    <template #footer>
      <ElButton @click="visible = false">{{ tl('cancel') }}</ElButton>
      <ElButton
        :disabled="loading || typeOptions.length === 0"
        :loading="submitting"
        type="primary"
        @click="submit"
      >
        {{ tl('confirm') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
