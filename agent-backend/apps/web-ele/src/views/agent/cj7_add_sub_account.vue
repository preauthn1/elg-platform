<script lang="ts" setup>
import type { AgentMenuNode } from '#/api';

import { computed, nextTick, onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';
import { useUserStore } from '@vben/stores';

import {
  ElAlert,
  ElButton,
  ElCard,
  ElCheckbox,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElTree,
} from 'element-plus';

import {
  cj7AddSubAccountApi,
  getAgentChildAccountsApi,
  getAgentMenuTreeApi,
} from '#/api';
import { useLeavePage } from '#/composables/use-leave-page';
import { tl } from '#/locales';

defineOptions({ name: 'AgentCj7AddSubAccount' });

const leaveTo = useLeavePage();
const userStore = useUserStore();
const selfId = computed(() => Number((userStore.userInfo as any)?.id ?? 0));
const selfUsername = computed(
  () => (userStore.userInfo as any)?.username ?? '',
);

const loading = ref(false);
const submitting = ref(false);
/** 非子帳號才可新增子帳號 */
const eligible = ref(true);
const treeData = ref<AgentMenuNode[]>([]);
const allIds = ref<number[]>([]);
const checkAll = ref(true);
const treeRef = ref<InstanceType<typeof ElTree>>();

const treeProps = {
  children: 'submenu',
  label: (data: any) => tl(`menu_${data.code}`, data.name || data.code),
};

const form = reactive({
  nickname: '',
  password: '',
  password_confirmation: '',
  username: '',
});

function collectIds(nodes: AgentMenuNode[], acc: number[]) {
  for (const n of nodes) {
    acc.push(Number(n.id));
    if (n.submenu) collectIds(n.submenu, acc);
  }
}

async function loadData() {
  loading.value = true;
  try {
    const elig = await getAgentChildAccountsApi();
    if (Number(elig.code) !== 200) {
      eligible.value = false;
      ElMessage.error(elig.message);
      return;
    }
    if (!elig.data?.is_not_child_account) {
      eligible.value = false;
      return;
    }
    const tree = await getAgentMenuTreeApi(selfId.value);
    treeData.value = (tree ?? []) as AgentMenuNode[];
    const ids: number[] = [];
    collectIds(treeData.value, ids);
    allIds.value = ids;
    // 樹渲染後明確全勾（default-checked-keys 在資料後設可能不套用）
    await nextTick();
    treeRef.value?.setCheckedKeys(ids);
  } finally {
    loading.value = false;
  }
}

function toggleCheckAll(val: boolean | number | string) {
  treeRef.value?.setCheckedKeys(val ? allIds.value : []);
}

async function handleSave() {
  if (!form.username.trim()) {
    ElMessage.warning(tl('username'));
    return;
  }
  submitting.value = true;
  try {
    const checked = treeRef.value?.getCheckedKeys() ?? [];
    const half = treeRef.value?.getHalfCheckedKeys() ?? [];
    const res = await cj7AddSubAccountApi({
      agent_menus: [...checked, ...half],
      nickname: form.nickname,
      parent: selfId.value,
      password: [REDACTED],
      password_confirmation: form.password_confirmation,
      username: form.username,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      await leaveTo('/agent/user_list');
    } else {
      ElMessage.error(
        String((res as any).data ?? res.message) || tl('failure'),
      );
    }
  } finally {
    submitting.value = false;
  }
}

onMounted(loadData);
</script>

<template>
  <Page
    :title="`${tl('menu_cj7_user_management')} > ${tl('menu_cj7_add_sub_account')}`"
    auto-content-height
    footer-class="border-t border-border"
  >
    <ElAlert
      v-if="!eligible"
      :closable="false"
      :title="tl('is_subaccount_and_is_not_available')"
      type="error"
    />
    <ElCard v-else v-loading="loading" shadow="never">
      <ElForm @submit.prevent="handleSave">
        <div class="grid grid-cols-1 gap-x-8 lg:grid-cols-2">
          <div>
            <ElFormItem :label="tl('agent_previous')" label-width="120px">
              <span>{{ selfUsername }}</span>
            </ElFormItem>
            <ElFormItem label-width="120px">
              <template #label>
                <span class="text-red-500">*</span> {{ tl('username') }}
              </template>
              <ElInput
                v-model="form.username"
                :placeholder="tl('username')"
                autocomplete="new-password"
              />
            </ElFormItem>
            <ElFormItem :label="tl('name')" label-width="120px">
              <ElInput v-model="form.nickname" :placeholder="tl('nickname')" />
            </ElFormItem>
            <ElFormItem label-width="120px">
              <template #label>
                <span class="text-red-500">*</span> {{ tl('password') }}
              </template>
              <ElInput
                v-model="form.password"
                :placeholder="tl('password')"
                autocomplete="new-password"
                type="password"
              />
            </ElFormItem>
            <ElFormItem label-width="120px">
              <template #label>
                <span class="text-red-500">*</span> {{ tl('password_confirm') }}
              </template>
              <ElInput
                v-model="form.password_confirmation"
                :placeholder="tl('password_confirm')"
                type="password"
              />
            </ElFormItem>
          </div>

          <div>
            <div class="mb-2 font-medium">{{ tl('permission_setting') }}</div>
            <ElCheckbox v-model="checkAll" @change="toggleCheckAll">
              {{ tl('select_all') }}
            </ElCheckbox>
            <ElTree
              ref="treeRef"
              :data="treeData"
              :default-checked-keys="allIds"
              :props="treeProps"
              default-expand-all
              node-key="id"
              show-checkbox
            />
          </div>
        </div>
      </ElForm>
    </ElCard>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <ElButton @click="leaveTo()">{{ tl('btn_back') }}</ElButton>
        <ElButton :loading="submitting" type="primary" @click="handleSave">
          {{ tl('btn_update') }}
        </ElButton>
      </div>
    </template>
  </Page>
</template>
