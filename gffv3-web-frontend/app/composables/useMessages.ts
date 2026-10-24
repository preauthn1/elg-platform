import type { ApiEnvelope } from '#shared/types/api'
import type { ResponseOf } from '#shared/api/registry'
import type { AnnouncementMessage, SystemNotifyItem } from '#shared/api/endpoints/messages'
import { resolveAnnouncementMessage, systemNotifyDetailKind } from '#shared/utils/messages'
import { useMessagesStore } from '~/stores/messages'

export const MESSAGES_PER_PAGE = 10
export const SYSTEM_NOTIFICATION_DETAIL_PATH = '/messages/system-notification-detail'

/**
 * 通知中心的 API：群發公告、平台公告（最新消息）、系統通知、站內信。
 * 每個端點一個函式回 ApiEnvelope；「成功後要做什麼」（全部已讀、未讀歸零、暫存選取）都在這裡。
 */
export function useMessages() {
  const api = useApi()
  const store = useMessagesStore()

  // 群發公告（大廳彈窗）

  async function fetchLatestAnnouncement() {
    const res = await api.post('announcement-message/latest', {})
    if (isApiOk(res)) store.announcement = resolveAnnouncementMessage<AnnouncementMessage>(res.data)
    store.announcementLoaded = true
    return res
  }

  /** 關閉彈窗時呼叫：先清掉本地狀態再送，回應不看。 */
  function readAnnouncement(announcementMessageId: number) {
    store.announcement = null
    return api.post('announcement-message/read', { announcement_message_id: announcementMessageId }, { silent: true })
  }

  // 平台公告（最新消息／活動消息）

  function listPlatformAnnouncements(page = 1, perPage = MESSAGES_PER_PAGE) {
    return api.post('platform-announcement/list', { page, per_page: perPage })
  }

  async function readAllPlatformAnnouncements() {
    const res = await api.post('platform-announcement/read-all', {}, { silent: true })
    store.platformAnnouncementUnread = 0
    return res
  }

  async function fetchPlatformAnnouncementUnread() {
    const res = await api.post('platform-announcement/unread', {}, { silent: true })
    if (isApiOk(res)) store.platformAnnouncementUnread = res.data.unread
    return res
  }

  /** 進分頁：第一頁抓成功就立刻全部已讀；換頁只重抓。 */
  async function loadPlatformAnnouncementsTab(page = 1) {
    const res = await listPlatformAnnouncements(page)
    if (page === 1 && isApiOk(res)) await readAllPlatformAnnouncements()
    return res
  }

  // 系統通知

  function listSystemNotifications(page = 1, perPage = MESSAGES_PER_PAGE) {
    return api.post('system-notify/list', { page, per_page: perPage })
  }

  async function readAllSystemNotifications() {
    const res = await api.post('system-notify/read-all', {}, { silent: true })
    store.systemNotificationUnread = 0
    return res
  }

  async function fetchSystemNotifyUnread() {
    const res = await api.post('system-notify/unread', {}, { silent: true })
    if (isApiOk(res)) store.systemNotificationUnread = res.data.unread
    return res
  }

  async function loadSystemNotificationsTab(page = 1) {
    const res = await listSystemNotifications(page)
    if (page === 1 && isApiOk(res)) await readAllSystemNotifications()
    return res
  }

  /** 只有傭金／轉帳兩類通知有詳情可打；其餘回 null，畫面只用列表項目渲染。 */
  async function fetchSystemNotifyDetail(
    item: SystemNotifyItem,
    page = 1,
    perPage = MESSAGES_PER_PAGE,
  ): Promise<ApiEnvelope<ResponseOf<'system-notify/detail'>> | null> {
    if (!systemNotifyDetailKind(item)) return null
    if (item.data_name !== 'transaction_logs' && item.data_name !== 'rolling_chip_logs') return null
    return api.post('system-notify/detail', {
      data_name: item.data_name,
      data_id: item.data_id,
      type: item.type,
      page,
      per_page: perPage,
    })
  }

  /** 列表點一筆：暫存在 store 再進詳情頁（網址不帶 id）。 */
  function selectSystemNotification(item: SystemNotifyItem) {
    store.selectedSystemNotification = item
    return navigateTo(SYSTEM_NOTIFICATION_DETAIL_PATH)
  }

  // 站內信

  function listMemberMails(page = 1, perPage = MESSAGES_PER_PAGE) {
    return api.post('member-mail/list', { page, per_page: perPage })
  }

  function readMemberMail(id: number) {
    return api.post('member-mail/read', { id })
  }

  async function readAllMemberMails() {
    const res = await api.post('member-mail/read-all', {})
    store.memberMailUnread = 0
    return res
  }

  async function fetchMemberMailUnread() {
    const res = await api.post('member-mail/unread', {}, { silent: true })
    if (isApiOk(res)) store.memberMailUnread = res.data.unread
    return res
  }

  async function loadMemberMailsTab(page = 1) {
    const res = await listMemberMails(page)
    if (page === 1 && isApiOk(res)) await readAllMemberMails()
    return res
  }

  // 鈴鐺

  /** 登入後一次抓三個未讀數；通知中心內不用叫（各分頁進入時自行歸零）。 */
  async function refreshUnread() {
    const { loggedIn } = useUserSession()
    if (!loggedIn.value) return
    await Promise.all([
      fetchPlatformAnnouncementUnread(),
      fetchSystemNotifyUnread(),
      fetchMemberMailUnread(),
    ])
  }

  return {
    fetchLatestAnnouncement,
    readAnnouncement,
    listPlatformAnnouncements,
    readAllPlatformAnnouncements,
    fetchPlatformAnnouncementUnread,
    loadPlatformAnnouncementsTab,
    listSystemNotifications,
    readAllSystemNotifications,
    fetchSystemNotifyUnread,
    loadSystemNotificationsTab,
    fetchSystemNotifyDetail,
    selectSystemNotification,
    listMemberMails,
    readMemberMail,
    readAllMemberMails,
    fetchMemberMailUnread,
    loadMemberMailsTab,
    refreshUnread,
  }
}
