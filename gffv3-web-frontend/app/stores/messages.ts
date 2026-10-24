import { defineStore } from 'pinia'
import type { AnnouncementMessage, SystemNotifyItem } from '#shared/api/endpoints/messages'
import { sumUnread } from '#shared/utils/messages'

/**
 * 通知中心跨頁狀態：三種未讀數（鈴鐺加總）、群發公告彈窗、
 * 系統通知詳情頁要看的那一筆（舊前台沒有 id 在網址，靠記憶體傳遞）。
 */
export const useMessagesStore = defineStore('messages', () => {
  const platformAnnouncementUnread = ref(0)
  const systemNotificationUnread = ref(0)
  const memberMailUnread = ref(0)
  const totalUnread = computed(() => sumUnread(
    platformAnnouncementUnread.value,
    systemNotificationUnread.value,
    memberMailUnread.value,
  ))

  const announcement = ref<AnnouncementMessage | null>(null)
  const announcementLoaded = ref(false)

  const selectedSystemNotification = ref<SystemNotifyItem | null>(null)

  function reset() {
    platformAnnouncementUnread.value = 0
    systemNotificationUnread.value = 0
    memberMailUnread.value = 0
    announcement.value = null
    announcementLoaded.value = false
    selectedSystemNotification.value = null
  }

  return {
    platformAnnouncementUnread,
    systemNotificationUnread,
    memberMailUnread,
    totalUnread,
    announcement,
    announcementLoaded,
    selectedSystemNotification,
    reset,
  }
})
