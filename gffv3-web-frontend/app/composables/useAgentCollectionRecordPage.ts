/** /agent/collection-record 的邏輯，所有皮共用，畫面在 components/<皮>/Page/AgentCollectionRecord.vue。 */
export function useAgentCollectionRecordPage() {
  /** 代理獎勵領取紀錄：依模式打 withdrawal/history 或 withdrawals/histories，只顯示日期／金額／狀態。 */
  const { history, historyPage, fetchHistory } = useAgentWithdrawal()

  async function load(page = 1) {
    await fetchHistory(page, historyPage.value.perPage)
  }

  onMounted(() => load(1))

  return {
    history,
    historyPage,
    load,
  }
}
