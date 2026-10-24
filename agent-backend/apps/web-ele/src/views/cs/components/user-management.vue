<script lang="ts" setup>
import type { CsUserRow, CsUserView } from '#/api/cs';

import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';

import { Page } from '@vben/common-ui';
import { DollarSign, Pencil, Trash2 } from '@vben/icons';
import { usePreferences } from '@vben/preferences';

import {
  ElButton,
  ElCard,
  ElDialog,
  ElEmpty,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElMessageBox,
  ElOption,
  ElRadio,
  ElRadioGroup,
  ElSelect,
  ElTable,
  ElTableColumn,
  ElTag,
} from 'element-plus';

import {
  csMenuActions,
  csUserTransactionApi,
  deleteCsUserApi,
  editCsUserApi,
  getCsMeApi,
  getCsUserListApi,
  getCsUserViewApi,
  runCsBootstrap,
} from '#/api/cs';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { useAuthStore } from '#/store';
import { diffAmounts, floorAmount } from '#/utils/format';

defineOptions({ name: 'CsUserManagement' });

const { isMobile } = usePreferences();
const route = useRoute();
const authStore = useAuthStore();

/** 三頁共用：路由決定種類與 role_id（營運商/用戶=2，客服人員=3） */
const kind = computed<'agent' | 'customer_service' | 'user'>(() => {
  if (route.path.endsWith('/customer_service')) return 'customer_service';
  if (route.path.endsWith('/user')) return 'user';
  return 'agent';
});
const roleId = computed(() => (kind.value === 'customer_service' ? 3 : 2));
const pageTitle = computed(() => {
  if (kind.value === 'customer_service') return tl('cs.cs_staff_mgmt');
  return kind.value === 'user' ? tl('cs.user_list') : tl('cs.vendor_mgmt');
});

const bootstrapError = ref(false);
/**
 * bootstrap（登入＋首批資料）進行中。不能借用 useListQuery 的 loading——
 * 那是 loadData 自己的旗標，每次重試的 finally 都會關掉，轉圈會一閃一閃。
 */
const bootstrapping = ref(false);
// 送出中旗標：防止連點重複送出（彈窗互斥，共用一個即可）
const submitting = ref(false);
const view = ref<CsUserView>({});
const me = ref<{ id?: number | string; role_id?: number }>({});

const PER_PAGE = 25;
const filters = reactive({ keyword: '' });
/**
 * 目前列表實際套用的關鍵字（已送出的那個）。解遮判斷只能認它——
 * 認 filters.keyword 的話，搜尋框才敲第一個字就把整頁加密帳號解遮了。
 */
const appliedKeyword = ref('');
/** 本次 loadData 是否載入成功。useListQuery 內部吞錯，靠 onLoaded 回報給 bootstrap */
const listLoaded = ref(false);

// 動作權限（來自選單 action 欄）
const acts = computed(() => csMenuActions(kind.value));
const canEdit = computed(() => acts.value.includes('edit'));
const canDelete = computed(() => acts.value.includes('delete'));
const canTransaction = computed(() => acts.value.includes('transaction'));
const canDeposit = computed(() => acts.value.includes('deposit'));
const encMask = computed(() => acts.value.includes('encryption_username'));

// 操作者 role_id==2 且在 /user 時精簡欄位
const lite = computed(() => kind.value === 'user' && me.value.role_id === 2);
const showWithdraw = computed(() => !lite.value);
const showBalance = computed(() => !lite.value);
const multiWallet = computed(() => view.value.is_multiple_wallet === 1);

function maskName(row: CsUserRow): string {
  if (appliedKeyword.value) return row.username;
  if (encMask.value && row.is_sub === 0 && row.username.length > 5) {
    return `${row.username.slice(0, 2)}***${row.username.slice(-3)}`;
  }
  return row.username;
}
function balanceNum(row: CsUserRow): number | string {
  if (row.balance !== null && row.balance !== undefined)
    return row.balance === -1 ? tl('cs.no_limit') : row.balance;
  return Number(diffAmounts(row.limit_of_credit, row.used_credit));
}
function balanceText(row: CsUserRow): string {
  const b = balanceNum(row);
  return typeof b === 'number' ? floorAmount(b, 2).toFixed(2) : b;
}

/**
 * 伺服器端分頁查詢。encMask（帳號需遮蔽）時只允許精確比對帳號，
 * 語意同原本前端過濾的「完整帳號才顯示」；其餘走 keyword 模糊比對帳號/暱稱。
 */
const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<CsUserRow>(
    ({ page, per_page }) => {
      const kw = filters.keyword.trim();
      // 放這裡而不是 submit handler：編輯/凍結/交易後的 loadData() 也會經過
      appliedKeyword.value = kw;
      return getCsUserListApi({
        page,
        per_page,
        roleId: roleId.value,
        ...(encMask.value ? { username_exact: kw } : { keyword: kw }),
      });
    },
    {
      immediate: false,
      onLoaded: (res) => {
        listLoaded.value =
          Number(res.code) === 1000 || Number(res.code) === 200;
      },
      perPage: PER_PAGE,
    },
  );
async function fetchView() {
  if (me.value.id !== null && me.value.id !== undefined) {
    view.value = await getCsUserViewApi(me.value.id);
  }
}
async function reloadView() {
  await fetchView().catch(() => {});
}
async function bootstrap() {
  bootstrapping.value = true;
  bootstrapError.value = false;
  try {
    await runCsBootstrap(async () => {
      me.value = await getCsMeApi();
      await fetchView();
      // loadData 內部吞錯永遠 resolve，不轉成 throw 的話 runCsBootstrap 不會重試
      listLoaded.value = false;
      await loadData();
      if (!listLoaded.value) throw new Error('cs user list failed');
    });
  } catch {
    bootstrapError.value = true;
  } finally {
    bootstrapping.value = false;
  }
}
onMounted(bootstrap);
watch(kind, () => {
  filters.keyword = '';
  search();
});

// ===== 編輯 =====
const editVisible = ref(false);
const editForm = reactive({
  id: '' as number | string,
  nickname: '',
  origThumb: '',
  status: 1 as number,
  thumbnail: '',
  username: '',
  withdraw_confirm: '1' as string,
});
function openEdit(row: CsUserRow) {
  Object.assign(editForm, {
    id: row.id,
    nickname: row.nickname ?? '',
    origThumb: row.thumbnail ?? '',
    status: row.status ?? 1,
    thumbnail: '',
    username: row.username,
    withdraw_confirm: String(row.withdraw_confirm ?? '1'),
  });
  editVisible.value = true;
}
async function submitEdit() {
  if (submitting.value) return;
  const payload: Record<string, any> = {
    id: editForm.id,
    nickname: editForm.nickname,
    status: editForm.status,
    withdraw_confirm: editForm.withdraw_confirm,
  };
  if (editForm.thumbnail.startsWith('data:'))
    payload.thumbnail = editForm.thumbnail;
  submitting.value = true;
  try {
    await editCsUserApi(payload as any);
    ElMessage.success(tl('cs.edit_success'));
    editVisible.value = false;
    await loadData();
  } catch (error: any) {
    ElMessage.error(error?.message || tl('cs.edit_failed'));
  } finally {
    submitting.value = false;
  }
}

// ===== 凍結 =====
async function confirmFreeze(row: CsUserRow) {
  if (submitting.value) return;
  try {
    await ElMessageBox.confirm(
      `${tl('cs.confirm_freeze')} ${row.username}？`,
      tl('cs.freeze'),
      { type: 'warning' },
    );
  } catch {
    return;
  }
  submitting.value = true;
  try {
    await deleteCsUserApi(row.id);
    ElMessage.success(tl('cs.frozen'));
    await loadData();
  } catch (error: any) {
    ElMessage.error(error?.message || tl('list.failed'));
  } finally {
    submitting.value = false;
  }
}

// ===== 開洗分 / 開分 =====
const txVisible = ref(false);
const txMode = ref<'deposit' | 'limit'>('limit');
const txForm = reactive({
  amount: '',
  balance: '' as number | string,
  gameWallet: null as null | number,
  id: '' as number | string,
  remark: '',
  username: '',
  walletType: 1,
});
const pwVisible = ref(false);
const paymentPassword = [REDACTED]'');

function openTx(row: CsUserRow, mode: 'deposit' | 'limit') {
  txMode.value = mode;
  Object.assign(txForm, {
    amount: '',
    balance: balanceNum(row),
    gameWallet: row.game_wallet ?? null,
    id: row.id,
    remark: '',
    username: row.username,
    walletType: 1,
  });
  txVisible.value = true;
}
async function submitTx(withPw = false) {
  if (submitting.value) return;
  if (!txForm.remark) {
    ElMessage.warning(tl('cs.enter_remarks'));
    return;
  }
  const data: Record<string, any> = {
    amount: txForm.amount,
    id: txForm.id,
    remark: txForm.remark,
  };
  if (withPw) data.payment_password = [REDACTED];
  if (txMode.value === 'limit' && multiWallet.value)
    data.type = txForm.walletType;
  submitting.value = true;
  try {
    const res = await csUserTransactionApi(data as any);
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('cs.transaction_success'));
      txVisible.value = false;
      pwVisible.value = false;
      paymentPassword.value = '';
      await loadData();
      await reloadView();
      // 分是從操作者自己的餘額轉出入，右上角餘額要跟著更新
      await authStore.refreshBalance();
    } else if (Number(res.code) === 7001) {
      txVisible.value = false;
      pwVisible.value = true; // 需付款密碼
    } else {
      ElMessage.error(res.message || tl('cs.transaction_failed'));
    }
  } catch (error: any) {
    ElMessage.error(error?.message || tl('cs.transaction_failed'));
  } finally {
    submitting.value = false;
  }
}

// 頭像上傳 → base64
function pickAvatar() {
  const input = document.createElement('input');
  input.type = 'file';
  input.accept = 'image/*';
  input.addEventListener('change', () => {
    const file = input.files?.[0];
    if (!file) return;
    const reader = new FileReader();
    reader.addEventListener('load', () => {
      const v = String(reader.result);
      editForm.thumbnail = v;
    });
    reader.readAsDataURL(file);
  });
  input.click();
}
</script>

<template>
  <Page :title="pageTitle">
    <ElCard shadow="never">
      <div class="mb-3 flex items-center gap-2">
        <ElForm class="flex items-center gap-2" @submit.prevent="search">
          <ElInput
            v-model="filters.keyword"
            :placeholder="tl('cs.search_account_nickname')"
            clearable
            style="max-width: 280px"
            @clear="search"
          />
          <ElButton native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </ElForm>
      </div>

      <div v-if="bootstrapError" class="flex flex-col items-center gap-3 py-16">
        <span class="text-muted-foreground">{{ tl('cs.load_failed') }}</span>
        <ElButton type="primary" @click="bootstrap">
          {{ tl('retry') }}
        </ElButton>
      </div>
      <ElTable
        v-else
        v-loading="loading || bootstrapping"
        :data="list"
        border
        size="small"
      >
        <ElTableColumn :label="tl('form.username')">
          <template #default="{ row }">
            {{ maskName(row as CsUserRow) }}
          </template>
        </ElTableColumn>
        <ElTableColumn prop="nickname" :label="tl('form.nickname')" />
        <ElTableColumn :label="tl('list.status')" width="90">
          <template #default="{ row }">
            <ElTag :type="row.status ? 'success' : 'info'" size="small">
              {{ row.status ? tl('form.status_1') : tl('form.status_0') }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn
          v-if="showWithdraw"
          :label="tl('cs.member_agreed')"
          width="160"
        >
          <template #default="{ row }">
            {{
              Number(row.withdraw_confirm) === 1
                ? tl('cs.agree')
                : tl('cs.disagree')
            }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          v-if="showBalance"
          :label="tl('form.limit_of_credit')"
          width="120"
        >
          <template #default="{ row }">
            {{ balanceText(row as CsUserRow) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('action')" width="160" fixed="right">
          <template #default="{ row }">
            <ElButton
              v-if="canTransaction"
              circle
              :title="tl('list.transaction')"
              type="warning"
              @click="openTx(row as CsUserRow, 'limit')"
            >
              <DollarSign class="size-4" />
            </ElButton>
            <ElButton
              v-if="canDeposit"
              circle
              :title="tl('list.transfer_in')"
              type="warning"
              @click="openTx(row as CsUserRow, 'deposit')"
            >
              <DollarSign class="size-4" />
            </ElButton>
            <ElButton
              v-if="canEdit"
              circle
              :title="tl('edit')"
              type="primary"
              @click="openEdit(row as CsUserRow)"
            >
              <Pencil class="size-4" />
            </ElButton>
            <ElButton
              v-if="canDelete"
              circle
              :title="tl('cs.freeze')"
              type="danger"
              @click="confirmFreeze(row as CsUserRow)"
            >
              <Trash2 class="size-4" />
            </ElButton>
          </template>
        </ElTableColumn>
        <template #empty>
          <ElEmpty :description="tl('cs.no_data_yet')" />
        </template>
      </ElTable>

      <TablePagination
        v-if="!bootstrapError"
        :current-page="page"
        :page-size="PER_PAGE"
        :total="total"
        @current-change="handlePageChange"
      />
    </ElCard>

    <!-- 編輯 -->
    <ElDialog
      v-model="editVisible"
      :title="`${tl('edit')} ${editForm.username}`"
      :width="isMobile ? '94%' : '460px'"
    >
      <ElForm label-width="auto">
        <ElFormItem :label="tl('form.nickname')">
          <ElInput v-model="editForm.nickname" />
        </ElFormItem>
        <ElFormItem :label="tl('list.status')">
          <ElSelect v-model="editForm.status">
            <ElOption :value="1" :label="tl('form.status_1')" />
            <ElOption :value="0" :label="tl('form.status_0')" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="tl('cs.member_agreed')">
          <ElSelect v-model="editForm.withdraw_confirm">
            <ElOption value="1" :label="tl('cs.agree')" />
            <ElOption value="0" :label="tl('cs.disagree')" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="tl('form.avatar')">
          <div class="flex items-center gap-3">
            <img
              :src="editForm.thumbnail || editForm.origThumb"
              v-if="editForm.thumbnail || editForm.origThumb"
              class="h-12 w-12 rounded object-cover"
            />
            <ElButton @click="pickAvatar()">
              {{ tl('choose_file') }}
            </ElButton>
          </div>
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="editVisible = false">
          {{ tl('form.cancel') }}
        </ElButton>
        <ElButton type="primary" :loading="submitting" @click="submitEdit">
          {{ tl('form.save') }}
        </ElButton>
      </template>
    </ElDialog>

    <!-- 開洗分 / 開分 -->
    <ElDialog
      v-model="txVisible"
      :title="
        txMode === 'limit' ? tl('list.transaction') : tl('list.transfer_in')
      "
      :width="isMobile ? '94%' : '460px'"
    >
      <ElForm label-width="auto">
        <ElRadioGroup
          v-if="txMode === 'limit' && multiWallet"
          v-model="txForm.walletType"
          class="mb-2"
        >
          <ElRadio :value="1">{{ tl('mainWallet') }}</ElRadio>
          <ElRadio :value="2">{{ tl('gameWallet') }}</ElRadio>
        </ElRadioGroup>
        <ElFormItem :label="tl('form.limit_of_credit')">
          <span>{{ txForm.balance }}</span>
          <span v-if="txForm.gameWallet != null" class="ml-3">
            {{ tl('gameWallet') }}：{{ txForm.gameWallet }}
          </span>
        </ElFormItem>
        <ElFormItem
          :label="
            txMode === 'limit'
              ? tl('cs.amount_negative_wash')
              : tl('form.amount')
          "
          required
        >
          <ElInput v-model="txForm.amount" />
        </ElFormItem>
        <ElFormItem :label="tl('form.remarks')" required>
          <ElInput v-model="txForm.remark" type="textarea" :rows="2" />
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="txVisible = false">{{ tl('form.cancel') }}</ElButton>
        <ElButton type="primary" :loading="submitting" @click="submitTx(false)">
          {{ tl('form.send') }}
        </ElButton>
      </template>
    </ElDialog>

    <!-- 付款密碼（交易回 7001 時） -->
    <ElDialog
      v-model="pwVisible"
      :title="tl('cs.payment_password')"
      :width="isMobile ? '90%' : '360px'"
    >
      <ElInput
        v-model="paymentPassword"
        type="password"
        show-password
        :[REDACTED]"tl('cs.payment_password')"
      />
      <template #footer>
        <ElButton @click="pwVisible = false">{{ tl('form.cancel') }}</ElButton>
        <ElButton
          type="primary"
          :disabled="!paymentPassword"
          :loading="submitting"
          @click="submitTx(true)"
        >
          {{ tl('form.send') }}
        </ElButton>
      </template>
    </ElDialog>
  </Page>
</template>
