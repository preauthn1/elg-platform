<script lang="ts" setup>
import type { CsFriend, CsPagination } from '#/api/cs';

import { onMounted, ref } from 'vue';

import { ElButton, ElCheckbox, ElEmpty, ElInput } from 'element-plus';

import { getCsContactListApi, runCsBootstrap } from '#/api/cs';
import UserAvatar from '#/components/user-avatar.vue';
import { tl } from '#/locales';

defineOptions({ name: 'CsContacts' });

/** 點聯絡人就開啟對話（#4157），開房交給 chat.vue（它才有房間列表與 socket） */
const emit = defineEmits<{ open: [CsFriend] }>();

const kind = ref<'customer' | 'friend'>('customer');
const search = ref('');
const fuzzy = ref(false);
const page = ref(1);
const list = ref<CsFriend[]>([]);
const pagination = ref<CsPagination>({});
const loading = ref(false);

const TYPE_KEY: Record<number, string> = { 0: 'agent', 1: 'member' };
function typeLabel(t?: number) {
  return tl(TYPE_KEY[Number(t)] ?? 'other');
}

/** 代理與客服的 third_party_id 固定回傳字串 '0'（同 chat.vue 的 peerAccount），
 * 純數字判斷式的 truthy 檢查擋不住字串 '0'，需明確排除才會退回 username。 */
function contactSubtitle(item: CsFriend): string {
  const thirdPartyId = item.third_party_id;
  if (thirdPartyId && String(thirdPartyId) !== '0') return String(thirdPartyId);
  return item.username ?? '';
}

async function load() {
  loading.value = true;
  try {
    const res = await getCsContactListApi({
      customer: kind.value === 'customer',
      fuzzy: fuzzy.value,
      page: page.value,
      username: search.value,
    });
    list.value = res.data ?? [];
    pagination.value = res.pagination ?? {};
  } catch {
    list.value = [];
  } finally {
    loading.value = false;
  }
}
function resetAndLoad() {
  page.value = 1;
  return load();
}
let searchSeq = 0;
/** 投訴熱線是後端在每頁固定塞的外部連結列（type=2），不算搜尋結果 */
const HOTLINE_TYPE = 2;
/** 主動搜尋（按鈕或 Enter）撈到恰好 1 筆時直接開房（#4316）。
 * 判斷寫在這裡而非 load()：switchKind／turnPage 也共用 load()，
 * 寫進去會在切頁籤、翻頁時蓋掉客服正在看的對話。
 * 只 emit('open')，不複用 selectContact()：url 型聯絡人會被彈成新分頁。
 * 代理開啟投訴熱線設定時，後端每頁都固定在最前面塞一筆 type=2 的外部連結列，
 * 那不是搜尋命中，先濾掉才算得出真正的命中筆數。
 * searchSeq 為連按搜尋的守衛，只採最後一次請求的結果。 */
async function doSearch() {
  const seq = ++searchSeq;
  await resetAndLoad();
  if (seq !== searchSeq) return;
  const hits = list.value.filter((item) => Number(item.type) !== HOTLINE_TYPE);
  const only = hits.length === 1 ? hits[0] : undefined;
  if (only && !only.url) emit('open', only);
}
function switchKind(k: 'customer' | 'friend') {
  kind.value = k;
  resetAndLoad();
}
function turnPage(p: unknown) {
  if (p !== '' && p !== null && p !== undefined) {
    page.value = Number(p);
    load();
  }
}
function selectContact(item: CsFriend) {
  if (item.url) {
    window.open(item.url, '_blank', 'noopener');
    return;
  }
  emit('open', item);
}

onMounted(async () => {
  loading.value = true;
  try {
    await runCsBootstrap(load);
  } finally {
    loading.value = false;
  }
});
</script>

<template>
  <!-- 聯絡人列表：點一列就開對話，不再顯示唯讀的個資卡（#4157）。
       #4204 起改嵌在聊天頁左欄（對話區保留），外框由左欄提供，不再自帶邊框 -->
  <div class="flex h-full flex-col overflow-hidden">
    <div class="flex-none space-y-2 border-b p-2">
      <div class="flex gap-1">
        <ElButton
          size="small"
          :type="kind === 'customer' ? 'primary' : 'default'"
          @click="switchKind('customer')"
        >
          {{ tl('cs.customer') }}
        </ElButton>
        <ElButton
          size="small"
          :type="kind === 'friend' ? 'primary' : 'default'"
          @click="switchKind('friend')"
        >
          {{ tl('cs.friend') }}
        </ElButton>
      </div>
      <div class="flex items-center gap-1">
        <ElInput
          v-model="search"
          size="small"
          :placeholder="tl('form.search')"
          @keyup.enter="doSearch"
        />
        <ElCheckbox v-model="fuzzy" size="small">
          {{ tl('form.fuzzy') }}
        </ElCheckbox>
        <ElButton size="small" type="primary" @click="doSearch">
          {{ tl('cs.query_short') }}
        </ElButton>
      </div>
    </div>
    <div v-loading="loading" class="flex-1 overflow-y-auto">
      <ElEmpty v-if="list.length === 0" :description="tl('cs.no_contacts')" />
      <template v-for="(item, i) in list" :key="i">
        <div
          v-if="i === 0 || list[i - 1]?.type !== item.type"
          class="bg-muted px-3 py-1 text-xs font-semibold text-muted-foreground"
        >
          {{ typeLabel(item.type) }}
        </div>
        <div
          class="flex cursor-pointer items-center gap-2 border-b px-3 py-2 hover:bg-accent"
          @click="selectContact(item)"
        >
          <UserAvatar :src="item.thumbnail" :size="32" class="flex-none" />
          <div class="min-w-0">
            <div class="truncate text-sm">{{ item.nickname }}</div>
            <div class="truncate text-xs text-muted-foreground">
              {{ contactSubtitle(item) }}
            </div>
          </div>
        </div>
      </template>
    </div>
    <div
      class="flex flex-none items-center justify-between border-t px-3 py-2 text-sm"
    >
      <ElButton link size="small" @click="turnPage(pagination.previous)">
        ‹
      </ElButton>
      <span>{{ pagination.current || 0 }} / {{ pagination.pages || 0 }}</span>
      <ElButton link size="small" @click="turnPage(pagination.next)">
        ›
      </ElButton>
    </div>
  </div>
</template>
