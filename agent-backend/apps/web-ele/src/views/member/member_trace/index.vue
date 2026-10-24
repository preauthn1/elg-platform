<script lang="ts" setup>
import type { MemberTraceRiskMark, MemberTraceRow } from '#/api/member-trace';

import { computed, reactive, ref, watch } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElAutocomplete,
  ElButton,
  ElCard,
  ElCheckbox,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
  ElTooltip,
} from 'element-plus';

import {
  getMemberTraceListApi,
  getMemberTraceSuggestApi,
} from '#/api/member-trace';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useExcelExport } from '#/composables/use-excel-export';
import { tl } from '#/locales';
import { fromApiTime } from '#/utils/timezone-shift';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'MemberTrace' });

const PER_PAGE = 25;

/** 上組篩選條件下拉：拿該用戶的哪些值去反查關聯帳號（指紋不做） */
const TRACE_FIELD_OPTIONS = [
  { key: 'ip', label: tl('ip') },
  { key: 'realname', label: tl('real_name') },
  { key: 'password', label: tl('password') },
  { key: 'mobile', label: tl('mobile') },
  { key: 'email', label: tl('email') },
  { key: 'bank', label: tl('bank_account') },
] as const;

/** 風控標記色系：命中哪個維度就顯示哪顆點 */
const RISK_MARK_STYLE: Record<
  MemberTraceRiskMark,
  { color: string; label: string }
> = {
  bank: { color: '#67c23a', label: tl('same_bank_account') },
  email: { color: '#f7ba2a', label: tl('same_email') },
  ip: { color: '#409eff', label: tl('same_ip') },
  mobile: { color: '#b37feb', label: tl('same_mobile') },
  password: { color: '#e6a23c', label: tl('same_password') },
  realname: { color: '#f56c6c', label: tl('same_realname') },
};

/** 上組：用戶名 + 反查維度 */
const traceForm = reactive({
  username: '',
  fields: [] as string[],
});

/** 下組：直接輸入值 */
const directForm = reactive({
  email: '',
  ip: '',
  mobile: '',
});

const useAnd = ref(false);

/** 上組是否啟用（以帳號為主體；篩選條件是「拿此帳號的哪些維度反查」） */
const upperFilled = computed(() => traceForm.username.trim() !== '');

/** 下組是否有輸入 */
const lowerFilled = computed(() =>
  Object.values(directForm).some((v) => v.trim() !== ''),
);

/** 互斥：填了上組(帳號)就禁用下組，反之亦然 */
const upperDisabled = computed(() => lowerFilled.value);
const lowerDisabled = computed(() => upperFilled.value);

/** 篩選條件下拉：下組互斥反灰，或尚未填帳號時反灰（選維度前需先有帳號） */
const fieldsDisabled = computed(
  () => upperDisabled.value || traceForm.username.trim() === '',
);

/** 帳號清空時一併清掉已選維度，避免殘留無效狀態 */
watch(
  () => traceForm.username,
  (val) => {
    if (val.trim() === '' && traceForm.fields.length > 0) {
      traceForm.fields = [];
    }
  },
);

const loading = ref(false);
const list = ref<MemberTraceRow[]>([]);
const total = ref(0);
const page = ref(1);

/** 陣列顯示成 [ "值", "值" ] 樣式（對齊 ttn 呈現）；空陣列回 - */
function formatList(items?: string[]) {
  if (!items || items.length === 0) return '-';
  return `[ ${items.map((v) => `"${v}"`).join(', ')} ]`;
}

/**
 * 工單4299：IP 欄改「撞到的置頂＋近期補滿（上限 20）」，
 * 撞到者標色供風控驗證命中；未完整列出時標示總筆數。
 */
function isMatchedIp(row: any, ip: string) {
  return (row.matched_login_ips ?? []).includes(ip);
}

function ipRecentNote(row: any) {
  const shown = row.login_ips?.length ?? 0;
  const total = row.login_log_total ?? 0;
  if (!shown || total <= shown) return '';
  return tl('mt_ip_recent_note')
    .replace('{n}', String(shown))
    .replace('{total}', String(total));
}

/** 工單4299：後端總筆數封頂 10,000（回 10001 代表超過），顯示為 10,000+ */
const COUNT_CAP = 10_000;
const totalText = computed(() =>
  total.value > COUNT_CAP
    ? tl('mt_total_capped').replace('{n}', COUNT_CAP.toLocaleString())
    : total.value.toLocaleString(),
);

const { exporting, runExport } = useExcelExport();

/** 帳號快搜：ElAutocomplete 的建議來源（前綴比對，上限 10 筆） */
async function fetchUsernames(
  keyword: string,
  cb: (suggestions: Array<{ value: string }>) => void,
) {
  const kw = keyword.trim();
  if (!kw) {
    cb([]);
    return;
  }
  try {
    const res = await getMemberTraceSuggestApi(kw);
    const items = Number(res.code) === 200 ? (res.data ?? []) : [];
    cb(items.map((value) => ({ value })));
  } catch {
    cb([]);
  }
}

/** 下組有填值的條件 */
function directParams() {
  const params: Record<string, string> = {};
  for (const key of ['ip', 'mobile', 'email'] as const) {
    const value = directForm[key].trim();
    if (value) params[key] = value;
  }
  return params;
}

function buildParams(perPage: number) {
  const base = {
    page: page.value,
    per_page: perPage,
    use_and: useAnd.value,
  };
  // 上組：反查模式
  if (upperFilled.value) {
    return {
      ...base,
      username: traceForm.username.trim(),
      trace_fields: traceForm.fields,
    };
  }
  // 下組：直接模式
  return { ...base, ...directParams() };
}

/** 送查前檢查：帳號（反查）或下方任一條件（直接查），擇一即可 */
function validate() {
  if (!upperFilled.value && !lowerFilled.value) {
    ElMessage.warning(tl('member_trace_search_hint'));
    return false;
  }
  return true;
}

async function loadData() {
  if (!validate()) return;

  loading.value = true;
  try {
    const res = await getMemberTraceListApi(buildParams(PER_PAGE));
    const ok = Number(res.code) === 200;
    list.value = ok ? (res.data?.items ?? []) : [];
    total.value = ok ? (res.data?.pagination?.total ?? 0) : 0;
    if (!ok && res.message) ElMessage.error(res.message);
  } catch {
    list.value = [];
    total.value = 0;
  } finally {
    loading.value = false;
  }
}

function search() {
  page.value = 1;
  return loadData();
}

function handlePageChange(p: number) {
  page.value = p;
  return loadData();
}

function handleClear() {
  traceForm.username = '';
  traceForm.fields = [];
  directForm.ip = '';
  directForm.mobile = '';
  directForm.email = '';
  list.value = [];
  total.value = 0;
  page.value = 1;
}

async function handleExport() {
  if (!validate()) return;

  await runExport<MemberTraceRow>(
    () =>
      getMemberTraceListApi({ ...buildParams(100), page: 1 }).then((res) => ({
        code: res.code,
        data: res.data?.items ?? [],
      })),
    'member_trace',
    [
      tl('member_id'),
      tl('username'),
      tl('agent_account'),
      tl('currency'),
      tl('real_name'),
      tl('mobile'),
      tl('email'),
      tl('bank_account'),
      tl('ip'),
      tl('register_time'),
      tl('last_ip'),
      tl('last_login_time'),
    ],
    (row) => [
      row.member_id,
      row.username,
      row.agent_username,
      row.currency,
      row.realname,
      row.mobile ?? '',
      row.email ?? '',
      row.bank_accounts.join(', '),
      row.login_ips.join(', '),
      fromApiTime(row.created_at),
      row.last_login_ip ?? '',
      fromApiTime(row.last_login),
    ],
  );
}
</script>

<template>
  <Page :title="tl('menu_member_trace')">
    <ElCard class="mb-3" shadow="never">
      <ElForm label-position="top" @submit.prevent="search">
        <!-- 上組：用戶名 + 反查維度（拿該用戶的值反查關聯帳號） -->
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-4">
          <ElFormItem :label="tl('username')">
            <ElAutocomplete
              v-model="traceForm.username"
              class="w-full"
              clearable
              :debounce="300"
              :disabled="upperDisabled"
              :fetch-suggestions="fetchUsernames"
              :placeholder="tl('please_enter')"
              :trigger-on-focus="false"
            />
          </ElFormItem>
          <ElFormItem :label="tl('search_condition')" class="md:col-span-3">
            <ElSelect
              v-model="traceForm.fields"
              class="w-full"
              clearable
              :disabled="fieldsDisabled"
              multiple
              :placeholder="tl('please_select')"
            >
              <ElOption
                v-for="item in TRACE_FIELD_OPTIONS"
                :key="item.key"
                :label="item.label"
                :value="item.key"
              />
            </ElSelect>
          </ElFormItem>
        </div>

        <ElFormItem>
          <ElCheckbox v-model="useAnd">
            {{ tl('use_and_search') }}
          </ElCheckbox>
        </ElFormItem>

        <!-- 下組：直接輸入值（與上組互斥） -->
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-4">
          <ElFormItem :label="tl('ip')">
            <ElInput
              v-model="directForm.ip"
              clearable
              :disabled="lowerDisabled"
              :placeholder="tl('please_enter')"
            />
          </ElFormItem>
          <ElFormItem :label="tl('mobile')">
            <ElInput
              v-model="directForm.mobile"
              clearable
              :disabled="lowerDisabled"
              :placeholder="tl('please_enter')"
            />
          </ElFormItem>
          <ElFormItem :label="tl('email')">
            <ElInput
              v-model="directForm.email"
              clearable
              :disabled="lowerDisabled"
              :placeholder="tl('please_enter')"
            />
          </ElFormItem>
        </div>

        <div class="flex justify-end gap-2">
          <ElButton @click="handleClear">{{ tl('clear') }}</ElButton>
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <div class="mb-3 flex justify-end">
        <ElButton :loading="exporting" type="success" @click="handleExport">
          {{ tl('excel') }}
        </ElButton>
      </div>

      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn :label="tl('number')" type="index" width="70" />
        <ElTableColumn :label="tl('username')" min-width="130">
          <template #default="{ row }">
            <MemberAccountLink :username="row.username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('member_id')"
          min-width="100"
          prop="member_id"
        />
        <ElTableColumn
          :label="tl('agent_account')"
          min-width="130"
          prop="agent_username"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('risk_mark')" min-width="110">
          <template #default="{ row }">
            <div class="flex flex-wrap gap-1">
              <ElTooltip
                v-for="mark in row.risk_marks"
                :key="mark"
                :content="RISK_MARK_STYLE[mark as MemberTraceRiskMark]?.label"
                placement="top"
              >
                <span
                  class="inline-block h-3 w-3 rounded-full"
                  :style="{
                    backgroundColor:
                      RISK_MARK_STYLE[mark as MemberTraceRiskMark]?.color,
                  }"
                ></span>
              </ElTooltip>
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('currency')" min-width="80" prop="currency" />
        <ElTableColumn :label="tl('real_name')" min-width="120">
          <template #default="{ row }">{{ row.realname || '-' }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('mobile')" min-width="130">
          <template #default="{ row }">{{ row.mobile || '-' }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('email')" min-width="180">
          <template #default="{ row }">{{ row.email || '-' }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('bank_account')" min-width="200">
          <template #default="{ row }">
            {{ formatList(row.bank_accounts) }}
          </template>
        </ElTableColumn>
        <!-- 工單4299：撞到查詢條件的 IP 標色置頂，其後為近期 IP；附總筆數說明 -->
        <ElTableColumn :label="tl('ip')" min-width="220">
          <template #default="{ row }">
            <template v-if="row.login_ips?.length">
              <span
                v-for="(ip, i) in row.login_ips"
                :key="ip"
                :class="
                  isMatchedIp(row, ip) ? 'text-danger font-medium' : undefined
                "
              >
                {{ i === 0 ? '[ ' : '' }}"{{ ip }}"{{
                  i === row.login_ips.length - 1 ? ' ]' : ', '
                }}
              </span>
              <div
                v-if="ipRecentNote(row)"
                class="text-muted-foreground text-xs"
              >
                {{ ipRecentNote(row) }}
              </div>
            </template>
            <template v-else>-</template>
          </template>
        </ElTableColumn>
        <!-- 後端輸出 GMT+8，依所選時區平移顯示（target/tickets#4492） -->
        <ElTableColumn
          :formatter="(row: MemberTraceRow) => fromApiTime(row.created_at)"
          :label="tl('register_time')"
          min-width="160"
          prop="created_at"
        />
        <ElTableColumn :label="tl('last_ip')" min-width="130">
          <template #default="{ row }">{{ row.last_login_ip || '-' }}</template>
        </ElTableColumn>
        <ElTableColumn
          :formatter="(row: MemberTraceRow) => fromApiTime(row.last_login)"
          :label="tl('last_login_time')"
          min-width="160"
          prop="last_login"
        />
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>

      <!-- 工單4299：命中數封頂 10,000（超過顯示 10,000+，代表條件過寬） -->
      <div
        v-if="total > 0"
        class="text-muted-foreground mt-2 text-right text-xs"
      >
        {{ tl('total') }}：{{ totalText }}
      </div>
      <TablePagination
        :current-page="page"
        :page-size="PER_PAGE"
        :total="total"
        @current-change="handlePageChange"
      />
    </ElCard>
  </Page>
</template>
