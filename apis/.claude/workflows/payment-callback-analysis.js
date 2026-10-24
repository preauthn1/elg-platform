export const meta = {
  name: 'payment-callback-analysis',
  description: '產生金流商盤點報告「回調邏輯」一節的分析資料（需 LLM 判讀的部分）。provider 清單由呼叫端以 args 傳入（report:payment-providers:stale --json，全量加 --all）；回傳 providers map，由呼叫端寫入 docs/reports/data/payment-callback-analysis.json，再由 `php artisan report:payment-providers` 渲染成 HTML（金流商清單、白標使用對照、文檔缺口由 artisan 即時計算，不在本 workflow）。風險判讀不在這裡做，由 report-payment-audit skill 的風險發現資料檔負責。',
  phases: [
    { title: 'Analyze', detail: '每金流商一個 agent 萃取回調觸發條件與狀態判定風格' },
  ],
}

const FRAMEWORK = `
共用框架語意（已讀過 traits，作為背景；agent 聚焦該金流商「覆寫/特有」的部分）：
【代收 DepositCallbackTrait】checkDepositOrderStatus 只允許 status=CREATED(0) 被改；終態→DepositOrderException(200)→回成功響應止重試；查無訂單 400→失敗響應。updateDepositOrderSuccess 設 SUCCESS，金額不符(numCompare 2位)→WAITING_MANUAL_DEPOSIT+BotNotify；updateDepositOrderFailed 設 FAILED。預設 notifySuccessText='success'/notifyFailText='fail'。
【代付 WithdrawalCallbackTrait】isPayoutRecordProcessed 只認 THIRD_PARTY_SUCCESS(24)；THIRD_PARTY_FAILED(29) 非終態可補發/重試。updateWithdrawalOrderStatus 收三態 WithdrawalCallbackOutcome：SUCCESS→24、FAILURE→29、INDETERMINATE→不改狀態只記 log；傳入 payoutRecord 時，非最新 record 的回調一律拒絕改寫訂單狀態並 BotNotify。validateCallbackAmount 僅成功時驗、不符只 warning。
`

const PROVIDER_SCHEMA = {
  type: 'object', additionalProperties: false,
  required: ['provider', 'inventoryNote', 'response', 'depositSuccessTrigger', 'withdrawalSuccessTrigger', 'statusStyle'],
  properties: {
    provider: { type: 'string' },
    inventoryNote: { type: 'string', description: "§1 備註短技術描述（不含『代收限定』前綴，那由 command 自動加）。例 'AES 對稱解密'、'RSA 簽名'、'雙簽名 CheckString'、'無驗簽'、'MajesticPay 薄包裝'、'無 Trait 檔'、'StatusCodeEnum'；無則空字串" },
    response: { type: 'string', description: "回應字串『成功 / 失敗』，短，例 'OK / FAIL'、'success / fail'、'{error_code,message}'、'JSON {success:bool}'" },
    depositSuccessTrigger: { type: 'string', description: "代收『成功』觸發，短，例 \"status==='Completed'\"；特殊行為加註如 '驗簽過即成功（不讀 code）'" },
    withdrawalSuccessTrigger: { type: 'string', description: "代付『成功』觸發；無 WithdrawalCallback.php 填 '—'" },
    statusStyle: { type: 'string', description: "狀態判定風格，短，例 '二分（非成功即失敗）'、'Enum + default 失敗'、'代收三態（未知→拋例外）'、'白名單（RSA）'；可加註 '繞過框架'、'代收無失敗分支'" },
  },
}

// 分析清單一律由呼叫端傳入：增量用 `php artisan report:payment-providers:stale --json`，
// 全量用 `--json --all`。兩者都由 PaymentCallbackAnalysisCache::configProviderNames() 解析 config/payment.php。
// args 可為陣列或逗號字串。
const TO_ANALYZE = [...new Set(
  Array.isArray(args)
    ? args
    : (typeof args === 'string' ? args.split(',') : [])
)].map((s) => String(s).trim()).filter(Boolean).sort()

if (TO_ANALYZE.length === 0) {
  throw new Error('未傳入金流商清單：增量用 report:payment-providers:stale --json，全量用 --json --all 的輸出當 args')
}
log(`本次分析 ${TO_ANALYZE.length} 家：${TO_ANALYZE.join(', ')}`)

phase('Analyze')

const results = await parallel(TO_ANALYZE.map((name) => () => agent(
  `你是金流回調稽核員。分析 "${name}"（目錄 app/Services/Payment/Providers/${name}/）並回填 schema 欄位。

步驟：
1. ls/glob 該目錄所有 *Callback*.php（可能多支，例 BigPayz 有 DuitNow/Payin）。
2. 完整讀每個 *Callback*.php、該金流商 *Trait.php、任何 *Status*Enum.php。
3. 填短欄位：response（成功/失敗回應字串）、代收/代付成功觸發、statusStyle、inventoryNote（短技術描述）。不做風險判讀。

${FRAMEWORK}

無 WithdrawalCallback.php 則 withdrawalSuccessTrigger 填 '—'。只根據實際讀到的程式碼。`,
  { label: name, phase: 'Analyze', schema: PROVIDER_SCHEMA, model: 'opus' }
)))

const analyzed = results.filter(Boolean)
// 完整性守衛：任一 agent 失敗即中止（避免殘缺資料覆蓋既有 JSON）
if (analyzed.length !== TO_ANALYZE.length) {
  const got = new Set(analyzed.map((p) => p.provider))
  throw new Error(`資料不完整：${analyzed.length}/${TO_ANALYZE.length}，缺 [${TO_ANALYZE.filter((n) => !got.has(n)).join(', ')}]`)
}

// 組成 docs/reports/data/payment-callback-analysis.json 的形狀（generatedAt 由呼叫端寫入時戳記）
const providers = {}
for (const p of analyzed.sort((a, b) => a.provider.localeCompare(b.provider))) {
  providers[p.provider] = {
    inventoryNote: p.inventoryNote,
    response: p.response,
    depositSuccessTrigger: p.depositSuccessTrigger,
    withdrawalSuccessTrigger: p.withdrawalSuccessTrigger,
    statusStyle: p.statusStyle,
  }
}

log(`Analyze 完成：${Object.keys(providers).length} 家。呼叫端：將本結果寫成 fresh.json → php artisan report:payment-providers:merge --input=fresh.json（套用+蓋章 hash）→ php artisan report:payment-providers`)

return {
  source: 'workflow:payment-callback-analysis',
  count: Object.keys(providers).length,
  providers,
}
