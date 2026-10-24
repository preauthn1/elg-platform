<script setup lang="ts">
import type { PlatformAnnouncementItem } from '#shared/api/endpoints/messages'
import {
  filterPlatformAnnouncements,
  platformAnnouncementAction,
  platformAnnouncementFilteredTotal,
  platformAnnouncementListContent,
  platformAnnouncementTagKey,
  readPagination,
} from '#shared/utils/messages'

const props = defineProps<{
  /** 只顯示這個 tag（活動消息分頁傳 3） */
  tagFilter?: number | null
}>()

const { template } = useSite()
const { loadPlatformAnnouncementsTab } = useMessages()

const isP2 = computed(() => template.value?.process === 'P2')
const filter = computed(() => ({ isP2: isP2.value, tagFilter: props.tagFilter ?? null }))

const page = ref(1)
const items = ref<PlatformAnnouncementItem[]>([])
const total = ref(0)
const loading = ref(true)
const selected = ref<PlatformAnnouncementItem | null>(null)

const list = computed(() => filterPlatformAnnouncements(items.value, filter.value))
const filteredTotal = computed(() => platformAnnouncementFilteredTotal(items.value, total.value, filter.value))
const pages = computed(() => Math.max(1, Math.ceil(filteredTotal.value / MESSAGES_PER_PAGE)))

async function load() {
  loading.value = true
  const res = await loadPlatformAnnouncementsTab(page.value)
  if (isApiOk(res)) {
    items.value = res.data.items
    total.value = readPagination(res.data.pagination).total
  }
  else {
    // 失敗訊息由 useApi 的全站彈窗呈現，列表退回空狀態
    items.value = []
    total.value = 0
  }
  loading.value = false
}

async function onClick(item: PlatformAnnouncementItem) {
  const action = platformAnnouncementAction(item)
  switch (action.kind) {
    case 'popup':
      selected.value = item
      break
    case 'external':
      if (action.newWindow) window.open(action.url, '_blank', 'noopener')
      else window.location.assign(action.url)
      break
    case 'route':
      await navigateTo(action.path)
      break
    default:
      break
  }
}

onMounted(load)
watch(page, load)
</script>

<template>
  <div>
    <p
      v-if="loading"
      class="text-sm text-muted"
    >
      {{ $t('common.ui.label.loading') }}
    </p>
    <p
      v-else-if="list.length === 0"
      class="text-sm text-muted"
    >
      {{ $t('common.ui.empty.noData') }}
    </p>

    <ul
      v-else
      class="flex flex-col gap-(--c17-tile-gap)"
    >
      <li
        v-for="item in list"
        :key="item.id"
      >
        <button
          type="button"
          class="block w-full rounded-(--c17-radius-sm) bg-surface p-(--c17-gap-3) shadow-[var(--c17-shadow-panel)] text-left disabled:cursor-default"
          :disabled="item.type === 0"
          @click="onClick(item)"
        >
          <div class="flex items-center justify-between text-sm">
            <span class="font-semibold">{{ platformAnnouncementTagKey(item.tag) ? $t(platformAnnouncementTagKey(item.tag)) : '' }}</span>
            <span class="text-(length:--c17-text-label) text-muted">
              <template v-if="isP2">{{ $t('common.time.label.startTime') }}: </template>{{ item.display_time }}
            </span>
          </div>
          <div class="mt-2 flex gap-3">
            <img
              v-if="item.image_url && (!isP2 || tagFilter === 3)"
              :src="item.image_url"
              alt=""
              class="size-12 shrink-0 rounded-(--c17-radius-sm) object-cover"
            >
            <div class="min-w-0">
              <div class="font-medium">
                {{ item.title }}
              </div>
              <div class="truncate text-sm text-muted">
                {{ platformAnnouncementListContent(item) }}
              </div>
            </div>
          </div>
          <div
            v-if="tagFilter === 3"
            class="mt-2 text-right text-xs text-accent"
          >
            {{ $t('events.detail.nav.eventDetail') }} &gt;
          </div>
        </button>
      </li>
    </ul>

    <UiPagination
      v-model:page="page"
      :pages="pages"
    />

    <div
      v-if="selected"
      class="fixed inset-0 z-50 flex items-center justify-center bg-scrim p-4"
      @click.self="selected = null"
    >
      <div class="w-full max-w-md rounded bg-surface p-4">
        <img
          v-if="selected.image_url"
          :src="selected.image_url"
          alt=""
          class="mb-3 w-full rounded object-cover"
        >
        <h2 class="font-semibold">
          {{ selected.title }}
        </h2>
        <p
          v-if="selected.content"
          class="mt-2 whitespace-pre-wrap text-sm"
        >
          {{ selected.content }}
        </p>
        <div class="mt-4 text-right">
          <button
            type="button"
            class="rounded bg-accent px-4 py-1 text-sm text-inverse"
            @click="selected = null"
          >
            {{ $t('common.ui.action.close') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
