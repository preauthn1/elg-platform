<script lang="ts" setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import { Page } from '@vben/common-ui';
import { useUserStore } from '@vben/stores';

import {
  ElAlert,
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElMessageBox,
  ElOption,
  ElSelect,
} from 'element-plus';

import {
  confirmAgentPasswordApi,
  DEFAULT_CJ7_PERMISSIONS,
  getAgentShareHolderApi,
  getAgentViewByIdApi,
  getCustomizedSettingApi,
  saveCj7AgentApi,
  searchAgentOptionsApi,
  setAgentPermissionApi,
} from '#/api';
import { tl } from '#/locales';
import { hasMenuCode } from '#/router/agent-menu';

defineOptions({ name: 'AgentCj7Edit' });

const route = useRoute();
const router = useRouter();
const userStore = useUserStore();
const selfId = computed(() => Number((userStore.userInfo as any)?.id ?? 0));
const selfUsername = computed(
  () => (userStore.userInfo as any)?.username ?? '',
);

/** 股份只允許整數（op_tickets#3650） */
const onlyInt = (v: number | string) => String(v).replaceAll(/\D/g, '');

const agentId = (route.query.id as string) || '';
const isEdit = computed(() => Boolean(agentId));
/** 新增時是否可選上級（權限 cj7_add_user_select_parent） */
const allowSelectParent = computed(() =>
  hasMenuCode('cj7_add_user_select_parent'),
);

const loading = ref(false);
const submitting = ref(false);
const tips = ref('');
const ownShare = ref<number | string>(0);
/** 顯示股份欄（新增 或 編輯且 host_id==0） */
const showShare = ref(true);
/** 編輯時是否為自己（自編需密碼驗證） */
const isSelf = ref(false);
const parentUsername = ref('');

/** 上級遠端搜尋候選 */
const parentOptions = ref<Array<{ id: number | string; username: string }>>([]);
const parentSearching = ref(false);

const form = reactive({
  nickname: '',
  parent: '' as number | string,
  password: '',
  password_confirmation: '',
  share: 0 as number | string,
  username: '',
});

async function searchParent(q: string) {
  if (!q) {
    parentOptions.value = [];
    return;
  }
  parentSearching.value = true;
  try {
    const res = await searchAgentOptionsApi(q);
    parentOptions.value = Number(res.code) === 1000 ? (res.data ?? []) : [];
  } finally {
    parentSearching.value = false;
  }
}

async function onParentChange() {
  if (!form.parent) return;
  const res = await getAgentShareHolderApi(form.parent);
  if (Number(res.code) === 1000) ownShare.value = res.data?.value ?? 0;
}

async function loadData() {
  loading.value = true;
  try {
    const settings = await getCustomizedSettingApi().catch(() => []);
    const map = new Map(
      (settings ?? []).map((s: any) => [Number(s.setting_key), s.value]),
    );
    tips.value = String(map.get(3098) ?? '');

    if (isEdit.value) {
      const agent = (await getAgentViewByIdApi(Number(agentId))) as any;
      form.username = agent?.username ?? '';
      form.nickname = agent?.nickname ?? '';
      parentUsername.value = agent?.parent_username ?? '';
      isSelf.value = Number(agent?.id) === selfId.value;
      showShare.value = Number(agent?.host_id) === 0;
      const sh = await getAgentShareHolderApi(agentId);
      if (Number(sh.code) === 1000) form.share = sh.data?.value ?? 0;
    } else {
      showShare.value = true;
      form.parent = allowSelectParent.value ? '' : selfId.value;
    }

    // 自身股份（own_share）：編輯自己時取 parent，否則取自己
    const ownTargetId =
      isEdit.value && Number(agentId) === selfId.value
        ? Number((userStore.userInfo as any)?.parent ?? selfId.value)
        : selfId.value;
    const own = await getAgentShareHolderApi(ownTargetId);
    if (Number(own.code) === 1000) ownShare.value = own.data?.value ?? 0;
  } finally {
    loading.value = false;
  }
}

async function doSave() {
  submitting.value = true;
  try {
    const res = await saveCj7AgentApi({
      ...(isEdit.value
        ? { id: agentId }
        : { parent: form.parent, username: form.username }),
      nickname: form.nickname,
      // 工單4506：編輯時不再送密碼（改走代理列表操作選單／右上角個人選單）
      ...(!isEdit.value && form.password
        ? {
            password: [REDACTED],
            password_confirmation: form.password_confirmation,
          }
        : {}),
      share: form.share,
    });
    if (Number(res.code) !== 1000) {
      ElMessage.error(
        String((res as any).data ?? res.message) || tl('failure'),
      );
      return;
    }
    // 新增成功 → 套預設 CJ7 權限
    if (!isEdit.value && res.data) {
      const menus: Record<string, string> = {};
      for (const code of DEFAULT_CJ7_PERMISSIONS) menus[code] = '';
      await setAgentPermissionApi({ agent_id: res.data as any, menus }).catch(
        () => null,
      );
    }
    ElMessage.success(tl('success'));
    router.back();
  } finally {
    submitting.value = false;
  }
}

async function handleSave() {
  // 編輯自己 → 先驗證原密碼
  if (isEdit.value && isSelf.value) {
    try {
      const { value } = await ElMessageBox.prompt(
        tl('original_password'),
        tl('original_password'),
        {
          confirmButtonText: tl('confirm'),
          cancelButtonText: tl('cancel'),
          inputType: 'password',
        },
      );
      const pw = await confirmAgentPasswordApi(value);
      if (Number(pw.code) !== 1000) {
        ElMessage.error(String(pw.data ?? pw.message));
        return;
      }
    } catch {
      return;
    }
  }
  doSave();
}

onMounted(loadData);
</script>

<template>
  <Page
    :title="`${tl('menu_cj7_user_management')} > ${isEdit ? tl('cj7_edit_agent') : tl('menu_cj7_add_user')}`"
    auto-content-height
    footer-class="border-t border-border"
  >
    <ElCard v-loading="loading" shadow="never">
      <ElForm
        class="max-w-2xl"
        label-width="140px"
        @submit.prevent="handleSave"
      >
        <ElFormItem :label="tl('agent_previous')">
          <template v-if="isEdit">
            <span>{{ parentUsername }}</span>
          </template>
          <template v-else-if="allowSelectParent">
            <ElSelect
              v-model="form.parent"
              :debounce="500"
              :loading="parentSearching"
              :remote-method="searchParent"
              class="!w-full"
              filterable
              remote
              remote-show-suffix
              @change="onParentChange"
            >
              <ElOption
                v-for="o in parentOptions"
                :key="o.id"
                :label="o.username"
                :value="o.id"
              />
            </ElSelect>
          </template>
          <span v-else>{{ selfUsername }}</span>
        </ElFormItem>

        <ElFormItem :label="tl('username')">
          <span v-if="isEdit">{{ form.username }}</span>
          <ElInput
            v-else
            v-model="form.username"
            :placeholder="tl('cj7_username')"
            autocomplete="new-password"
          />
        </ElFormItem>

        <ElFormItem :label="tl('name')">
          <ElInput v-model="form.nickname" :placeholder="tl('cj7_nickname')" />
        </ElFormItem>

        <template v-if="!isEdit">
          <ElFormItem :label="tl('password')">
            <ElInput
              v-model="form.password"
              :placeholder="tl('cj7_password')"
              autocomplete="new-password"
              type="password"
            />
          </ElFormItem>
          <ElFormItem :label="tl('password_confirm')">
            <ElInput
              v-model="form.password_confirmation"
              :placeholder="tl('cj7_repassword')"
              type="password"
            />
          </ElFormItem>
        </template>

        <ElFormItem v-if="showShare" :label="tl('share')">
          <template v-if="isEdit">
            <span>{{ form.share }}</span>
          </template>
          <div v-else class="flex items-center gap-2">
            <ElInput
              v-model="form.share"
              :formatter="onlyInt"
              :parser="onlyInt"
              style="width: 120px"
            />
            <span>/ {{ ownShare }}</span>
          </div>
        </ElFormItem>

        <ElAlert v-if="tips" :closable="false" class="mb-3" type="error">
          <span style="white-space: pre-line">{{ tips }}</span>
        </ElAlert>
      </ElForm>
    </ElCard>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <ElButton @click="router.back()">{{ tl('btn_back') }}</ElButton>
        <ElButton :loading="submitting" type="primary" @click="handleSave">
          {{ tl('btn_update') }}
        </ElButton>
      </div>
    </template>
  </Page>
</template>
