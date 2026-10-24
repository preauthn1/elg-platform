/** /promotions 的邏輯，所有皮共用，畫面在 components/<皮>/Page/Promotions.vue。 */
export async function usePromotionsPage() {
  const { listEvents, events, eventPath } = useEvents()

  const dataReady = useAsyncData('promotions-list', async () => {
    await listEvents()
    return events.value
  }, { default: () => [] })
  const { data: items } = dataReady

  // 資料在 SSR 就要抓好：上面已經開始抓，這裡才等（await 之後不能再呼叫 composable）
  await dataReady

  return {
    eventPath,
    items,
  }
}
