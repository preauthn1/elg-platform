<script setup lang="ts">
import { useMessagesStore } from '~/stores/messages'

/**
 * 群發公告彈窗：掛在大廳（/）即可，掛上去自己抓最新一筆，關閉時標記已讀。
 * 未登入也能看（HasMember），已讀對訪客是 no-op。
 */
const store = useMessagesStore()
const { fetchLatestAnnouncement, readAnnouncement } = useMessages()

onMounted(() => {
  fetchLatestAnnouncement()
})

async function close() {
  const current = store.announcement
  if (!current) return
  await readAnnouncement(current.announcement_message_id)
}
</script>

<template>
  <div
    v-if="store.announcement"
    class="fixed inset-0 z-50 flex items-center justify-center bg-scrim p-4"
  >
    <div class="w-full max-w-md rounded bg-surface p-4">
      <h2 class="font-semibold">
        {{ store.announcement.title }}
      </h2>
      <p class="mt-2 whitespace-pre-wrap text-sm">
        {{ store.announcement.message }}
      </p>
      <div class="mt-4 text-right">
        <button
          type="button"
          class="rounded bg-accent px-4 py-1 text-sm text-inverse"
          @click="close"
        >
          {{ $t('common.ui.action.close') }}
        </button>
      </div>
    </div>
  </div>
</template>
