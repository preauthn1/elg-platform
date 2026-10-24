/**
 * 指定存款方式（/deposit/:id）：網址的 :id 是要預選的 order_type_id，進頁先擋進行中的活動。
 * 所有皮共用，畫面在 components/<皮>/Page/Deposit.vue。
 */
export function useDepositEntry() {
  const route = useRoute()
  const { hasActiveEvent } = useDepositEventGuard()

  /** 不是正整數就當沒帶。 */
  const preselect = computed(() => {
    const n = Number(route.params.id)
    return Number.isFinite(n) && n > 0 ? n : undefined
  })
  const checked = ref(false)
  const blocked = ref(false)

  onMounted(async () => {
    blocked.value = await hasActiveEvent()
    checked.value = true
  })

  return { preselect, checked, blocked }
}
