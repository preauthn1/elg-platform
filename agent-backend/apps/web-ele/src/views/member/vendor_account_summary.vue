<script lang="ts" setup>
import type { Sr06Row, VendorAccountSummary } from '#/api';

import { computed, defineAsyncComponent, ref, watch } from 'vue';

import { useAccess } from '@vben/access';
import { Page } from '@vben/common-ui';
import { useUserStore } from '@vben/stores';

import dayjs from 'dayjs';
import {
  ElButton,
  ElCard,
  ElCheckbox,
  ElDescriptions,
  ElDescriptionsItem,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElMessageBox,
  ElTable,
  ElTableColumn,
  ElTag,
} from 'element-plus';

import {
  getSr06Api,
  getVendorAccountSummaryApi,
  getVendorBalanceApi,
  refundTransactionApi,
  unbindVendorAccountApi,
  vendorTransferInApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';
import AgentProfileDialog from '#/views/agent/components/agent-profile-dialog.vue';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'MemberVendorAccountSummary' });

const agentProfileRef = ref<InstanceType<typeof AgentProfileDialog>>();
const userStore = useUserStore();
const { hasAccessByCodes } = useAccess();
const CustomizedSettingNew = defineAsyncComponent(
  () => import('#/views/agent/agent_customized_setting_new.vue'),
);
const settingVisible = ref(false);
const settingGroupId = ref<number | string>('');

/** 個性化組別點擊：彈窗載入該組進階設定 */
function openCustomizedSetting(groupId?: number | string) {
  if (!groupId) return;
  settingGroupId.value = groupId;
  settingVisible.value = true;
}

const canUnbind = hasAccessByCodes(['member_vendor_account_unbind']);

const username = ref('');
const loading = ref(false);
const summary = ref<null | VendorAccountSummary>(null);
const errorMsg = ref('');
const sr06List = ref<Sr06Row[]>([]);

/** GCP 連結附加 &authuser=1 */
const isAuth1 = ref(localStorage.getItem('is_auth_1') === '1');
watch(isAuth1, (v) => localStorage.setItem('is_auth_1', v ? '1' : '0'));

const chatDbVisible = ref(false);

const gcpProjectId = computed(
  () => summary.value?.commonInfo?.gcp_project_id ?? '',
);

const fmtNum = (v?: null | number | string) => amountThousands(v, 2);

async function search() {
  if (!username.value) {
    ElMessage.warning('please input username.');
    return;
  }
  loading.value = true;
  summary.value = null;
  errorMsg.value = '';
  sr06List.value = [];
  try {
    const [sumRes, sr06Res] = await Promise.all([
      getVendorAccountSummaryApi(username.value),
      getSr06Api({
        end_date: dayjs().format('YYYY-MM-DD'),
        member_username: username.value,
        page: 1,
        per_page: 30,
        start_date: dayjs().subtract(7, 'day').format('YYYY-MM-DD'),
        status: 0,
      }).catch(() => null),
    ]);
    if (Number(sumRes.code) === 1000) {
      summary.value = sumRes.data ?? null;
    } else {
      errorMsg.value = sumRes.message || `(${sumRes.code})`;
    }
    if (sr06Res && Number(sr06Res.code) === 1000) {
      sr06List.value = sr06Res.data ?? [];
    }
  } catch (error: any) {
    errorMsg.value = String(error?.message ?? error);
  } finally {
    loading.value = false;
  }
}

/** GCP log 連結 */
function openGcp(query: string) {
  let url = `https://example.invalid/logs/query;query=${query}?project=${gcpProjectId.value}&query=%0A`;
  if (isAuth1.value) url += '&authuser=1';
  window.open(url, '_blank');
}

/** UTC 時間字串 */
function utcStamp(offsetHours: number) {
  const iso = new Date(Date.now() - offsetHours * 3_600_000).toISOString();
  return `${iso.slice(0, 16)}:00Z`;
}

function gcpQueryHours(vendorUsername: string, hours: number) {
  return `${vendorUsername} AND timestamp ${encodeURIComponent(`>="${utcStamp(hours)}"`)}`;
}

/** 交易列 GCP 查詢：created_at_utc 前後 1 分鐘 */
function gcpQueryTransaction(row: Sr06Row) {
  const mapped =
    summary.value?.vendor_accounts_mappingDic?.[String(row.vendor_id)];
  const base = dayjs(row.created_at_utc);
  const before = `${base.subtract(1, 'minute').format('YYYY-MM-DD[T]HH:mm:ss')}Z`;
  const after = `${base.add(1, 'minute').format('YYYY-MM-DD[T]HH:mm:ss')}Z`;
  return (
    `${mapped?.vendor_username ?? ''}` +
    ` AND timestamp ${encodeURIComponent(`>="${before}"`)}` +
    ` AND timestamp ${encodeURIComponent(`<="${after}"`)}`
  );
}

function copyText(text?: string) {
  if (!text) return;
  navigator.clipboard.writeText(text).then(() => {
    ElMessage.success(tl('copy_successfully'));
  });
}

/** 遊戲錢包餘額 */
async function getVendorBalance(vendorId?: number) {
  const memberId = summary.value?.member?.member_id;
  if (!memberId || !vendorId) return;
  const res = await getVendorBalanceApi({
    member_id: memberId,
    vendor_id: vendorId,
  });
  if (Number(res.code) === 1000) {
    const msg = `${tl('frontend_response_to_query')}${tl('gcp_log')}`.replace(
      '{TEXT}',
      JSON.stringify(res.data),
    );
    ElMessageBox.alert(msg);
  } else {
    ElMessageBox.alert(`${res.message}.${res.data ?? ''}`);
  }
}

/** 遊戲錢包餘額取回 */
async function getVendorTransferIn(item: {
  vendor_id?: number;
  vendor_info_id?: number;
  vendor_username?: string;
}) {
  const res = await vendorTransferInApi({
    account: item.vendor_username ?? '',
    vendor_id: item.vendor_id ?? '',
    vendor_info_id: item.vendor_info_id ?? '',
  });
  if (Number(res.code) === 1000) {
    ElMessageBox.alert(
      `${tl('api_internal_response')}\n${JSON.stringify(res.message)}`,
    );
  } else {
    ElMessageBox.alert(res.message);
  }
}

/** 解綁帳號 */
async function unbindAccount(item: {
  vendor_id?: number;
  vendor_info_id?: number;
  vendor_username_orig?: string;
}) {
  const res = await unbindVendorAccountApi({
    account: item.vendor_username_orig ?? '',
    vendor_id: item.vendor_id ?? '',
    vendor_info_id: item.vendor_info_id ?? '',
  });
  if (Number(res.code) === 1000) {
    ElMessage.success(String(res.message));
    search();
  } else {
    ElMessageBox.alert(res.message);
  }
}

/** 補償 */
const hostId = computed(() =>
  Number((userStore.userInfo as any)?.host_id ?? -1),
);
function showCompensate(row: Sr06Row) {
  return (
    Number(row.status) === 9995 &&
    Number(row.point) <= 1000 &&
    hostId.value === 33_893
  );
}
async function refund(row: Sr06Row) {
  const res = await refundTransactionApi(row.id);
  if (Number(res.code) === 1000) {
    ElMessage.success(String(res.message));
    search();
  } else {
    ElMessageBox.alert(
      Number(res.code) === 2002 ? String(res.data) : res.message,
    );
  }
}

function sr06StatusText(row: Sr06Row) {
  const s = Number(row.status);
  let text = s === 1000 ? tl('success') : tl('failure');
  if (s === 9000 || s === 9990) text += `(${tl('returned')})`;
  return text;
}
</script>

<template>
  <Page
    :title="`${tl('member_lower_management')} > ${tl('menu_member_vendor_account_summary')}`"
  >
    <!-- 查詢 -->
    <ElCard class="mb-3" shadow="never">
      <ElForm inline @submit.prevent="search">
        <ElFormItem :label="tl('enter_member_account')">
          <ElInput
            v-model="username"
            :placeholder="tl('member_account')"
            class="!w-60"
            clearable
          />
        </ElFormItem>
        <ElFormItem>
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </ElFormItem>
      </ElForm>
    </ElCard>

    <div v-if="errorMsg" class="py-6 text-center text-red-500">
      {{ errorMsg }}
    </div>

    <template v-if="summary">
      <!-- 會員資訊列 -->
      <ElCard class="mb-3" shadow="never">
        <div class="flex flex-wrap items-center gap-x-8 gap-y-2">
          <div>
            <b>{{ tl('member_account') }}：</b>
            <MemberAccountLink
              :member-id="summary.member?.member_id"
              :username="summary.member?.member_username"
            />
            ({{ summary.member?.member_id }})
          </div>
          <div>
            <b>{{ tl('agent') }}：</b>
            <ElButton
              link
              type="primary"
              @click="agentProfileRef?.open(summary.member?.agent_id ?? 0)"
            >
              {{ summary.member?.agent_name }}
            </ElButton>
            ({{ summary.member?.agent_id }})
          </div>
          <div>
            <b>{{ tl('customized_setting') }}：</b>
            <ElButton
              v-if="summary.member?.customized_setting_group_id"
              link
              type="primary"
              @click="
                openCustomizedSetting(
                  summary.member?.customized_setting_group_id,
                )
              "
            >
              {{ summary.member?.customized_setting_group_id }}
            </ElButton>
            <span v-else>{{
              summary.member?.customized_setting_group_id
            }}</span>
          </div>
          <div>
            <b>{{ tl('cs_db') }}：</b>
            <ElButton link type="primary" @click="chatDbVisible = true">
              {{ tl('search') }}
            </ElButton>
          </div>
          <ElCheckbox v-model="isAuth1">{{ tl('authuser') }}</ElCheckbox>
        </div>
      </ElCard>

      <!-- 遊戲帳號 -->
      <ElCard class="mb-3" shadow="never">
        <template #header>
          <span class="font-medium">{{ tl('game_account') }}</span>
        </template>
        <ElTable :data="summary.vendor_accounts ?? []" size="small">
          <ElTableColumn :label="tl('status')" width="90">
            <template #default="{ row }">
              <ElTag
                :type="Number(row.vendor_status) === 0 ? 'info' : 'success'"
              >
                {{
                  Number(row.vendor_status) === 0 ? tl('disable') : tl('enable')
                }}
              </ElTag>
            </template>
          </ElTableColumn>
          <ElTableColumn :label="tl('id')" prop="vendor_id" width="70" />
          <ElTableColumn
            :label="tl('label_vendor')"
            prop="vendor_name"
            min-width="120"
          />
          <ElTableColumn
            :label="`${tl('label_vendor')}${tl('account_name')}`"
            min-width="160"
          >
            <template #default="{ row }">
              <span
                class="cursor-pointer hover:underline"
                title="Copy"
                @click="copyText(row.vendor_username)"
              >
                {{ row.vendor_username }}
              </span>
            </template>
          </ElTableColumn>
          <ElTableColumn min-width="220">
            <template #default="{ row }">
              <template v-if="row.vendor_username !== '-'">
                <ElButton
                  link
                  type="primary"
                  @click="openGcp(row.vendor_username)"
                >
                  {{ tl('gcp') }}
                </ElButton>
                <ElButton
                  link
                  type="primary"
                  @click="openGcp(gcpQueryHours(row.vendor_username, 1))"
                >
                  {{ tl('gcp') }}(1{{ tl('hour') }})
                </ElButton>
                <ElButton
                  link
                  type="primary"
                  @click="openGcp(gcpQueryHours(row.vendor_username, 4))"
                >
                  {{ tl('gcp') }}(4{{ tl('hour') }})
                </ElButton>
              </template>
            </template>
          </ElTableColumn>
          <ElTableColumn min-width="320" fixed="right">
            <template #default="{ row }">
              <template
                v-if="
                  Number(row.vendor_status) === 1 && row.vendor_username !== '-'
                "
              >
                <ElButton
                  size="small"
                  type="danger"
                  @click="getVendorBalance(row.vendor_id)"
                >
                  {{ tl('game_wallet') }}
                </ElButton>
                <ElButton
                  v-if="Number(row.vendor_id) === 22"
                  size="small"
                  type="warning"
                  @click="getVendorTransferIn(row)"
                >
                  {{ tl('retrieve_balance') }}
                </ElButton>
                <ElButton
                  v-if="canUnbind && Number(row.vendor_unbind) === 1"
                  size="small"
                  type="info"
                  @click="unbindAccount(row)"
                >
                  {{ tl('unbind_account') }}
                </ElButton>
              </template>
              <ElButton v-else disabled size="small">
                {{ tl('not_open') }}
              </ElButton>
              <template v-if="Number(row.vendor_id) === 24">
                <a
                  :href="`https://example.invalid/games-management/record?playerName=${row.vendor_username}`"
                  target="_blank"
                >
                  <ElButton size="small" type="primary">
                    {{ tl('pgs_event') }}
                  </ElButton>
                </a>
                <a
                  :href="`https://example.invalid/members-management/money-record?playerName=${row.vendor_username}`"
                  class="ml-2"
                  target="_blank"
                >
                  <ElButton size="small" type="primary">
                    {{ tl('pgs_coin') }}
                  </ElButton>
                </a>
              </template>
            </template>
          </ElTableColumn>
        </ElTable>
      </ElCard>

      <!-- 近 30 筆紀錄 -->
      <ElCard shadow="never">
        <template #header>
          <span class="font-medium">{{ tl('top_30record') }}</span>
        </template>
        <ElTable :data="sr06List" border size="small" stripe>
          <ElTableColumn :label="tl('num')" prop="id" width="90" />
          <ElTableColumn :label="tl('ordernum')" min-width="190">
            <template #default="{ row }">
              <ElButton
                link
                type="primary"
                @click="openGcp(gcpQueryTransaction(row as Sr06Row))"
              >
                {{ row.transaction_no }}
              </ElButton>
              ({{ tl('gcp_log') }})
            </template>
          </ElTableColumn>
          <ElTableColumn :label="tl('date')" prop="created_at" width="160" />
          <ElTableColumn
            :label="tl('agent_account')"
            prop="agent_username"
            min-width="100"
          >
            <template #default="{ row }">
              <AgentAccount :username="row.agent_username" />
            </template>
          </ElTableColumn>
          <ElTableColumn :label="tl('platform')" width="110">
            <template #default="{ row }">
              <span class="text-info font-bold">{{ row.vendor }}</span>
            </template>
          </ElTableColumn>
          <ElTableColumn :label="tl('type')" width="90">
            <template #default="{ row }">
              {{
                Number(row.type) === 1 ? tl('transferout') : tl('transferin')
              }}
            </template>
          </ElTableColumn>
          <ElTableColumn :label="tl('member_account')" min-width="100">
            <template #default="{ row }">
              <MemberAccountLink :username="row.member_username" />
            </template>
          </ElTableColumn>
          <ElTableColumn
            :label="tl('member_nickname')"
            prop="member_nickname"
            min-width="100"
          />
          <ElTableColumn :label="tl('change_point')" align="right" width="110">
            <template #default="{ row }">
              <span v-if="Number(row.type) === 1" class="text-red-500">
                {{ fmtNum(-Number(row.point)) }}
              </span>
              <span v-else>{{ fmtNum(row.point) }}</span>
            </template>
          </ElTableColumn>
          <ElTableColumn :label="tl('moment_point')" align="right" width="110">
            <template #default="{ row }">{{ fmtNum(row.balance) }}</template>
          </ElTableColumn>
          <ElTableColumn :label="tl('status')" width="130">
            <template #default="{ row }">
              <span
                :class="
                  Number(row.status) === 1000
                    ? 'text-green-600'
                    : 'text-red-500'
                "
              >
                {{ sr06StatusText(row as Sr06Row) }}
              </span>
              <ElButton
                v-if="showCompensate(row as Sr06Row)"
                size="small"
                type="danger"
                plain
                @click="refund(row as Sr06Row)"
              >
                {{ tl('compensate') }}
              </ElButton>
            </template>
          </ElTableColumn>
          <template #empty>{{ tl('no_record') }}</template>
        </ElTable>
      </ElCard>

      <!-- 客服 DB 資訊 -->
      <ElDialog
        v-model="chatDbVisible"
        :title="`${tl('cs_db')}${tl('chat_db')}`"
        width="700"
        align-center
      >
        <ElDescriptions :column="2" border>
          <ElDescriptionsItem
            :label="`${tl('members_member_id')}${tl('chat_db')}`"
          >
            {{ summary.chatDB_member?.member_id ?? '-' }}
          </ElDescriptionsItem>
          <ElDescriptionsItem :label="tl('unlimit_parent_member')">
            {{ summary.member?.agent_name ?? '-' }}
          </ElDescriptionsItem>
          <ElDescriptionsItem
            :label="`${tl('parent_members_member_id')}${tl('chat_db')}`"
          >
            {{ summary.chatDB_member?.parent_agent_member_id ?? '-' }}
          </ElDescriptionsItem>
          <ElDescriptionsItem
            :label="`${tl('parent_members_user_id')}${tl('chat_db')}`"
          >
            {{ summary.chatDB_member?.parent_agent_user_id ?? '-' }}
          </ElDescriptionsItem>
          <ElDescriptionsItem :label="tl('agent_group_type_4')">
            {{ summary.member?.chat_group_id ?? '-' }}
          </ElDescriptionsItem>
          <ElDescriptionsItem
            :label="`${tl('customized_setting')} ${tl('envelop')}`"
          >
            {{ summary.member?.customized_setting_group_id ?? '-' }}
          </ElDescriptionsItem>
        </ElDescriptions>
      </ElDialog>
    </template>
    <AgentProfileDialog ref="agentProfileRef" />

    <ElDialog
      v-model="settingVisible"
      :title="`${tl('customized_setting')} (${settingGroupId})`"
      destroy-on-close
      top="5vh"
      width="1100"
    >
      <div class="max-h-[80vh] overflow-y-auto">
        <CustomizedSettingNew
          v-if="settingVisible"
          :key="settingGroupId"
          :embed-group-id="String(settingGroupId)"
        />
      </div>
    </ElDialog>
  </Page>
</template>
