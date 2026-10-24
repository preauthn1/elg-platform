<script lang="ts" setup>
import type { CsMe } from '#/api/cs';

import { computed, onMounted, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElCard,
  ElDescriptions,
  ElDescriptionsItem,
  ElTag,
} from 'element-plus';

import { getCsMeApi, runCsBootstrap } from '#/api/cs';
import { getProfitShareDomainApi } from '#/api/profit-share';
import { tl } from '#/locales';

defineOptions({ name: 'CsDashboard' });

const me = ref<CsMe>({});
// 網域與代理後台頂部「分享碼」同一來源，不用客服登入時存下的 csDomain 快照（target/tickets#4490）
const shareDomain = ref('');
// 進客服需先換 CS token（ensureCsLogin），整段期間顯示載入中
const loading = ref(true);

const ROLE_KEY: Record<number, string> = { 1: 'role_11', 2: 'agent', 3: 'cs' };
const roleText = computed(() => {
  const k = ROLE_KEY[Number(me.value.role_id)];
  return k ? tl(k) : me.value.role_id;
});

// 與代理後台頂部分享碼 QR 同一來源（agentDomain/self）；設定 9106 只是邀請碼上方的顯示文字（target/tickets#2672），不算網域
async function loadShareDomain() {
  const res = await getProfitShareDomainApi().catch(() => null);
  if (res && Number(res.code) === 1000) {
    shareDomain.value = res.data?.domain ?? '';
  }
}

onMounted(async () => {
  // 與 CS 登入流程互不相干，失敗不影響其他欄位
  loadShareDomain();
  try {
    await runCsBootstrap(async () => {
      me.value = await getCsMeApi();
    });
  } catch {
    // 首頁僅顯示基本資訊，全部重試失敗就顯示空值
  } finally {
    loading.value = false;
  }
});
</script>

<template>
  <Page :title="tl('cs_system')">
    <ElCard v-loading="loading" shadow="never">
      <template #header>{{ tl('cs.welcome') }}</template>
      <ElDescriptions :column="2" border>
        <ElDescriptionsItem :label="tl('form.username')">
          {{ me.member_username }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('cs.role')">
          <ElTag size="small" type="success">{{ roleText }}</ElTag>
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('domain')">
          {{ shareDomain }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('cs.member_id')">
          {{ me.member_id }}
        </ElDescriptionsItem>
      </ElDescriptions>
    </ElCard>
  </Page>
</template>
