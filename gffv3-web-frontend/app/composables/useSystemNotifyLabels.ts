import type { SystemNotifyItem } from '#shared/api/endpoints/messages'
import {
  type NotifyLabel,
  systemNotifyContent,
  systemNotifyCurrencySetting,
  systemNotifyStatusKey,
  systemNotifyTagKey,
  systemNotifyTypeLabel,
} from '#shared/utils/messages'

/** 系統通知的顯示文字：把純函式回的描述子用 i18n 與站台設定解成字串。 */
export function useSystemNotifyLabels() {
  const { t } = useI18n()
  const { site, settings } = useSite()

  function currencyName(item: Pick<SystemNotifyItem, 'data_type'>): string {
    return settings.value?.[systemNotifyCurrencySetting(item)] ?? ''
  }

  function resolveLabel(label: NotifyLabel): string {
    if (!label) return ''
    switch (label.kind) {
      case 'i18n':
        return t(label.key)
      case 'text':
        return label.text
      case 'setting': {
        const value = site.value?.raw[label.key]
        return typeof value === 'string' ? value : ''
      }
    }
  }

  function tagName(item: SystemNotifyItem): string {
    const key = systemNotifyTagKey(item)
    return key ? t(key) : currencyName(item)
  }

  function typeName(item: SystemNotifyItem): string {
    return resolveLabel(systemNotifyTypeLabel(item))
  }

  function contentText(item: SystemNotifyItem): string {
    return systemNotifyContent(item, currencyName(item))
  }

  function statusText(item: Pick<SystemNotifyItem, 'status'>): string {
    const key = systemNotifyStatusKey(item.status)
    return key ? t(key) : ''
  }

  return { currencyName, resolveLabel, tagName, typeName, contentText, statusText }
}
