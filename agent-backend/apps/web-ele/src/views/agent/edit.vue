<script lang="ts" setup>
import type {
  AgentMenuNode,
  AgentPermissionGroupOption,
  CountryRow,
} from '#/api';

import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';

import { useAccess } from '@vben/access';
import { Page } from '@vben/common-ui';
import { useTabs } from '@vben/hooks';
import { useUserStore } from '@vben/stores';

import {
  ElButton,
  ElCard,
  ElCheckbox,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElMessageBox,
  ElOption,
  ElRadio,
  ElRadioGroup,
  ElSelect,
} from 'element-plus';

import {
  addAgentApi,
  confirmAgentPasswordApi,
  editAgentApi,
  getAgentDetailApi,
  getAgentGroupDropdownApi,
  getAgentMenuTreeApi,
  getAgentPermissionGroupDropdownApi,
  getAgentViewApi,
  getCountryDropdownApi,
  getCustomizedSettingApi,
  setAgentPermissionApi,
} from '#/api';
import { useAgentOptions } from '#/composables/use-agent-options';
import { useLeavePage } from '#/composables/use-leave-page';
import { tl } from '#/locales';

defineOptions({ name: 'AgentEdit' });

const route = useRoute();
const leaveTo = useLeavePage();
const userStore = useUserStore();
const { hasAccessByCodes } = useAccess();
const { setTabTitle } = useTabs();

const canAdvanceSetting = hasAccessByCodes(['agent_advance_setting']);
const canEditMobileFlag = hasAccessByCodes(['agent_edit_mobile_validate']);
const canEditUnlimited = hasAccessByCodes(['agent_edit_unlimited']);
const canAgentGroup = hasAccessByCodes(['agent_group']);

/** 進頁取一次；跟著 route 走的話，切分頁時離場中的舊頁標題會先跳成新頁的 */
const editId = (() => {
  const id = Number(route.query.id ?? 0);
  return Number.isFinite(id) && id > 0 ? id : 0;
})();
const ownId = computed(() => Number((userStore.userInfo as any)?.id ?? 0));
const isSelf = computed(() => editId === ownId.value);

const agent = ref<any>(null);
const countries = ref<CountryRow[]>([]);
const groups = ref<
  Array<{ agent_group_type: number; id: number; name: string }>
>([]);
/** 工單4080：代理群組（權限角色）下拉，含各角色的選單ID供選取後自動勾選 */
const permissionGroups = ref<AgentPermissionGroupOption[]>([]);
/** customized settings：95202 姓名可編輯 */
const enableEditRealName = ref(false);

const loading = ref(false);
const saving = ref(false);

const form = reactive({
  address: '',
  agent_mode: 1,
  /** 工單4080：代理群組（權限角色）。'' = 不選擇角色，僅吃個人權限 */
  agent_permission_group_id: '' as number | string,
  cash_flow_group_id: '' as number | string,
  chat_group_id: '' as number | string,
  city: '',
  country_id: '' as number | string,
  customized_setting_group_id: '' as number | string,
  email: '',
  email_validate: 0,
  frontend_register: 1,
  has_apk: 0,
  has_chat: 0,
  has_ipa: 0,
  has_mobile: 0,
  has_pc: 0,
  is_sub_account: 0,
  /** 工單4498：登入 IP 白名單，逗號分隔；空＝不限制 */
  login_ip_whitelist: '',
  member_field_setting_group_id: '' as number | string,
  mobile: '',
  mobile_validate: 1,
  nickname: '',
  parent: '' as number | string,
  password: '',
  password_confirmation: '',
  percentage: '' as number | string,
  real_name: '',
  realname_validate: 0,
  email_gateway_group_id: '' as number | string,
  sms_gateway_group_id: '' as number | string,
  status: 1,
  username: '',
  vendor_customize_group_id: '' as number | string,
});

const groupsByType = (t: number) =>
  groups.value.filter((g) => Number(g.agent_group_type) === t);

/** 是否總代理（host_id=0 才有 email/mobile 驗證、註冊開關等區塊；新增模式視同總代） */
const isMainBlock = computed(() =>
  editId ? Number(agent.value?.host_id ?? 0) === 0 : true,
);

/**
 * 工單4498：登入 IP 白名單區塊。顯示與否看被編輯的帳號（admin／admin 子帳號／總代／總代子帳號才有，
 * 由 agent/view 回的 login_ip_whitelist_editable 決定，與後端存檔把關同一支判斷）；
 * 能不能改看操作者本人有沒有開 Google 2FA（沒開就唯讀並提示），有變動時存檔前要輸入本人的驗證碼。
 */
const showLoginIpWhitelist = computed(
  () => Boolean(editId) && agent.value?.login_ip_whitelist_editable === true,
);
const operatorHas2fa = ref(false);
const originalLoginIpWhitelist = ref('');
/** 逗號切開、去空白、去空值後再接回，只拿來判斷「有沒有變動」，格式驗證由後端做並指出是哪一個 */
function normalizeIpList(raw: string) {
  return raw
    .split(',')
    .map((s) => s.trim())
    .filter(Boolean)
    .join(',');
}
const loginIpWhitelistChanged = computed(
  () =>
    normalizeIpList(form.login_ip_whitelist) !==
    normalizeIpList(originalLoginIpWhitelist.value),
);

/** 佔成欄位：總代顯示；編輯非直屬下級時鎖定 */
const percentageDisabled = computed(
  () => !!editId && Number(agent.value?.parent) !== ownId.value,
);

/* ---- 上級代理選擇（新增模式，remote search） ---- */
const {
  loading: parentSearching,
  options: parentOptions,
  pin: pinParent,
  search: searchParent,
} = useAgentOptions();

/** 上級變更時帶入其無限代理模式 */
async function onParentChange(id: number | string) {
  if (!id) return;
  const detail = (await getAgentDetailApi(Number(id)).catch(() => null)) as any;
  if (detail) {
    form.agent_mode = Number(detail.agent_mode) === 1 ? 1 : 0;
  }
}

/* ---- 權限選單樹 ---- */
const menuTree = ref<AgentMenuNode[]>([]);
const checkedMenus = ref<Set<number>>(new Set());
const allChecked = ref(false);

function menuTitle(node: AgentMenuNode) {
  return tl(`menu_${node.code}`, node.name);
}

/**
 * 權限來源上色：角色帶進來的用橘色、代理自己的維持原色。
 *
 * 兩者都可自由勾選，顏色只標示「這一項存檔後會落在哪裡」——橘色由角色提供
 * （agent_permission_group_menus，跟著角色走），原色存進個人權限
 * （agent_permissions，只屬於這個代理）。
 */
function menuSourceClass(id: number) {
  return menusFromRole.value.has(id) ? 'perm-from-role' : '';
}

function flattenIds(nodes: AgentMenuNode[], out: number[] = []) {
  for (const n of nodes) {
    out.push(n.id);
    if (n.submenu) flattenIds(n.submenu, out);
  }
  return out;
}

/** 勾選邏輯：勾父 → 同步子孫；勾子 → 自動勾父/祖 */
function toggleMenu(
  node: AgentMenuNode,
  checked: boolean,
  parents: AgentMenuNode[],
) {
  const set = new Set(checkedMenus.value);
  // 角色帶進來的項目鎖定不可異動：勾父會連動子孫，這裡要略過它們，
  // 否則整棵樹一勾就把角色的權限也拖著跑
  const apply = (n: AgentMenuNode) => {
    if (!menusFromRole.value.has(n.id)) {
      if (checked) set.add(n.id);
      else set.delete(n.id);
    }
    for (const c of n.submenu ?? []) apply(c);
  };
  apply(node);
  if (checked) {
    for (const p of parents) set.add(p.id);
  }
  checkedMenus.value = set;
}

function toggleAll(checked: boolean) {
  // 工單4486：可勾選樹是操作者自己的樹，操作者看不到的既有權限（例如不能用
  // 銀行卡時的銀行卡管理）不在樹上，全選／全不選不能動到它們，原樣保留
  const visible = new Set(flattenIds(menuTree.value));
  const hidden = [...checkedMenus.value].filter((id) => !visible.has(id));
  const set = new Set<number>(hidden);
  if (checked) {
    for (const id of visible) set.add(id);
  }
  // 全選／全不選同樣不動角色帶的項目，它們恆為勾選
  for (const id of menusFromRole.value) set.add(id);
  checkedMenus.value = set;
}

/**
 * 工單4080：目前選中角色所提供的權限。
 *
 * 存檔時個人權限會扣掉這一份（見 doSave）——角色給的權限由角色持續提供、
 * 不重複寫進個人權限，這樣角色日後增刪權限才會反映到所有套用者身上。
 */
const menusFromRole = ref<Set<number>>(new Set());

/**
 * 選擇角色後，權限樹自動勾上該角色的權限。
 *
 * 切換角色時先取消前一個角色帶進來的勾選：那些權限本來就由角色提供，換掉角色
 * 就不該再有。使用者若仍想保留其中幾項，自己重新勾回來即可——手動勾的才算個人
 * 權限，存檔時會寫進 agent_permissions。
 */
function onPermissionGroupChange(groupId: '' | number) {
  const set = new Set(checkedMenus.value);

  // 先移除上一個角色帶進來的勾選，避免切換角色時殘留
  for (const id of menusFromRole.value) set.delete(id);

  const target = permissionGroups.value.find((g) => g.id === groupId);
  const incoming = new Set(target?.menus);
  for (const id of incoming) set.add(id);

  menusFromRole.value = incoming;
  checkedMenus.value = set;
}

/**
 * 重抓角色下拉。工單4080：編輯帶 agent_id，讓後端補回該代理已綁但已停用的角色
 * （否則下拉空白，存檔會把綁定靜默清掉）。工單4422：新增帶 is_sub_account＋parent，
 * 後端據此判斷目標是不是 super admin 子帳號，決定給不給 admin 角色。
 */
async function loadPermissionGroups() {
  const target = editId
    ? { agent_id: editId }
    : { is_sub_account: form.is_sub_account, parent: form.parent };
  const groupRes = await getAgentPermissionGroupDropdownApi(target).catch(
    () => null,
  );
  permissionGroups.value =
    groupRes && Number(groupRes.code) === 200 ? (groupRes.data ?? []) : [];
}

/**
 * 工單4422：新增時切換「是否為子帳號」或主帳號／上層代理，可套用的角色會變
 * （super admin 子帳號才有 admin 角色）。重抓下拉；原選角色已不在清單（或不可選）時清成「無」，
 * 並移除它帶進權限樹的勾選。載入中由 load() 自己處理，不在這裡重抓。
 */
watch(
  () => [form.is_sub_account, form.parent],
  async () => {
    if (editId || loading.value) return;
    await loadPermissionGroups();
    const stillUsable = permissionGroups.value.some(
      (g) =>
        g.id === form.agent_permission_group_id &&
        g.status === 1 &&
        g.assignable !== false,
    );
    if (!stillUsable && form.agent_permission_group_id !== '') {
      form.agent_permission_group_id = '';
      onPermissionGroupChange('');
    }
  },
);

/* ---- 載入 ---- */
async function load() {
  loading.value = true;
  try {
    const [settings] = await Promise.all([
      getCustomizedSettingApi().catch(() => []),
      getCountryDropdownApi()
        .then((d) => {
          countries.value = d ?? [];
        })
        .catch(() => {}),
    ]);
    const map = new Map(
      (settings ?? []).map((s: any) => [Number(s.setting_key), s.value]),
    );
    enableEditRealName.value = Number(map.get(95_202) ?? 0) === 1;

    // 編輯者自己的選單樹（左側全集）
    menuTree.value = (await getAgentMenuTreeApi(ownId.value)) ?? [];

    // 工單4498：操作者本人有沒有開 Google 2FA，決定白名單欄位可不可以改
    const operator = (await getAgentViewApi(ownId.value).catch(
      () => null,
    )) as any;
    operatorHas2fa.value = Number(operator?.has_2fa) === 1;

    // 角色下拉：編輯模式此時就抓；新增模式要等預設上級帶入後才知道目標帳號，見下方
    if (editId) await loadPermissionGroups();

    if (editId) {
      const a = (await getAgentViewApi(editId)) as any;
      agent.value = a;
      // 分頁標題補上帳號以區分多個編輯分頁。只能傳字串，
      // 傳 ComputedRef 會讓分頁存進 sessionStorage 時序列化失敗
      if (a?.username) setTabTitle(`${tl('edit_agent')}(${a.username})`);
      form.nickname = a?.nickname ?? '';
      form.real_name = a?.real_name ?? '';
      form.email = a?.email ?? '';
      form.country_id = a?.country_id ?? '';
      form.city = a?.city ?? '';
      form.address = a?.address ?? '';
      form.mobile = a?.mobile ?? '';
      form.percentage = a?.percentage ?? '';
      form.status = Number(a?.status ?? 0);
      form.realname_validate = Number(a?.realname_validate) === 1 ? 1 : 0;
      form.email_validate = Number(a?.email_validate) === 1 ? 1 : 0;
      form.mobile_validate =
        Number(a?.mobile_validate) === 1 ||
        a?.mobile_validate === null ||
        a?.mobile_validate === undefined
          ? 1
          : 0;
      form.frontend_register = Number(a?.frontend_register) === 0 ? 0 : 1;
      form.agent_mode = Number(a?.agent_mode) === 0 ? 0 : 1;
      form.has_pc = Number(a?.has_pc) === 1 ? 1 : 0;
      form.has_mobile = Number(a?.has_mobile) === 1 ? 1 : 0;
      form.has_apk = Number(a?.has_apk) === 1 ? 1 : 0;
      form.has_ipa = Number(a?.has_ipa) === 1 ? 1 : 0;
      form.has_chat = Number(a?.has_chat) === 1 ? 1 : 0;
      form.customized_setting_group_id = a?.customized_setting_group_id ?? '';
      form.cash_flow_group_id = a?.cash_flow_group_id ?? '';
      form.sms_gateway_group_id = a?.sms_gateway_group_id ?? '';
      form.email_gateway_group_id = a?.email_gateway_group_id ?? '';
      form.chat_group_id = a?.chat_group_id ?? '';
      form.vendor_customize_group_id = a?.vendor_customize_group_id ?? '';
      form.member_field_setting_group_id =
        a?.member_field_setting_group_id ?? '';
      // 工單4498：登入 IP 白名單既有值，存檔時用來判斷有沒有變動（有變動才要驗證碼）
      form.login_ip_whitelist = a?.login_ip_whitelist ?? '';
      originalLoginIpWhitelist.value = form.login_ip_whitelist;
      // 工單4080：帶入既有角色。不呼叫 onPermissionGroupChange——下面勾選的已是該
      // 代理實際生效的權限（含角色權限），再套一次是多餘的；但仍要記下這個角色提供
      // 哪些權限，否則存檔時扣不掉，角色權限會被誤寫進個人權限。
      form.agent_permission_group_id = a?.agent_permission_group_id ?? '';
      const currentGroup = permissionGroups.value.find(
        (g) => g.id === form.agent_permission_group_id,
      );
      menusFromRole.value = new Set(currentGroup?.menus);

      // 目標代理已擁有的選單 → 勾選
      const targetTree = await getAgentMenuTreeApi(editId).catch(() => []);
      checkedMenus.value = new Set(flattenIds(targetTree ?? []));

      // 組別下拉（依代理的組織）
      if (canAgentGroup) {
        groups.value =
          (await getAgentGroupDropdownApi(a?.organization_id).catch(
            () => [],
          )) ?? [];
      }
    } else {
      // 新增模式：預設上級 = host（總代用自己）、預設權限、預設手機驗證承襲本人
      const info = userStore.userInfo as any;
      const hostId = Number(info?.host_id ?? 0);
      const baseId = hostId === 0 ? ownId.value : hostId;
      const base = (await getAgentViewApi(baseId).catch(() => null)) as any;
      if (base) {
        pinParent([{ id: base.id, username: base.username }]);
        form.parent = base.id;
      }
      const self = (await getAgentViewApi(ownId.value).catch(
        () => null,
      )) as any;
      form.mobile_validate =
        Number(self?.mobile_validate) === 1 ||
        self?.mobile_validate === null ||
        self?.mobile_validate === undefined
          ? 1
          : 0;
      await loadPermissionGroups();
      // 工單4080：新增營運商不再預設勾選任何權限。原本前端寫死一份
      // DEFAULT_PERMISSIONS 清單無條件勾上，改一次要發版、營運也無法自行調整；
      // 現在改為由使用者選角色（角色的權限會自動勾上並鎖定）或自行逐項勾選。
      // 工單4498：新建帳號不能選 admin 角色，預選時跳過不可選的（admin 子帳號會退到共用預設角色）
      const defaultGroup = permissionGroups.value.find(
        (g) =>
          g.default_setting === 1 && g.status === 1 && g.assignable !== false,
      );

      if (defaultGroup) {
        form.agent_permission_group_id = defaultGroup.id;
        onPermissionGroupChange(defaultGroup.id);
      }

      onParentChange(form.parent);
    }
  } finally {
    loading.value = false;
  }
}

/* ---- 儲存 ---- */
const confirmVisible = ref(false);
const originalPassword = [REDACTED]'');

function handleSaveClick() {
  // 修改自己的資料需先驗證密碼
  if (editId && isSelf.value) {
    originalPassword.value = '';
    confirmVisible.value = true;
  } else {
    doSave();
  }
}

async function confirmAndSave() {
  const res = await confirmAgentPasswordApi(originalPassword.value);
  if (Number(res.code) === 1000) {
    confirmVisible.value = false;
    doSave();
  } else {
    ElMessage.error(Number(res.code) === 2002 ? String(res.data) : res.message);
  }
}

async function doSave() {
  // 工單4498：白名單內容有變動（含清空）才要操作者本人的 Google 2FA 驗證碼；取消就不存。
  // 驗證碼由後端用操作者的 secret 驗，錯了整筆不存（訊息由後端回），可重新輸入、不鎖定
  let otp = '';
  if (showLoginIpWhitelist.value && loginIpWhitelistChanged.value) {
    try {
      const { value } = await ElMessageBox.prompt(
        tl('please_input_otp'),
        tl('login_ip_whitelist_otp_title'),
        {
          cancelButtonText: tl('cancel'),
          confirmButtonText: tl('confirm'),
        },
      );
      otp = value ?? '';
    } catch {
      return;
    }
  }

  saving.value = true;
  try {
    const data: Record<string, unknown> = {
      address: form.address,
      agent_mode: form.agent_mode,
      // 工單4080：'' 代表不選擇角色，後端存 NULL（僅吃個人權限）
      agent_permission_group_id: form.agent_permission_group_id,
      cash_flow_group_id: form.cash_flow_group_id,
      chat_group_id: form.chat_group_id,
      city: form.city,
      country_id: form.country_id,
      customized_setting_group_id: form.customized_setting_group_id,
      email: form.email,
      email_validate: form.email_validate,
      frontend_register: form.frontend_register,
      has_apk: form.has_apk,
      has_chat: form.has_chat,
      has_ipa: form.has_ipa,
      has_mobile: form.has_mobile,
      has_pc: form.has_pc,
      is_sub_account: form.is_sub_account,
      member_field_setting_group_id: form.member_field_setting_group_id,
      mobile: form.mobile,
      mobile_validate: form.mobile_validate,
      nickname: form.nickname,
      percentage: form.percentage,
      real_name: form.real_name,
      realname_validate: form.realname_validate,
      email_gateway_group_id: form.email_gateway_group_id,
      sms_gateway_group_id: form.sms_gateway_group_id,
      status: form.status,
      vendor_customize_group_id: form.vendor_customize_group_id,
    };
    // 工單4506：編輯時不再送密碼（改走代理列表操作選單／右上角個人選單）；建立才送
    if (!editId) {
      data.password = [REDACTED];
      data.password_confirmation = form.password_confirmation;
    }

    // 工單4498：只有顯示白名單區塊的帳號才送這個欄位（後端對其他帳號會忽略；不送＝不變動）
    if (showLoginIpWhitelist.value) {
      data.login_ip_whitelist = form.login_ip_whitelist;
      if (loginIpWhitelistChanged.value) data.otp = otp;
    }

    let agentId: number | string;
    if (editId) {
      data.id = editId;
      const res = (await editAgentApi(data as any)) as any;
      if (Number(res.code) !== 1000) {
        ElMessage.error(
          Number(res.code) === 2002 ? String(res.data) : res.message,
        );
        return;
      }
      agentId = editId;
    } else {
      data.username = form.username;
      if (form.parent !== '') data.parent = form.parent;
      const res = await addAgentApi(data);
      if (Number(res.code) !== 1000 || !res.data) {
        // add 失敗時錯誤訊息在 data
        ElMessage.error(String(res.data ?? res.message));
        return;
      }
      agentId = res.data as number;
    }

    // 設定選單權限：只寫「角色沒給的那些」，角色給的由角色持續提供，
    // 不重複寫進個人權限（生效範圍＝個人 ∪ 角色，後端 getEffectiveMenuIds 合併）
    const menus: Record<string, string> = {};
    for (const id of checkedMenus.value) {
      if (menusFromRole.value.has(id)) continue;
      menus[String(id)] = '';
    }
    const permRes = await setAgentPermissionApi({ agent_id: agentId, menus });
    if (Number(permRes.code) === 200) {
      ElMessage.success(tl('success'));
      await (editId ? leaveTo() : leaveTo('/agent/index'));
    } else {
      ElMessage.error(
        Number(permRes.code) === 2002 ? String(permRes.data) : permRes.message,
      );
    }
  } finally {
    saving.value = false;
  }
}

onMounted(load);
</script>

<template>
  <Page
    :title="`${tl('agnet_management')} > ${editId ? tl('edit_agent') : tl('menu_add_agent')}`"
    auto-content-height
    footer-class="border-t border-border"
  >
    <ElCard v-loading="loading" shadow="never">
      <!-- 固定寬度：auto 靠 JS 量測，唯讀（純文字）欄位量不準會整列歪掉 -->
      <ElForm label-width="140px" @submit.prevent="handleSaveClick">
        <div class="grid grid-cols-1 gap-x-8 lg:grid-cols-3">
          <!-- 左：基本資料（佔兩欄） -->
          <div class="lg:col-span-2">
            <div class="grid grid-cols-1 gap-x-6 md:grid-cols-2">
              <ElFormItem :label="tl('is_sub_account')">
                <span v-if="editId">
                  {{
                    Number(agent?.host_id) > 0
                      ? tl('sub_account')
                      : tl('main_account')
                  }}
                </span>
                <ElRadioGroup v-else v-model="form.is_sub_account">
                  <ElRadio :value="1">{{ tl('option_yes') }}</ElRadio>
                  <ElRadio :value="0">{{ tl('option_no') }}</ElRadio>
                </ElRadioGroup>
              </ElFormItem>
              <ElFormItem
                :label="
                  form.is_sub_account === 1
                    ? tl('main_account')
                    : tl('agent_previous')
                "
              >
                <span v-if="editId">{{ agent?.parent_username }}</span>
                <ElSelect
                  v-else
                  v-model="form.parent"
                  :debounce="500"
                  :loading="parentSearching"
                  :remote-method="searchParent"
                  class="w-full"
                  filterable
                  remote
                  @change="onParentChange"
                >
                  <ElOption
                    v-for="o in parentOptions"
                    :key="o.id"
                    :label="o.username"
                    :value="o.id"
                  />
                </ElSelect>
              </ElFormItem>
              <ElFormItem :label="tl('username')" :required="!editId">
                <span v-if="editId">{{ agent?.username }}</span>
                <ElInput
                  v-else
                  v-model="form.username"
                  autocomplete="off"
                  placeholder="Account"
                />
              </ElFormItem>
              <ElFormItem :label="tl('nickname')">
                <ElInput v-model="form.nickname" placeholder="Enter nickname" />
              </ElFormItem>
              <ElFormItem :label="tl('real_name')" class="md:col-span-2">
                <ElInput
                  v-model="form.real_name"
                  :readonly="!enableEditRealName"
                  placeholder="Enter real name"
                />
              </ElFormItem>
              <ElFormItem :label="tl('email')" class="md:col-span-2">
                <ElInput
                  v-model="form.email"
                  autocomplete="off"
                  placeholder="Enter email"
                  type="email"
                />
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
              </template>
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
              <ElFormItem :label="tl('tel')">
                <ElInput v-model="form.mobile" placeholder="Mobile" />
              </ElFormItem>
              <ElFormItem v-if="isMainBlock" :label="tl('percentage')">
                <ElInput
                  v-model="form.percentage"
                  :disabled="percentageDisabled"
                  placeholder="18"
                />
              </ElFormItem>
              <ElFormItem :label="tl('status')">
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
                  <ElRadio :value="0">{{ tl('no_pass') }}</ElRadio>
                </ElRadioGroup>
              </ElFormItem>
              <template v-if="isMainBlock">
                <ElFormItem :label="tl('email_certification')">
                  <ElRadioGroup v-model="form.email_validate">
                    <ElRadio :value="1">{{ tl('necessary') }}</ElRadio>
                    <ElRadio :value="0">{{ tl('unnecessary') }}</ElRadio>
                  </ElRadioGroup>
                </ElFormItem>
                <ElFormItem :label="tl('mobile_authentication')">
                  <ElRadioGroup
                    v-model="form.mobile_validate"
                    :disabled="!canEditMobileFlag"
                  >
                    <ElRadio :value="1">{{ tl('necessary') }}</ElRadio>
                    <ElRadio :value="0">{{ tl('unnecessary') }}</ElRadio>
                  </ElRadioGroup>
                </ElFormItem>
                <ElFormItem :label="tl('member_allow_register')">
                  <ElRadioGroup v-model="form.frontend_register">
                    <ElRadio :value="1">{{ tl('open') }}</ElRadio>
                    <ElRadio :value="0">{{ tl('notopen') }}</ElRadio>
                  </ElRadioGroup>
                </ElFormItem>
                <ElFormItem :label="tl('agent_mode_unlimit')">
                  <ElRadioGroup
                    v-model="form.agent_mode"
                    :disabled="!canEditUnlimited"
                  >
                    <ElRadio :value="1">{{ tl('open') }}</ElRadio>
                    <ElRadio :value="0">{{ tl('notopen') }}</ElRadio>
                  </ElRadioGroup>
                </ElFormItem>
              </template>

              <!-- 工單4080：選擇角色後，右側權限樹自動勾上該角色的權限。
                   位置依工單圖放在左欄「無限代」之後 -->
              <ElFormItem :label="tl('choose_role_permission')">
                <ElSelect
                  v-model="form.agent_permission_group_id"
                  class="w-full"
                  filterable
                  @change="onPermissionGroupChange($event)"
                >
                  <ElOption :label="tl('none')" value="" />
                  <!-- 已停用的角色只為了顯示既有綁定而列出，不開放改選成它；
                       工單4498：admin 角色在目標帳號未開 2FA 或白名單為空時反灰並標出缺哪一項 -->
                  <ElOption
                    v-for="g in permissionGroups"
                    :key="g.id"
                    :disabled="g.status === 0 || g.assignable === false"
                    :label="
                      g.status === 0
                        ? `${g.name}（${tl('disable')}）`
                        : g.assignable === false && g.unassignable_reason
                          ? `${g.name}（${g.unassignable_reason}）`
                          : g.name
                    "
                    :value="g.id"
                  />
                </ElSelect>
              </ElFormItem>
            </div>

            <!-- 高級設置（編輯模式 + 權限） -->
            <template v-if="isMainBlock && canAdvanceSetting && editId">
              <h3
                class="mb-3 mt-6 border-b pb-2 text-lg font-bold text-red-400"
              >
                {{ tl('menu_agent_advance_setting') }}
              </h3>
              <div class="grid grid-cols-1 gap-x-6 md:grid-cols-2">
                <ElFormItem :label="tl('EnableSetting_HasPC')">
                  <ElRadioGroup v-model="form.has_pc">
                    <ElRadio :value="1">{{ tl('open') }}</ElRadio>
                    <ElRadio :value="0">{{ tl('notopen') }}</ElRadio>
                  </ElRadioGroup>
                </ElFormItem>
                <ElFormItem :label="tl('EnableSetting_HasMobile')">
                  <ElRadioGroup v-model="form.has_mobile">
                    <ElRadio :value="1">{{ tl('open') }}</ElRadio>
                    <ElRadio :value="0">{{ tl('notopen') }}</ElRadio>
                  </ElRadioGroup>
                </ElFormItem>
                <ElFormItem :label="tl('EnableSetting_HasApk')">
                  <ElRadioGroup v-model="form.has_apk">
                    <ElRadio :value="1">{{ tl('open') }}</ElRadio>
                    <ElRadio :value="0">{{ tl('notopen') }}</ElRadio>
                  </ElRadioGroup>
                </ElFormItem>
                <ElFormItem :label="tl('EnableSetting_HasIpa')">
                  <ElRadioGroup v-model="form.has_ipa">
                    <ElRadio :value="1">{{ tl('open') }}</ElRadio>
                    <ElRadio :value="0">{{ tl('notopen') }}</ElRadio>
                  </ElRadioGroup>
                </ElFormItem>
                <ElFormItem :label="tl('EnableSetting_HasChat')">
                  <ElRadioGroup v-model="form.has_chat">
                    <ElRadio :value="1">{{ tl('open') }}</ElRadio>
                    <ElRadio :value="0">{{ tl('notopen') }}</ElRadio>
                  </ElRadioGroup>
                </ElFormItem>
              </div>
            </template>

            <!-- 組別（編輯模式 + 權限） -->
            <template v-if="isMainBlock && canAgentGroup && editId">
              <h3
                class="mb-3 mt-6 border-b pb-2 text-lg font-bold text-red-400"
              >
                {{ tl('menu_agent_group') }}
              </h3>
              <div class="grid grid-cols-1 gap-x-6 md:grid-cols-2">
                <ElFormItem
                  v-for="(field, t) in {
                    1: 'customized_setting_group_id',
                    2: 'cash_flow_group_id',
                    3: 'sms_gateway_group_id',
                    4: 'chat_group_id',
                    5: 'vendor_customize_group_id',
                    6: 'member_field_setting_group_id',
                    7: 'email_gateway_group_id',
                  }"
                  :key="t"
                  :label="tl(`agent_group_type_${t}`)"
                >
                  <ElSelect
                    v-model="(form as any)[field]"
                    class="w-full"
                    filterable
                  >
                    <ElOption
                      v-if="Number(t) === 7"
                      :label="tl('none')"
                      value=""
                    />
                    <ElOption
                      v-for="g in groupsByType(Number(t))"
                      :key="g.id"
                      :label="g.name"
                      :value="g.id"
                    />
                  </ElSelect>
                </ElFormItem>
              </div>
            </template>

            <!-- 工單4498：登入 IP 白名單（代理組別設置之後，獨立區塊）。
                 操作者本人沒開 Google 2FA 時唯讀並提示；有變動時存檔前要輸入本人的驗證碼 -->
            <template v-if="showLoginIpWhitelist">
              <h3
                class="mb-3 mt-6 border-b pb-2 text-lg font-bold text-red-400"
              >
                {{ tl('login_ip_whitelist') }}
              </h3>
              <ElFormItem :label="tl('allowed_login_ip')">
                <ElInput
                  v-model="form.login_ip_whitelist"
                  :disabled="!operatorHas2fa"
                  :placeholder="tl('login_ip_whitelist_hint')"
                  :rows="2"
                  type="textarea"
                />
                <div class="mt-1 text-xs text-gray-400">
                  {{ tl('login_ip_whitelist_hint') }}
                </div>
                <div v-if="!operatorHas2fa" class="mt-1 text-xs text-red-400">
                  {{ tl('login_ip_whitelist_need_2fa') }}
                </div>
              </ElFormItem>
            </template>
          </div>

          <!-- 右：權限設定 -->
          <div>
            <h3 class="mb-2 border-b pb-2 text-base font-bold">
              {{ tl('permission_setting') }}
            </h3>

            <div class="mb-1 flex flex-wrap items-center justify-between gap-2">
              <ElCheckbox
                v-model="allChecked"
                @change="toggleAll($event as boolean)"
              >
                {{ tl('select_all') }}
              </ElCheckbox>
              <!-- 圖例：橘色＝角色提供（鎖定不可改），藍色＝代理自己的 -->
              <div class="flex items-center gap-3 text-xs">
                <span class="perm-legend-agent">
                  ■ {{ tl('permission_from_agent') }}
                </span>
                <span class="perm-legend-role">
                  ■ {{ tl('permission_from_role') }}
                </span>
              </div>
            </div>
            <div class="max-h-[700px] overflow-y-auto">
              <div v-for="m1 in menuTree" :key="m1.id">
                <ElCheckbox
                  :class="menuSourceClass(m1.id)"
                  :disabled="menusFromRole.has(m1.id)"
                  :model-value="checkedMenus.has(m1.id)"
                  @change="toggleMenu(m1, $event as boolean, [])"
                >
                  {{ menuTitle(m1) }}
                </ElCheckbox>
                <div v-for="m2 in m1.submenu ?? []" :key="m2.id" class="pl-4">
                  <ElCheckbox
                    :class="menuSourceClass(m2.id)"
                    :disabled="menusFromRole.has(m2.id)"
                    :model-value="checkedMenus.has(m2.id)"
                    @change="toggleMenu(m2, $event as boolean, [m1])"
                  >
                    {{ menuTitle(m2) }}
                  </ElCheckbox>
                  <div v-for="m3 in m2.submenu ?? []" :key="m3.id" class="pl-8">
                    <ElCheckbox
                      :class="menuSourceClass(m3.id)"
                      :disabled="menusFromRole.has(m3.id)"
                      :model-value="checkedMenus.has(m3.id)"
                      @change="toggleMenu(m3, $event as boolean, [m1, m2])"
                    >
                      {{ menuTitle(m3) }}
                    </ElCheckbox>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </ElForm>
    </ElCard>

    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <ElButton @click="leaveTo()">{{ tl('btn_back') }}</ElButton>
        <ElButton :loading="saving" type="primary" @click="handleSaveClick">
          {{ tl('btn_update') }}
        </ElButton>
      </div>
    </template>

    <!-- 修改自己資料需驗證密碼 -->
    <ElDialog
      v-model="confirmVisible"
      :title="tl('original_password')"
      width="480"
      align-center
    >
      <ElInput
        v-model="originalPassword"
        :placeholder="tl('original_password')"
        show-password
        type="password"
        @keyup.enter="confirmAndSave"
      />
      <template #footer>
        <ElButton @click="confirmVisible = false">{{ tl('cancel') }}</ElButton>
        <ElButton type="primary" @click="confirmAndSave">
          {{ tl('confirm') }}
        </ElButton>
      </template>
    </ElDialog>
  </Page>
</template>

<style scoped>
/*
 * 權限來源上色：角色帶進來的標橘色，代理自己的維持主題藍（＝勾選態原色）。
 *
 * Element Plus 對 .el-checkbox__label 有自己的 color（勾選後換主題色、disabled
 * 換灰色），直接在根元素掛 class 蓋不到文字，故比照專案既有寫法用 :deep() 指到
 * label，並補 !important 蓋過那兩種狀態色——角色的項目一律 disabled，不加就會
 * 被 disabled 的灰蓋掉，看不出是「角色鎖定」還是「自己沒勾」。
 */
.perm-from-role:deep(.el-checkbox__label) {
  color: var(--el-color-warning) !important;
}

/* 勾選框本體也維持橘色，與文字一致（否則框是灰的、字是橘的） */
.perm-from-role:deep(.el-checkbox__inner) {
  background-color: var(--el-color-warning) !important;
  border-color: var(--el-color-warning) !important;
}

.perm-from-role:deep(.el-checkbox__inner::after) {
  border-color: #fff !important;
}

/* 鎖定的項目游標改成 not-allowed，讓「點不動」是預期行為而非壞掉 */
.perm-from-role:deep(.el-checkbox__input.is-disabled) {
  cursor: not-allowed;
}

.perm-legend-agent {
  color: var(--el-color-primary);
}

.perm-legend-role {
  color: var(--el-color-warning);
}
</style>
