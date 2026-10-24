<script lang="ts" setup>
import type { AgentMenuNode, AgentPermissionGroupOwnerOption } from '#/api';

import { computed, ref } from 'vue';
import { useRoute } from 'vue-router';

import { Page } from '@vben/common-ui';
import { useUserStore } from '@vben/stores';

import {
  ElButton,
  ElCard,
  ElCheckbox,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElSwitch,
} from 'element-plus';

import {
  createAgentPermissionGroupApi,
  getAgentMenuTreeApi,
  getAgentPermissionGroupApi,
  getAgentPermissionGroupOwnerDropdownApi,
  updateAgentPermissionGroupApi,
} from '#/api';
import { useLeavePage } from '#/composables/use-leave-page';
import { tl } from '#/locales';

defineOptions({ name: 'PermissionGroupEdit' });

const route = useRoute();
const userStore = useUserStore();
const leaveTo = useLeavePage();

const editId = computed(() => route.query.id as string | undefined);
const isEdit = computed(() => Boolean(editId.value));

const loading = ref(false);
const saving = ref(false);
/** 工單4543：總代開下線建的角色只能檢視，後端 can_edit=false 時整頁唯讀、不給儲存 */
const readonly = ref(false);

/**
 * 工單4543：所屬代理（持有者）一律不可選——誰建的就記誰，admin 建的記 admin 持有（owner 空）。
 * owners 只用來顯示名稱：super admin 拿到 admin預設角色＋全部總代，其餘只有自己的主帳號。
 */
const owners = ref<AgentPermissionGroupOwnerOption[]>([]);
/** 工單4543：只有總代（含子帳號）能把角色設為預設；一般代理與 admin 都不行，開關鎖住並說明 */
const canSetDefault = ref(false);

/** 編輯時後端回的持有者帳號；總代檢視下線建的角色時，owners 裡沒有那個下線，要靠它顯示 */
const ownerUsername = ref<null | string>(null);

/**
 * 不可選時顯示的持有者名稱（工單4543）：owner 為空＝「admin」（admin 持有）、1＝「admin（子帳號專用）」、
 * 其他＝持有者帳號（下拉有的取下拉，沒有的取後端回的 owner_username）。
 */
const ownerDisplayName = computed(() => {
  const id = form.value.owner_agent_id;
  if (id === '') return tl('role_owner_all_lines');
  if (id === 1) return tl('role_owner_admin_sub_only');
  const hit = owners.value.find((o) => o.id === id);
  return hit?.username ?? ownerUsername.value ?? String(id);
});

/**
 * 是否為 admin 持有的角色（owner 為空，工單4543 前叫共用角色）。
 *
 * admin 持有的預設是 admin 建帳號時的兜底，故任何人都不得異動其預設身分。
 * 後端同樣會擋，這裡先鎖住開關避免白跑一趟。
 */
const isSharedRole = computed(() => form.value.owner_agent_id === '');

const form = ref({
  name: '',
  /** 所屬代理（持有者）。'' = admin 持有；非 super admin 由後端固定寫自己（工單4543） */
  owner_agent_id: '' as number | string,
  remark: '',
  status: true,
  /**
   * 是否為該線的預設角色（target/op_tickets#3168）。
   *
   * 預設角色在「同一條線內」唯一——設成預設時後端會自動把同 owner 的舊預設
   * 降為非預設，不必也不該在前端先去清它。
   */
  default_setting: false,
});

/* ---- 權限選單樹（結構比照 agent/edit.vue，供角色勾選）---- */
const menuTree = ref<AgentMenuNode[]>([]);
const checkedMenus = ref<Set<number>>(new Set());
const allChecked = ref(false);

function menuTitle(node: AgentMenuNode) {
  return tl(`menu_${node.code}`, node.name);
}

function flattenIds(nodes: AgentMenuNode[], out: number[] = []) {
  for (const n of nodes) {
    out.push(n.id);
    if (n.submenu) flattenIds(n.submenu, out);
  }
  return out;
}

/** 勾選邏輯：勾父 → 同步子孫；勾子 → 自動勾父/祖（與 agent/edit.vue 一致） */
function toggleMenu(
  node: AgentMenuNode,
  checked: boolean,
  parents: AgentMenuNode[],
) {
  const set = new Set(checkedMenus.value);
  const apply = (n: AgentMenuNode) => {
    if (checked) set.add(n.id);
    else set.delete(n.id);
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
  checkedMenus.value = set;
}

/** 一個頂層選單實際會渲染幾列（含所有子孫） */
function countRows(node: AgentMenuNode): number {
  let n = 1;
  for (const child of node.submenu ?? []) n += countRows(child);
  return n;
}

/**
 * 權限清單分兩欄呈現（工單要求）。
 *
 * 以「展開後的總列數」而非「頂層選單數量」切半：各頂層底下的子選單數量差距很大
 * （系統管理十幾項、打卡活動只有兩項），用數量切會讓左右高度嚴重不均。
 * 整個頂層不拆開跨欄，故取「累加後最接近總數一半」的切點。
 */
const menuColumns = computed<AgentMenuNode[][]>(() => {
  const tree = menuTree.value;
  if (tree.length === 0) return [[], []];
  if (tree.length === 1) return [tree, []];

  const rows = tree.map((node) => countRows(node));
  const target = rows.reduce((sum, n) => sum + n, 0) / 2;

  let acc = 0;
  let splitAt = 1;
  let best = Number.POSITIVE_INFINITY;
  for (const [i, row] of rows.entries()) {
    acc += row;
    const diff = Math.abs(acc - target);
    if (diff < best) {
      best = diff;
      splitAt = i + 1;
    }
  }

  // 兩欄至少各留一項，避免全擠同一欄
  splitAt = Math.min(Math.max(splitAt, 1), tree.length - 1);

  return [tree.slice(0, splitAt), tree.slice(splitAt)];
});

async function load() {
  loading.value = true;
  try {
    // 可勾選的範圍＝操作者自己的選單樹（與 agent/edit.vue 相同語意）
    const ownId = Number((userStore.userInfo as any)?.id ?? 0);
    menuTree.value = (await getAgentMenuTreeApi(ownId)) ?? [];

    // 工單4543：持有者由建立者決定，這裡只取名稱來顯示
    const ownerRes = await getAgentPermissionGroupOwnerDropdownApi().catch(
      () => null,
    );
    const ownerData =
      ownerRes && Number(ownerRes.code) === 200 ? ownerRes.data : undefined;
    owners.value = ownerData?.owners ?? [];
    canSetDefault.value = ownerData?.can_set_default ?? false;

    // 新增時：代理只會拿到自己的主帳號，直接帶入（後端也固定寫自己）；
    // super admin 不帶，維持空＝admin 持有（它的清單第一項是 admin預設角色，不能拿來帶）
    if (!isEdit.value) {
      const only =
        owners.value.length === 1 && !owners.value[0]?.is_admin
          ? owners.value[0]
          : undefined;
      if (only) {
        form.value.owner_agent_id = only.id;
      }
      return;
    }

    const res = await getAgentPermissionGroupApi(editId.value as string);
    if (Number(res.code) !== 200 || !res.data) {
      ElMessage.error(res.message ?? tl('operation_failed'));
      return;
    }

    readonly.value = res.data.can_edit === false;
    ownerUsername.value = res.data.owner_username ?? null;
    form.value = {
      name: res.data.name,
      owner_agent_id: res.data.owner_agent_id ?? '',
      remark: res.data.remark ?? '',
      status: res.data.status === 1,
      default_setting: res.data.default_setting === 1,
    };
    checkedMenus.value = new Set(res.data.menus);
  } finally {
    loading.value = false;
  }
}

async function handleSave() {
  if (!form.value.name.trim()) {
    ElMessage.warning(tl('role_name') + tl('cannot_be_empty'));
    return;
  }

  saving.value = true;
  try {
    const payload = {
      menus: [...checkedMenus.value],
      name: form.value.name.trim(),
      owner_agent_id: form.value.owner_agent_id,
      remark: form.value.remark.trim(),
      // 預設角色恆為啟用：後端也會強制，這裡一併送出讓畫面與實際一致
      status: form.value.default_setting || form.value.status ? 1 : 0,
      default_setting: form.value.default_setting ? 1 : 0,
    };

    const res = await (isEdit.value
      ? updateAgentPermissionGroupApi({
          ...payload,
          id: editId.value as string,
        })
      : createAgentPermissionGroupApi(payload));

    if (Number(res.code) === 200) {
      ElMessage.success(tl('operation_success'));
      // 用 useLeavePage 關掉本頁再返回，避免 keep-alive 殘留舊表單
      leaveTo('/permission_group/index');
    } else {
      ElMessage.error(res.message ?? tl('operation_failed'));
    }
  } finally {
    saving.value = false;
  }
}

load();
</script>

<template>
  <Page
    :title="`${tl('menu_agent_management')} > ${readonly ? tl('view') : isEdit ? tl('edit') : tl('add')}${tl('role_name')}`"
    auto-content-height
    footer-class="border-t border-border"
  >
    <ElCard v-loading="loading" shadow="never">
      <ElForm label-width="auto" @submit.prevent="handleSave">
        <div class="grid grid-cols-1 gap-x-6 md:grid-cols-2">
          <ElFormItem :label="tl('role_name')" required>
            <ElInput
              v-model="form.name"
              clearable
              :disabled="readonly"
              maxlength="50"
              :placeholder="tl('please_enter')"
            />
          </ElFormItem>
          <!-- 工單4543：角色持有者，誰持有誰才能套給下級、也只有持有者能改。
               誰建的就記誰（admin 建的記 admin 持有），一律不可選 -->
          <ElFormItem :label="tl('role_owner_agent')">
            <div class="w-full">
              <span class="font-medium">
                {{ ownerDisplayName }}
              </span>
              <div class="text-muted-foreground mt-1 text-xs">
                {{ tl('role_owner_agent_hint') }}
              </div>
            </div>
          </ElFormItem>
          <ElFormItem :label="tl('memo')">
            <ElInput
              v-model="form.remark"
              clearable
              :disabled="readonly"
              maxlength="191"
              :placeholder="tl('please_enter')"
            />
          </ElFormItem>
          <ElFormItem :label="tl('set_as_default_group')">
            <!--
              持有者的預設角色（target/op_tickets#3168）：新增營運商未選角色時自動套用，
              同一持有者內唯一。admin 持有（owner 為空）的預設是 admin 建帳號時的兜底，鎖住不給改；
              該持有者已有預設時後端會擋下並提示，要換得先去把舊的那個關掉。
            -->
            <ElSwitch
              v-model="form.default_setting"
              :disabled="!canSetDefault || isSharedRole || readonly"
            />
            <span
              v-if="!canSetDefault && !readonly"
              class="text-muted-foreground ml-2 text-xs"
            >
              {{ tl('default_setting_top_agent_only') }}
            </span>
          </ElFormItem>
          <ElFormItem :label="tl('status')">
            <!-- 預設角色恆為啟用：停掉會讓該線新增營運商沒有預設權限可帶 -->
            <ElSwitch
              v-model="form.status"
              :disabled="form.default_setting || readonly"
            />
          </ElFormItem>
        </div>

        <div class="mt-2 border-t pt-4">
          <div class="mb-2 flex items-center gap-4">
            <h3 class="text-base font-medium">
              {{ tl('permission_setting') }}
            </h3>
            <ElCheckbox
              v-model="allChecked"
              :disabled="readonly"
              @change="toggleAll($event as boolean)"
            >
              {{ tl('select_all') }}
            </ElCheckbox>
          </div>

          <!-- 工單4543：可勾範圍＝操作者自己的權限；角色既有、但操作者看不到的權限存檔時保留（後端同樣會擋超出的） -->
          <p v-if="!readonly" class="text-muted-foreground mb-2 text-xs">
            {{ tl('role_permission_scope_hint') }}
          </p>
          <!-- 權限分兩欄展示（工單要求） -->
          <div
            class="grid max-h-[700px] grid-cols-1 gap-x-8 overflow-y-auto md:grid-cols-2"
          >
            <div v-for="(column, ci) in menuColumns" :key="ci">
              <div v-for="m1 in column" :key="m1.id">
                <ElCheckbox
                  :disabled="readonly"
                  :model-value="checkedMenus.has(m1.id)"
                  @change="toggleMenu(m1, $event as boolean, [])"
                >
                  {{ menuTitle(m1) }}
                </ElCheckbox>
                <div v-for="m2 in m1.submenu ?? []" :key="m2.id" class="pl-4">
                  <ElCheckbox
                    :disabled="readonly"
                    :model-value="checkedMenus.has(m2.id)"
                    @change="toggleMenu(m2, $event as boolean, [m1])"
                  >
                    {{ menuTitle(m2) }}
                  </ElCheckbox>
                  <div v-for="m3 in m2.submenu ?? []" :key="m3.id" class="pl-8">
                    <ElCheckbox
                      :disabled="readonly"
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
        <ElButton @click="leaveTo('/permission_group/index')">
          {{ tl('btn_back') }}
        </ElButton>
        <ElButton
          v-if="!readonly"
          :loading="saving"
          type="primary"
          @click="handleSave"
        >
          {{ tl('btn_update') }}
        </ElButton>
      </div>
    </template>
  </Page>
</template>
