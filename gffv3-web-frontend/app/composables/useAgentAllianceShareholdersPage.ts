/** /agent/alliance-shareholders 的邏輯，所有皮共用，畫面在 components/<皮>/Page/AgentAllianceShareholders.vue。 */
export function useAgentAllianceShareholdersPage() {
  /**
   * 聯盟股東介紹：shareholder/profit 顯示最近一次分潤日期與金額；
   * 申請按鈕依 apply_status（後端尚未回，缺失視為可申請）。
   */
  const { profit, applyButtonLabelKey, canApply, fetchProfit } = useShareholder()

  onMounted(async () => {
    await fetchProfit()
  })

  return {
    profit,
    applyButtonLabelKey,
    canApply,
  }
}
