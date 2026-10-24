<script setup lang="ts">
import { useMessagesStore } from '~/stores/messages'

/** 群發公告彈窗的獨立入口；大廳（/）掛 <MessagesAnnouncementPopup /> 即可，不需要這頁。 */
const store = useMessagesStore()
</script>

<template>
  <div>
    <C17PageHeader
      :title="$t('messages.announcement.title.announcement')"
      fallback="/member"
      :balance="false"
    />

    <div class="flex flex-col gap-(--c17-gap-3) px-(--c17-pad-x) py-(--c17-gap-4)">
      <p
        v-if="store.announcementLoaded && !store.announcement"
        class="text-sm text-muted"
      >
        {{ $t('common.ui.empty.noData') }}
      </p>
      <MessagesAnnouncementPopup />
    </div>
  </div>
</template>
