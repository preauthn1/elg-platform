<script lang="ts" setup>
import { computed, onMounted, ref } from 'vue';

import { Page } from '@vben/common-ui';

import { useQRCode } from '@vueuse/integrations/useQRCode';
import { ElCard, ElMessage } from 'element-plus';

import { getProfitShareDomainApi, getProfitShareInviteCodeApi } from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'ProfitShareQrcode' });

const domain = ref('');
const shareholderCode = ref('');
const profitShareCode = ref('');

/** 註冊連結：domain 含 '?' 則直接接 code，否則接 /Signup/code */
function signupUrl(code: string) {
  if (!domain.value || !code) return '';
  const sep = domain.value.includes('?') ? '' : '/Signup/';
  return `https://${domain.value}${sep}${code}`;
}

const shareholderUrl = computed(() => signupUrl(shareholderCode.value));
const profitShareUrl = computed(() => signupUrl(profitShareCode.value));

const shareholderQr = useQRCode(shareholderUrl, {
  errorCorrectionLevel: 'H',
  width: 200,
});
const profitShareQr = useQRCode(profitShareUrl, {
  errorCorrectionLevel: 'H',
  width: 200,
});

async function loadData() {
  const [domainRes, codeRes] = await Promise.all([
    getProfitShareDomainApi().catch(() => null),
    getProfitShareInviteCodeApi(),
  ]);
  if (domainRes && Number(domainRes.code) === 1000) {
    domain.value = domainRes.data?.domain ?? '';
  }
  if (Number(codeRes.code) === 1000) {
    shareholderCode.value = codeRes.data?.shareholder ?? '';
    profitShareCode.value = codeRes.data?.profit_share ?? '';
  }
}

async function copyCode(code: string) {
  if (!code) return;
  await navigator.clipboard.writeText(code);
  ElMessage.success(tl('copy_successfully'));
}

onMounted(loadData);
</script>

<template>
  <Page
    :title="`${tl('menu_cj7_user_management')} > ${tl('menu_profit_share_qrcode')}`"
  >
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
      <ElCard shadow="never">
        <div class="text-center">
          <h5 class="text-green-600 mb-3 font-bold">
            {{ tl('shareholder_member') }}
          </h5>
          <div class="mb-3 flex justify-center">
            <img
              v-if="shareholderQr"
              :src="shareholderQr"
              alt="shareholder qrcode"
              class="h-50 w-50"
            />
          </div>
          <h3
            class="cursor-pointer text-lg font-bold"
            @click="copyCode(shareholderCode)"
          >
            {{ shareholderCode }}
          </h3>
        </div>
      </ElCard>

      <ElCard shadow="never">
        <div class="text-center">
          <h5 class="text-blue-500 mb-3 font-bold">
            {{ tl('profit_share_member') }}
          </h5>
          <div class="mb-3 flex justify-center">
            <img
              v-if="profitShareQr"
              :src="profitShareQr"
              alt="profit share qrcode"
              class="h-50 w-50"
            />
          </div>
          <h3
            class="cursor-pointer text-lg font-bold"
            @click="copyCode(profitShareCode)"
          >
            {{ profitShareCode }}
          </h3>
        </div>
      </ElCard>
    </div>
  </Page>
</template>
