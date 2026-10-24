import { z } from 'zod'
import { defineEndpoint, type EndpointMap } from '../define'
import { flagSchema } from '#shared/types/site'
import { paginatedSchema } from '#shared/types/api'
import { SHAREHOLDER_USERNAME_PATTERN } from '#shared/utils/agent'

/** PaginationRequest：page / per_page 皆必填 integer min 1。 */
const paginationRequest = z.object({
  page: z.coerce.number().int().min(1),
  per_page: z.coerce.number().int().min(1),
})

/** 只吃 domain（BFF 自動補）或完全沒有參數的端點。 */
const emptyRequest = z.object({})

/** 金額類欄位後端有時回 2 位小數字串、有時回數字，前端顯示前用 toNumber() 轉。 */
const amount = z.union([z.string(), z.number()])
const count = z.coerce.number()

/** unlimit-hierarchy/withdrawal/create 與 three-hierarchy/withdrawals/create 同一條 rule。 */
const withdrawalCreateRequest = z.object({
  amount: z.coerce.number().min(0.01),
})

const withdrawalCreateResponse = z.looseObject({
  balance: amount,
})

const withdrawalHistoryItem = z.looseObject({
  date: z.string(),
  no: z.coerce.number(),
  amount,
  type: z.string(),
  withdrawType: z.string(),
  withdrawAccount: z.unknown().optional(),
})

const hierarchyLinkFields = {
  topUsername: z.string(),
  username: z.string(),
  directPlayerCount: count,
  addedToday: count,
  activeToday: count,
  code: z.coerce.string(),
  link: z.string(),
}

export const agentHierarchyEndpoints = {
  // ---- 無限級（agent_mode_setting == 1） ---------------------------------------------------------
  'unlimit-hierarchy': defineEndpoint({
    path: 'unlimit-hierarchy',
    auth: 'member',
    description: '無限級代理總覽：上級／團隊人數／今日新增／邀請碼與推薦連結',
    request: emptyRequest,
    response: z.looseObject({
      ...hierarchyLinkFields,
      teamCount: count,
    }),
  }),

  'unlimit-hierarchy/list': defineEndpoint({
    path: 'unlimit-hierarchy/list',
    auth: 'member',
    description: '無限級規則層級表（unlimit_system_rates）',
    request: emptyRequest,
    response: z.array(z.looseObject({
      level: count,
      minAmount: amount,
      maxAmount: amount.nullable().optional(),
      bounsAmount: amount,
      rebateRate: amount,
    })),
  }),

  'unlimit-hierarchy/direct-players': defineEndpoint({
    path: 'unlimit-hierarchy/direct-players',
    auth: 'member',
    description: '我的玩家（直屬）分頁',
    request: paginationRequest,
    response: paginatedSchema(z.looseObject({
      member_id: count,
      member: z.string(),
      teamContribution: amount,
      memberContribution: amount,
      teamCount: count,
      memberCount: count,
      memberType: count,
    })),
  }),

  'unlimit-hierarchy/reward': defineEndpoint({
    path: 'unlimit-hierarchy/reward',
    auth: 'member',
    description: '我的業績／獎勵紀錄分頁',
    request: paginationRequest,
    response: paginatedSchema(z.looseObject({
      date: z.string(),
      totalContribution: amount,
      teamContribution: amount,
      memberContribution: amount,
      reward: amount,
      flow: amount,
      itself_reward: amount,
      type: z.string(),
      memo: z.string(),
    })),
  }),

  'unlimit-hierarchy/withdrawal/history': defineEndpoint({
    path: 'unlimit-hierarchy/withdrawal/history',
    auth: 'member',
    description: '無限級提領紀錄分頁',
    request: paginationRequest,
    response: paginatedSchema(withdrawalHistoryItem),
  }),

  'unlimit-hierarchy/withdrawal/create': defineEndpoint({
    path: 'unlimit-hierarchy/withdrawal/create',
    auth: 'member',
    description: '無限級申請提領；1934 鎖分中、1010 無獎勵紀錄、1022 超過可提領',
    request: withdrawalCreateRequest,
    response: withdrawalCreateResponse,
  }),

  'unlimit-hierarchy/withdrawal/record': defineEndpoint({
    path: 'unlimit-hierarchy/withdrawal/record',
    auth: 'member',
    description: '無限級提領總覽；Controller 套 PaginationRequest 但回應不是分頁，送 page=1、per_page=1 即可',
    request: z.object({
      page: z.coerce.number().int().min(1).default(1),
      per_page: z.coerce.number().int().min(1).default(1),
    }),
    response: z.looseObject({
      username: z.string(),
      totalRewardPoints: amount,
      withdrawnPoints: amount,
      withdrawablePoints: amount,
      flowAmount: amount,
      itselfWithdrawnPoints: amount,
      itselfWithdrawablePoints: amount,
    }),
  }),

  'unlimit-hierarchy/flow-amount': defineEndpoint({
    path: 'unlimit-hierarchy/flow-amount',
    auth: 'member',
    description: '今日有效流水（新版包在 performance 欄位）',
    request: emptyRequest,
    response: z.looseObject({
      performance: amount,
    }),
  }),

  // ---- 三級分銷（agent_mode_setting ∈ {2,3,4}） ---------------------------------------------------
  'three-hierarchy': defineEndpoint({
    path: 'three-hierarchy',
    auth: 'member',
    description: '三級分銷代理總覽：直屬／下兩層人數／今日新增／邀請碼與推薦連結',
    request: emptyRequest,
    response: z.looseObject({
      ...hierarchyLinkFields,
      doubleDirectPlayerCount: count,
    }),
  }),

  'three-hierarchy/achievements': defineEndpoint({
    path: 'three-hierarchy/achievements',
    auth: 'member',
    description: '我的業績（最近一期結算日）分頁',
    request: paginationRequest,
    response: paginatedSchema(z.looseObject({
      date: z.string(),
      rebate_type: count,
      username: z.string(),
      vendor: z.string(),
      game_type: z.string(),
      bet: amount,
      reward: amount,
    })),
  }),

  'three-hierarchy/rebates': defineEndpoint({
    path: 'three-hierarchy/rebates',
    auth: 'member',
    description: '我的返利（三層投注／輸分返利）分頁',
    request: paginationRequest,
    response: paginatedSchema(z.looseObject({
      date: z.string(),
      rebate_1: amount,
      rebate_2: amount,
      rebate_3: amount,
      lose_rebate_1: amount,
      lose_rebate_2: amount,
      lose_rebate_3: amount,
      reward: amount,
    })),
  }),

  'three-hierarchy/rule': defineEndpoint({
    path: 'three-hierarchy/rule',
    auth: 'member',
    description: '三級分銷返利規則（依遊戲類型／場館的三層比例）',
    request: emptyRequest,
    response: z.looseObject({
      items: z.array(z.looseObject({
        vendor_id: count,
        vendor_name: z.string(),
        vendor_image: z.string().nullable().catch(''),
        game_type_name: z.string(),
        game_type_id: count,
        type: count,
        proportion_1: amount,
        proportion_2: amount,
        proportion_3: amount,
      })),
    }),
  }),

  'three-hierarchy/withdrawals': defineEndpoint({
    path: 'three-hierarchy/withdrawals',
    auth: 'member',
    description: '三級分銷提領總覽（沒有 flowAmount / itself* 欄位）',
    request: emptyRequest,
    response: z.looseObject({
      username: z.string(),
      totalRewardPoints: amount,
      withdrawnPoints: amount,
      withdrawablePoints: amount,
    }),
  }),

  'three-hierarchy/withdrawals/histories': defineEndpoint({
    path: 'three-hierarchy/withdrawals/histories',
    auth: 'member',
    description: '三級分銷提領紀錄分頁',
    request: paginationRequest,
    response: paginatedSchema(withdrawalHistoryItem),
  }),

  'three-hierarchy/withdrawals/create': defineEndpoint({
    path: 'three-hierarchy/withdrawals/create',
    auth: 'member',
    description: '三級分銷申請提領；1934 鎖分中、1022 無結算紀錄或超過可提領、2001 更新失敗',
    request: withdrawalCreateRequest,
    response: withdrawalCreateResponse,
  }),

  // ---- 會員團隊（不分模式） ---------------------------------------------------------------------------
  'member-team/info': defineEndpoint({
    path: 'member-team/info',
    auth: 'member',
    description: '團隊基本資訊：直屬／團隊人數、本期傭金與派發狀態、邀請碼與推薦連結',
    request: emptyRequest,
    response: z.looseObject({
      directPlayerCount: count,
      addedToday: count,
      addedTodayGroupMember: count,
      totalGroupMember: count,
      todayCommission: amount,
      is_payout: flagSchema,
      code: z.coerce.string(),
      link: z.string(),
    }),
  }),

  'member-team/achievement': defineEndpoint({
    path: 'member-team/achievement',
    auth: 'member',
    description: '業績查詢：type 1 最近一期（昨日）、2 前一期（前天）',
    request: z.object({
      type: z.union([z.literal(1), z.literal(2)]).optional(),
    }),
    response: z.looseObject({
      data: z.array(z.looseObject({
        name: z.string(),
        totalAchievement: amount,
        directPlayersAchievement: amount,
        teamAchievement: amount,
      })),
      total: z.looseObject({
        totalAchievement: amount,
        directPlayersAchievement: amount,
        teamAchievement: amount,
      }),
    }),
  }),

  'member-team/team-management': defineEndpoint({
    path: 'member-team/team-management',
    auth: 'member',
    description: '團隊管理：查自己的直屬會員最近一期業績；查不到回 []',
    request: z.object({
      username: z.string().min(4).max(30),
    }),
    response: z.union([
      z.looseObject({
        username: z.string(),
        achievements: amount,
      }),
      z.array(z.unknown()),
    ]),
  }),

  'member-team/agent-achievement': defineEndpoint({
    path: 'member-team/agent-achievement',
    auth: 'member',
    description: '代理報表：本期直屬／團隊／總業績、上期傭金、歷史總傭金',
    request: emptyRequest,
    response: z.looseObject({
      directPlayersAchievement: amount,
      teamAchievement: amount,
      totalAchievement: amount,
      lastpPeriodCommission: amount,
      historicalTotalCommission: amount,
    }),
  }),

  // ---- 股東 -----------------------------------------------------------------------------------
  'shareholder/profit': defineEndpoint({
    path: 'shareholder/profit',
    auth: 'optional',
    description: '最近一次全站股東分潤日期與金額；apply_status 後端尚未提供',
    request: emptyRequest,
    response: z.looseObject({
      profit_time: z.string(),
      total_profit: amount,
      apply_status: z.coerce.number().optional(),
    }),
  }),

  'shareholder/apply': defineEndpoint({
    path: 'shareholder/apply',
    auth: 'member',
    description: '會員申請成為代理（股東）；1852 未開放／已申請、1851 上級為會員申請而來、1853 上級股份不足',
    request: z.object({
      username: z.string().min(4).max(20).regex(SHAREHOLDER_USERNAME_PATTERN),
      password: [REDACTED],
      password_confirmation: z.string(),
    }).refine(form => form.password === form.password_confirmation, {
      path: ['password_confirmation'],
      message: 'password confirmation mismatch',
    }),
    response: z.unknown(),
  }),

  // ---- 場館有效投注倍率（無限級規則頁） ---------------------------------------------------------------------
  'vendor/rate-list': defineEndpoint({
    path: 'vendor/rate-list',
    auth: 'member',
    description: '各場館有效投注倍率 unlimit_rate（vendor_images 客製優先）',
    request: emptyRequest,
    response: z.array(z.looseObject({
      id: count,
      name: z.string(),
      unlimit_rate: amount,
    })),
  }),
} satisfies EndpointMap
