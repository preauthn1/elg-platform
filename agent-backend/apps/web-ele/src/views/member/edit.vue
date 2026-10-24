<script lang="ts" setup>
import type { CountryRow, MemberGroupOption, MemberRow } from '#/api';

import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';

import { useAccess } from '@vben/access';
import { Page } from '@vben/common-ui';
import { useTabs } from '@vben/hooks';
import { useUserStore } from '@vben/stores';

import {
  ElButton,
  ElCard,
  ElDatePicker,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElOption,
  ElRadio,
  ElRadioGroup,
  ElSelect,
} from 'element-plus';

import {
  getCountryDropdownApi,
  getCustomizedSettingApi,
  getMemberGroupDropdownApi,
  getMemberViewApi,
  resetMemberMobileApi,
  saveMemberApi,
} from '#/api';
import { useAgentOptions } from '#/composables/use-agent-options';
import { useLeavePage } from '#/composables/use-leave-page';
import { tl } from '#/locales';

defineOptions({ name: 'MemberEdit' });

const route = useRoute();
const leaveTo = useLeavePage();
const userStore = useUserStore();
const { hasAccessByCodes } = useAccess();

const { setTabTitle } = useTabs();

const canMobileValidate = hasAccessByCodes(['mobile_validate_permission']);
const canRevealPhone = hasAccessByCodes(['reveal_phone_number']);

/** 進頁取一次；跟著 route 走的話，切分頁時離場中的舊頁標題會先跳成新頁的 */
const editId = (() => {
  const id = Number(route.query.id ?? 0);
  return Number.isFinite(id) && id > 0 ? id : 0;
})();

const member = ref<MemberRow | null>(null);
const {
  loading: agentSearching,
  options: agents,
  pin: pinAgent,
  search: searchAgents,
} = useAgentOptions();
const countries = ref<CountryRow[]>([]);
const memberGroups = ref<MemberGroupOption[]>([]);
/** 姓名可否編輯 */
const accountEdit = ref(1);

const loading = ref(false);
const saving = ref(false);

const form = reactive({
  address: '',
  birthday: '',
  city: '',
  country_code: '' as number | string,
  country_id: '' as number | string,
  email: '',
  first_name: '',
  ip_change_email_verify: 0,
  ip_change_mobile_verify: 0,
  member_group_id: '' as number | string,
  mobile: '',
  mobile_validate: 0,
  nickname: '',
  parent: '' as number | string,
  password: '',
  password_confirmation: '',
  payment_password: '',
  payment_password_confirmation: '',
  realname_validate: 0,
  // 新增模式預設「停用」
  status: 0,
  username: '',
});

/** 姓名唯讀 */
const firstNameReadonly = computed(
  () =>
    !!editId &&
    !(accountEdit.value === 1 || (member.value?.first_name ?? '') === ''),
);

async function loadEditData() {
  loading.value = true;
  try {
    const [m, settings] = await Promise.all([
      getMemberViewApi(editId),
      getCustomizedSettingApi().catch(() => []),
    ]);
    member.value = m ?? null;
    // 分頁標題補上帳號以區分多個編輯分頁。只能傳字串，
    // 傳 ComputedRef 會讓分頁存進 sessionStorage 時序列化失敗
    if (m?.username) setTabTitle(`${tl('detailinfo_edit')}(${m.username})`);
    const map = new Map(
      (settings ?? []).map((s: any) => [Number(s.setting_key), s.value]),
    );
    accountEdit.value = Number(map.get(95_201) ?? 1);
    if (m) {
      form.first_name = m.first_name ?? '';
      form.birthday = (m as any).birthday ?? '';
      form.nickname = (m as any).nickname ?? '';
      form.email = (m as any).email ?? '';
      form.country_id =
        Number((m as any).country_id) > 0 ? (m as any).country_id : '';
      form.city = (m as any).city ?? '';
      form.address = (m as any).address ?? '';
      form.mobile = '';
      form.status = Number(m.status ?? 0);
      form.mobile_validate = Number(m.mobile_validate) === 1 ? 1 : 0;
      form.realname_validate = Number(m.realname_validate) === 1 ? 1 : 0;
      form.ip_change_mobile_verify =
        Number((m as any).ip_change_mobile_verify ?? 0) === 1 ? 1 : 0;
      form.ip_change_email_verify =
        Number((m as any).ip_change_email_verify ?? 0) === 1 ? 1 : 0;
      form.member_group_id =
        Number((m as any).member_group_id) > 0
          ? Number((m as any).member_group_id)
          : '';

      // 群組屬於會員的直屬代理，依 member_id 撈該會員可指派的群組
      const groupRes = await getMemberGroupDropdownApi({
        member_id: editId,
      }).catch(() => null);
      memberGroups.value =
        groupRes && Number(groupRes.code) === 200 ? (groupRes.data ?? []) : [];
    }
  } finally {
    loading.value = false;
  }
}

async function handleSave() {
  saving.value = true;
  try {
    const data: Record<string, unknown> = {
      address: form.address,
      city: form.city,
      country_id: form.country_id,
      email: form.email,
      first_name: form.first_name,
      ip_change_email_verify: form.ip_change_email_verify,
      ip_change_mobile_verify: form.ip_change_mobile_verify,
      last_name: '',
      member_group_id: Number(form.member_group_id || 0),
      mobile_validate: form.mobile_validate,
      nickname: form.nickname,
      realname_validate: form.realname_validate,
      status: form.status,
    };
    if (form.birthday !== '') data.birthday = form.birthday;
    if (form.mobile !== '') data.mobile = `${form.country_code}${form.mobile}`;
    if (form.password !== '') {
      data.password = [REDACTED];
      data.password_confirmation = form.password_confirmation;
    }
    if (form.payment_password !== '') {
      data.payment_password = [REDACTED];
      data.payment_password_confirmation = form.payment_password_confirmation;
    }
    if (editId) {
      data.id = editId;
    } else {
      data.username = form.username;
      data.parent = form.parent;
    }
    const res = await saveMemberApi(data);
    if (Number(res.code) === 1000) {
      ElMessage.success(res.message);
      await (editId ? leaveTo() : leaveTo('/member/index'));
    } else {
      // 2002 顯示 data，其餘顯示 message
      ElMessage.error(
        Number(res.code) === 2002 ? String(res.data) : res.message,
      );
    }
  } finally {
    saving.value = false;
  }
}

/** 重置手機號 */
async function handleResetMobile() {
  const res = await resetMemberMobileApi(editId);
  if (Number(res.code) === 1000) {
    await leaveTo();
  } else {
    ElMessage.error(Number(res.code) === 2002 ? String(res.data) : res.message);
  }
}

onMounted(async () => {
  // 先載入國家清單，確保 loadEditData 設定 country_id 後才做預設，避免競態把值蓋成 0
  const d = await getCountryDropdownApi().catch(() => []);
  countries.value = d ?? [];
  if (editId) {
    await loadEditData();
  } else {
    // 可選擇玩家掛在哪個代理下，預設本人
    const me = userStore.userInfo as any;
    form.parent = Number(me?.id ?? '');
    await searchAgents('');
    pinAgent([{ id: Number(me?.id ?? ''), username: me?.username ?? '' }]);
  }
  // country_id 為 0/未設時，比照舊版 <select> 預設選第一個國家
  if (!form.country_id && countries.value.length > 0) {
    form.country_id = countries.value[0]?.id ?? '';
  }
  if (!form.country_code && countries.value.length > 0) {
    form.country_code = (countries.value[0] as any).country_code ?? '';
  }
});
</script>

<template>
  <Page
    :title="`${tl('member_lower_management')} > ${tl('member_account_management')} > ${tl('detailinfo_user')} > ${tl('detailinfo_edit')}`"
    auto-content-height
    footer-class="border-t border-border"
  >
    <ElCard v-loading="loading" shadow="never">
      <ElForm label-position="top" @submit.prevent="handleSave">
        <div class="mx-auto grid max-w-4xl grid-cols-1 gap-x-6 md:grid-cols-2">
          <ElFormItem :label="tl('agent_account')">
            <span v-if="editId" class="text-green-600">
              {{ member?.agent_username }}
            </span>
            <ElSelect
              v-else
              v-model="form.parent"
              :debounce="500"
              :loading="agentSearching"
              :remote-method="searchAgents"
              class="w-full"
              filterable
              remote
            >
              <ElOption
                v-for="a in agents"
                :key="a.id"
                :label="a.username"
                :value="a.id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('username')" :required="!editId">
            <span v-if="editId" class="text-info">{{ member?.username }}</span>
            <ElInput
              v-else
              v-model="form.username"
              autocomplete="off"
              placeholder="Account"
            />
          </ElFormItem>
          <ElFormItem v-if="editId" :label="tl('member_group_belong')">
            <ElSelect v-model="form.member_group_id" class="w-full" clearable>
              <!-- 「無」＝取消指派群組；空字串於存檔時轉 0，後端寫入 null -->
              <ElOption :label="tl('member_group_none')" value="" />
              <ElOption
                v-for="g in memberGroups"
                :key="g.id"
                :label="g.name"
                :value="g.id"
              />
            </ElSelect>
          </ElFormItem>

          <template v-if="!editId">
            <ElFormItem :label="tl('password')" required>
              <ElInput
                v-model="form.password"
                autocomplete="new-password"
                placeholder="Password"
                show-password
                type="password"
              />
            </ElFormItem>
            <ElFormItem :label="tl('password_confirm')" required>
              <ElInput
                v-model="form.password_confirmation"
                autocomplete="new-password"
                placeholder="Confirm Password"
                show-password
                type="password"
              />
            </ElFormItem>
            <ElFormItem :label="tl('payment_password')">
              <ElInput
                v-model="form.payment_password"
                autocomplete="new-password"
                placeholder="Payment Password"
                show-password
                type="password"
              />
            </ElFormItem>
            <ElFormItem :label="tl('payment_password_confirm')">
              <ElInput
                v-model="form.payment_password_confirmation"
                autocomplete="new-password"
                placeholder="Confirm Payment Password"
                show-password
                type="password"
              />
            </ElFormItem>
          </template>

          <ElFormItem :label="tl('real_name')">
            <ElInput
              v-model="form.first_name"
              :readonly="firstNameReadonly"
              placeholder="Enter full name"
            />
          </ElFormItem>
          <ElFormItem :label="tl('birthday')">
            <ElDatePicker
              v-model="form.birthday"
              class="!w-full"
              format="YYYY-MM-DD"
              type="date"
              value-format="YYYY-MM-DD"
            />
          </ElFormItem>
          <ElFormItem :label="tl('nickname')">
            <ElInput v-model="form.nickname" placeholder="Enter nickname" />
          </ElFormItem>
          <ElFormItem :label="tl('email')">
            <ElInput
              v-model="form.email"
              autocomplete="off"
              placeholder="Enter email"
              type="email"
            />
          </ElFormItem>
          <ElFormItem :label="tl('country')">
            <ElSelect v-model="form.country_id" class="w-full" filterable>
              <ElOption
                v-for="c in countries"
                :key="c.id"
                :label="c.name"
                :value="c.id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('city')">
            <ElInput v-model="form.city" placeholder="City/Zone" />
          </ElFormItem>
          <ElFormItem :label="tl('address')">
            <ElInput v-model="form.address" placeholder="Address" />
          </ElFormItem>
          <ElFormItem
            v-if="canRevealPhone"
            :label="`${tl('tel')}(${tl('mobile_filter_zero')})`"
          >
            <!-- 已有手機號：顯示 + 重置 -->
            <template v-if="editId && member?.mobile">
              <span class="mr-3">{{ member?.mobile }}</span>
              <ElButton size="small" @click="handleResetMobile">
                {{ tl('reset') }}
              </ElButton>
            </template>
            <div v-else class="flex w-full gap-2">
              <ElSelect v-model="form.country_code" class="!w-44" filterable>
                <ElOption
                  v-for="c in countries"
                  :key="c.id"
                  :label="`${c.name} +${c.country_code}`"
                  :value="c.country_code ?? ''"
                />
              </ElSelect>
              <ElInput
                v-model="form.mobile"
                class="flex-1"
                placeholder="Mobile"
              />
            </div>
          </ElFormItem>
          <ElFormItem
            v-if="canMobileValidate"
            :label="tl('mobile_authentication')"
          >
            <ElRadioGroup v-model="form.mobile_validate">
              <ElRadio :value="1">{{ tl('pass') }}</ElRadio>
              <ElRadio :value="0">{{ tl('not_certified') }}</ElRadio>
            </ElRadioGroup>
          </ElFormItem>
          <ElFormItem :label="tl('status')">
            <!-- 編輯模式僅顯示，不可改 -->
            <span
              v-if="editId"
              :class="form.status === 1 ? 'text-green-600' : 'text-red-500'"
            >
              {{ form.status === 1 ? tl('enable') : tl('disable') }}
            </span>
            <ElRadioGroup v-else v-model="form.status">
              <ElRadio :value="1">{{ tl('enable') }}</ElRadio>
              <ElRadio :value="0">{{ tl('disable') }}</ElRadio>
            </ElRadioGroup>
          </ElFormItem>
          <ElFormItem :label="tl('realname_authentication')">
            <ElRadioGroup v-model="form.realname_validate">
              <ElRadio :value="1">{{ tl('pass') }}</ElRadio>
              <ElRadio :value="0">{{ tl('not_certified') }}</ElRadio>
            </ElRadioGroup>
          </ElFormItem>
          <ElFormItem :label="tl('ip_change_mobile_verification')">
            <ElRadioGroup v-model="form.ip_change_mobile_verify">
              <ElRadio :value="1">{{ tl('enable') }}</ElRadio>
              <ElRadio :value="0">{{ tl('disable') }}</ElRadio>
            </ElRadioGroup>
          </ElFormItem>
          <ElFormItem :label="tl('ip_change_email_verification')">
            <ElRadioGroup v-model="form.ip_change_email_verify">
              <ElRadio :value="1">{{ tl('enable') }}</ElRadio>
              <ElRadio :value="0">{{ tl('disable') }}</ElRadio>
            </ElRadioGroup>
          </ElFormItem>
        </div>
      </ElForm>
    </ElCard>
    <template #footer>
      <div class="mx-auto flex w-full max-w-4xl justify-end gap-2">
        <ElButton @click="leaveTo()">{{ tl('btn_back') }}</ElButton>
        <ElButton :loading="saving" type="primary" @click="handleSave">
          {{ tl('btn_update') }}
        </ElButton>
      </div>
    </template>
  </Page>
</template>
