<script lang="ts" setup>
import type { CsUserView } from '#/api/cs';

import { onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElTabPane,
  ElTabs,
} from 'element-plus';

import {
  editCsUserApi,
  getCsMeApi,
  getCsMenuNodes,
  getCsSystemSettingApi,
  getCsUserViewApi,
  runCsBootstrap,
  saveCsSystemSettingApi,
} from '#/api/cs';
import { tl } from '#/locales';
import { floorAmount } from '#/utils/format';

defineOptions({ name: 'CsSetup' });

// 送出中：防連點重複送出（兩個 tab 共用）
const saving = ref(false);
// 初次進客服需換 CS token，整段顯示載入中
const loading = ref(true);
const bootstrapError = ref(false);

// ===== 系統設定 =====
const sys = reactive({
  api_url: '',
  background_color: '',
  background_image: '',
  header_background_color: '',
  header_font_color: '',
  name: '',
  origImg: '',
});

/** 不吞錯誤的原始版本，bootstrap() 才需要知道有沒有真的失敗（供重試判斷） */
async function fetchSys() {
  const d = await getCsSystemSettingApi();
  Object.assign(sys, {
    api_url: d.api_url ?? '',
    background_color: d.background_color ?? '',
    background_image: '',
    header_background_color: d.header_background_color ?? '',
    header_font_color: d.header_font_color ?? '',
    name: d.name ?? '',
    origImg: d.background_image ?? '',
  });
}
async function loadSys() {
  try {
    await fetchSys();
  } catch {
    // 系統尚未設定
  }
}
function pickSysImg() {
  const input = document.createElement('input');
  input.type = 'file';
  input.accept = 'image/*';
  input.addEventListener('change', () => {
    const file = input.files?.[0];
    if (!file) return;
    const reader = new FileReader();
    reader.addEventListener('load', () => {
      sys.background_image = String(reader.result);
    });
    reader.readAsDataURL(file);
  });
  input.click();
}
async function saveSys() {
  const payload: Record<string, any> = {
    api_url: sys.api_url,
    background_color: sys.background_color,
    header_background_color: sys.header_background_color,
    header_font_color: sys.header_font_color,
    name: sys.name,
  };
  // 圖片僅在重新上傳（base64）時送出
  if (sys.background_image.startsWith('data:')) {
    payload.background_image = sys.background_image;
  }
  if (saving.value) return;
  saving.value = true;
  try {
    await saveCsSystemSettingApi(payload);
    ElMessage.success(tl('cs.save_success'));
    await loadSys();
  } catch (error: any) {
    ElMessage.error(error?.message || tl('cs.save_failed'));
  } finally {
    saving.value = false;
  }
}

// ===== 個人設定 =====
const self = reactive({
  balance: '' as number | string,
  id: '' as number | string,
  nickname: '',
  origThumb: '',
  thumbnail: '',
});

function balanceText(v: CsUserView): number | string {
  if (v.balance !== null && v.balance !== undefined) {
    return v.balance === -1 ? tl('cs.no_limit') : floorAmount(v.balance);
  }
  if (String(v.limit_of_credit) === '-1') return tl('cs.no_limit');
  return floorAmount(
    Number(v.limit_of_credit ?? 0) - Number(v.used_credit ?? 0),
  );
}
/** view 完全沒有可用欄位（剛換 CS token、後端資料還沒就緒時會發生）。 */
function isEmptyView(v: CsUserView): boolean {
  return (
    v.nickname === undefined &&
    v.balance === undefined &&
    v.limit_of_credit === undefined
  );
}
async function loadSelf() {
  const me = await getCsMeApi();
  self.id = me.id ?? '';
  if (me.id === null || me.id === undefined) return;
  let v = await getCsUserViewApi(me.id);
  // 剛登入客服時後端資料可能還沒就緒，短暫重試避免個人設定的暱稱/餘額整個空白
  for (let i = 0; i < 3 && isEmptyView(v); i++) {
    await new Promise((r) => setTimeout(r, 800));
    v = await getCsUserViewApi(me.id);
  }
  self.nickname = v.nickname ?? '';
  self.origThumb = v.thumbnail ?? '';
  self.balance = balanceText(v);
}
function pickSelfImg() {
  const input = document.createElement('input');
  input.type = 'file';
  input.accept = 'image/*';
  input.addEventListener('change', () => {
    const file = input.files?.[0];
    if (!file) return;
    const reader = new FileReader();
    reader.addEventListener('load', () => {
      self.thumbnail = String(reader.result);
    });
    reader.readAsDataURL(file);
  });
  input.click();
}
async function saveSelf() {
  if (saving.value) return;
  const payload: Record<string, any> = { id: self.id, nickname: self.nickname };
  if (self.thumbnail.startsWith('data:')) payload.thumbnail = self.thumbnail;
  saving.value = true;
  try {
    await editCsUserApi(payload as any);
    ElMessage.success(tl('cs.save_success'));
    await loadSelf();
  } catch (error: any) {
    ElMessage.error(error?.message || tl('cs.save_failed'));
  } finally {
    saving.value = false;
  }
}

// 舊版「設定」在選單裡是兩個獨立節點（/setup/system、/setup/self），
// 系統設定要不要顯示是看 userMenu/list 有沒有回傳對應節點；新版把兩者併成
// 同一頁的分頁，這裡改用同一份選單權限資料判斷系統設定分頁要不要顯示。
// 沒有任何選單資料（尚未取得權限，或客服後端連不上）時保守顯示，避免
// 因為一次暫時性錯誤就把系統設定分頁整個藏起來。
const showSystemTab = ref(true);
function refreshShowSystemTab() {
  const nodes = getCsMenuNodes();
  if (nodes.length === 0) return;
  showSystemTab.value = nodes.some((n) =>
    n.url?.replace(/^\/+/, '').includes('setup/system'),
  );
}

async function bootstrap() {
  loading.value = true;
  bootstrapError.value = false;
  try {
    // 系統設定沒配置是正常狀態（沿用原本的容錯），個人設定失敗才視為真的出錯
    await runCsBootstrap(() =>
      Promise.all([loadSys(), loadSelf()]).then(() => {}),
    );
    refreshShowSystemTab();
  } catch {
    bootstrapError.value = true;
  } finally {
    loading.value = false;
  }
}
onMounted(bootstrap);
</script>

<template>
  <Page :title="tl('menu.setup')">
    <div v-if="bootstrapError" class="flex flex-col items-center gap-3 py-16">
      <span class="text-muted-foreground">{{ tl('cs.load_failed') }}</span>
      <ElButton type="primary" @click="bootstrap">
        {{ tl('retry') }}
      </ElButton>
    </div>
    <ElCard v-else v-loading="loading" shadow="never">
      <ElTabs>
        <ElTabPane v-if="showSystemTab" :label="tl('menu.system_setup')">
          <ElForm label-width="120px" style="max-width: 560px">
            <ElFormItem :label="tl('form.room_Name')">
              <ElInput v-model="sys.name" />
            </ElFormItem>
            <ElFormItem :label="tl('cs.bg_image')">
              <div>
                <img
                  v-if="sys.background_image || sys.origImg"
                  :src="sys.background_image || sys.origImg"
                  style="display: block; max-width: 240px; margin-bottom: 8px"
                />
                <ElButton @click="pickSysImg">{{ tl('choose_file') }}</ElButton>
              </div>
            </ElFormItem>
            <ElFormItem :label="tl('cs.bg_color')">
              <ElInput v-model="sys.background_color" />
            </ElFormItem>
            <ElFormItem :label="tl('cs.title_bg_color')">
              <ElInput v-model="sys.header_background_color" />
            </ElFormItem>
            <ElFormItem :label="tl('cs.title_text_color')">
              <ElInput v-model="sys.header_font_color" />
            </ElFormItem>
            <ElFormItem :label="tl('cs.api_url')">
              <ElInput v-model="sys.api_url" />
            </ElFormItem>
            <ElFormItem>
              <ElButton type="primary" :loading="saving" @click="saveSys">
                {{ tl('form.save') }}
              </ElButton>
            </ElFormItem>
          </ElForm>
        </ElTabPane>

        <ElTabPane :label="tl('router.setup_self')">
          <ElForm label-width="120px" style="max-width: 560px">
            <ElFormItem :label="tl('form.avatar')">
              <div>
                <img
                  v-if="self.thumbnail || self.origThumb"
                  :src="self.thumbnail || self.origThumb"
                  style="
                    display: block;
                    width: 96px;
                    height: 96px;
                    margin-bottom: 8px;
                    object-fit: cover;
                    border-radius: 50%;
                  "
                />
                <ElButton @click="pickSelfImg">
                  {{ tl('choose_file') }}
                </ElButton>
              </div>
            </ElFormItem>
            <ElFormItem :label="tl('form.nickname')">
              <ElInput v-model="self.nickname" />
            </ElFormItem>
            <ElFormItem :label="tl('form.limit_of_credit')">
              <ElInput :model-value="self.balance" disabled />
            </ElFormItem>
            <ElFormItem>
              <ElButton type="primary" :loading="saving" @click="saveSelf">
                {{ tl('form.save') }}
              </ElButton>
            </ElFormItem>
          </ElForm>
        </ElTabPane>
      </ElTabs>
    </ElCard>
  </Page>
</template>
