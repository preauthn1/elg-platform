<script lang="ts" setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';

import { AuthPageLayout } from '@vben/layouts';
import {
  preferences,
  preferencesManager,
  updatePreferences,
} from '@vben/preferences';

import { ElDialog } from 'element-plus';

import { getLanguagesApi } from '#/api';
import WorldIcon from '#/components/world-icon.vue';
import {
  $t,
  chromeLocaleFor,
  frameworkToCode,
  getBackendLang,
  setBackendLang,
  tl,
} from '#/locales';

const route = useRoute();

/**
 * 僅登入頁顯示語系入口：本圖示以 fixed 定位於表單標題列高度，
 * 在忘記密碼等欄位較多的子頁會壓到表單內容（target/tickets#4161）。
 */
const showLanguageToggle = computed(() => route.name === 'Login');

const appName = computed(() => example.invalid.name);
const logo = computed(() => preferences.logo.source);
const logoDark = computed(() => preferences.logo.sourceDark);

/**
 * 登入頁語言切換（target/tickets#4161）。
 *
 * 不使用框架內建的 LanguageToggle：其 SUPPORT_LANGUAGES 寫死僅中英兩種，且只更新
 * 前端 i18n 而不同步後端語系，會造成「切了英文但後端內容仍是中文」。
 * 此處比照 layouts/basic.vue 的 handleSelectLanguage，語系清單取自後端，
 * 並同時寫入後端語系與前端 locale 後重載，確保兩者一致。
 */
interface LangItem {
  code: string;
  name: string;
}

const langVisible = ref(false);
const languageList = ref<LangItem[]>([]);

const currentLangCode = computed(
  () => getBackendLang() || frameworkToCode(example.invalid.locale),
);

onMounted(async () => {
  const list = await getLanguagesApi().catch(() => []);
  languageList.value = (list ?? []) as LangItem[];
});

async function handleSelectLanguage(code: string) {
  if (!code || code === currentLangCode.value) return;
  setBackendLang(code);
  updatePreferences({ app: { locale: chromeLocaleFor(code) } });
  // 偏好寫入是 debounce 的，reload 前必須先落盤，否則 locale 更新會遺失
  await preferencesManager.flush();
  window.location.reload();
}
</script>

<template>
  <AuthPageLayout
    :app-name="appName"
    :logo="logo"
    :logo-dark="logoDark"
    :page-description="$t('authentication.pageDesc')"
    :page-title="$t('authentication.pageTitle')"
    :toolbar="false"
  >
    <!--
      借用 copyright 插槽把圖示送進右側表單面板內（見 layouts/authentication/form.vue）。
      該插槽包裹層為 absolute bottom-3、會成為子層定位基準，故以 fixed 脫離。
      水平對齊 input 右緣切線：input 容器為 md:max-w-md（448px）在寬 2/5 的面板內
      置中，面板貼齊視窗右緣，故 input 右緣距視窗右緣為 20% - 14rem；
      窄螢幕該值為負，以 max() 退回面板內距 1.5rem。
    -->
    <template #copyright>
      <span
        v-if="showLanguageToggle"
        :title="tl('language')"
        class="text-muted-foreground hover:text-foreground hover:bg-accent fixed flex cursor-pointer items-center rounded-md p-1.5"
        style="top: calc(50% - 8.5rem); right: max(1.5rem, calc(20% - 14rem))"
        @click="langVisible = true"
      >
        <WorldIcon class="size-5" />
      </span>
    </template>
  </AuthPageLayout>

  <!-- 語系選單：清單與切換行為與登入後（basic.vue）完全一致 -->
  <ElDialog
    v-model="langVisible"
    :title="tl('language')"
    align-center
    class="lang-dialog"
    width="420px"
  >
    <div class="grid grid-cols-3 gap-2">
      <div
        v-for="l in languageList"
        :key="l.code"
        :class="
          l.code === currentLangCode
            ? 'border-primary text-primary'
            : 'border-border hover:border-primary'
        "
        class="cursor-pointer rounded-md border px-3 py-2 text-center text-sm"
        @click="handleSelectLanguage(l.code)"
      >
        {{ l.name }}
      </div>
    </div>
  </ElDialog>
</template>
