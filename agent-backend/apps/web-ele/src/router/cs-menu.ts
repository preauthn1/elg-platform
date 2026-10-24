import type { RouteRecordStringComponent } from '@vben/types';

import type { CsMenuNode } from '#/api/cs';

import { CS_TEMPLATE_ROLE_IDS, CS_TRANSACTION_LOG_ROLE_IDS } from '#/api/cs';
import { tl } from '#/locales';

/** 客服系統選單群組（agent 設定 5404=1 時由 agent-menu 併入）。 */

const csPages = import.meta.glob('../views/cs/**/*.vue');
const COMING_SOON = '/_core/fallback/coming-soon';

function csView(url: string): string {
  const clean = url.replace(/^\/+/, '');
  return csPages[`../views/cs/${clean}.vue`] ? `/cs/${clean}` : COMING_SOON;
}

// nameKey 為 legacy 語言 key，由 csWorkspaceRoute 以 tl() 解析（與父節點 tl('cs') 一致）
// 次級選單一律不帶圖示（只有一級「客服工作區」有），與 agent-menu 的規則一致。
const CS_NODES: Array<{
  code: string;
  nameKey: string;
  url: string;
}> = [
  { code: 'index', nameKey: 'menu.index', url: 'index' },
  { code: 'agent', nameKey: 'cs.vendor_mgmt', url: 'agent' },
  { code: 'user', nameKey: 'cs.user_list', url: 'user' },
  {
    code: 'customer_service',
    nameKey: 'cs.cs_staff_mgmt',
    url: 'customer_service',
  },
  { code: 'chat', nameKey: 'menu.chat', url: 'chat' },
  // #4485 快速回復訊息管理頁（可見性見 CS_ROLE_GATED）
  {
    code: 'message_template',
    nameKey: 'menu.message_template',
    url: 'message_template',
  },
  // #4169 標籤管理／標籤異動紀錄：code 需與 CS 後端 userMenu/list 的節點一致
  { code: 'tag', nameKey: 'menu.cs_tag', url: 'tag/index' },
  { code: 'tag_log', nameKey: 'menu.cs_tag_log', url: 'tag/log' },
  {
    code: 'transaction_log',
    nameKey: 'menu.transaction_log',
    url: 'transaction/list',
  },
  { code: 'sr02', nameKey: 'menu.sr02', url: 'report/sr02' },
  { code: 'setup', nameKey: 'menu.setup', url: 'setup' },
];

/**
 * 可見性改由「代理後台 > 權限設定」管控的客服頁（#4169）。
 *
 * key 是 CS_NODES 的 code，值是 agent_menus 的權限旗標 code（apis
 * database/data/agent_menus.php，url 留空、掛在下線代理管理底下，比照
 * cs_downline_balance_visible）。列在這裡的頁面完全不看客服後端 userMenu/list：
 * 側欄與頁面權限都以代理後台勾選的結果為準，客服後端逾時的 fallback 也不放行。
 */
const AGENT_PERMISSION_CODES: Record<string, string> = {
  tag: 'cs_tag',
  tag_log: 'cs_tag_log',
};

/**
 * 依「客服角色」（CS 登入者的 role_id）決定可見性的客服頁（target/tickets#4485）。
 *
 * key 是 CS_NODES 的 code，值是允許看到該頁的客服角色。列在這裡的頁面
 * 完全不看客服後端 userMenu/list；取不到角色（csRoleId 為 null）時一律隱藏，
 * 也不套用客服後端逾時時「保守顯示全部」的 fallback。
 *
 * transaction_log 同時列在 ALWAYS_ROUTED_CODES：角色不符或取不到角色時仍註冊路由、只隱藏側欄（#4487）。
 */
const CS_ROLE_GATED: Record<string, readonly number[]> = {
  message_template: CS_TEMPLATE_ROLE_IDS,
  transaction_log: CS_TRANSACTION_LOG_ROLE_IDS,
};

/**
 * 沒有選單權限也要註冊路由的客服頁（target/tickets#4487）。
 *
 * 比照舊版 WEB_CustomerServiceBackend：路由寫死、選單權限只管側欄。
 * 「紅包／開洗分」彈窗（views/cs/components/transfer-dialog.vue 的 goToRecords）
 * 會 window.open('/cs/transaction/list?username=…') 深連結到紀錄查詢。
 * 側欄顯示與否改由 CS_ROLE_GATED 的角色決定（#4487 追加），不看 userMenu/list；
 * 角色不符或取不到角色時仍註冊路由、只隱藏側欄，讓彈窗深連結開得起來。
 * 列在這裡的頁面沒權限時仍註冊路由，只以 hideInMenu 從側欄隱藏；
 * 只套 transaction_log，其他頁維持「沒權限不註冊」。
 */
const ALWAYS_ROUTED_CODES = new Set(['transaction_log']);

/**
 * 客服選單項目要對齊舊版 WEB_CustomerServiceBackend：舊版整個左側選單就是
 * userMenu/list 回傳了什麼就顯示什麼（AppDrawer.vue 直接吃 Vuex getMenu，
 * 沒有另外寫死清單）。這裡沿用 CS_NODES 只是為了保留圖示/翻譯 key/元件對應，
 * 實際「顯示哪些項目」一律以 nodes 的 code 為準。
 *
 * nodes 傳 null 代表這次沒能取得權限資料（例如客服後端逾時／失敗）——
 * 這種情況保守顯示全部，避免因為一次暫時性錯誤就把整個客服工作區關掉；
 * 傳實際陣列（即使是空陣列）就照實際權限過濾，跟舊版行為一致。
 * 'index'（客服首頁）不受權限清單影響，永遠顯示，比照 agent 選單的 homeRoute() 慣例。
 *
 * AGENT_PERMISSION_CODES 列到的頁面是例外：改吃代理後台的選單權限
 * （hasAgentMenuCode），不受 userMenu/list 與上述 fallback 影響。
 *
 * CS_ROLE_GATED 列到的頁面也是例外（#4485）：只看客服角色 csRoleId 是否在
 * 允許清單內；csRoleId 為 null（取不到角色、客服後端逾時）時隱藏，不套用 fallback。
 *
 * ALWAYS_ROUTED_CODES 列到的頁面另有例外：不顯示在側欄時仍註冊路由
 * （meta.hideInMenu），讓彈窗深連結開得起來；顯示與否的判斷不變。
 */
export function csWorkspaceRoute(
  nodes: CsMenuNode[] | null,
  hasAgentMenuCode: (code: string) => boolean,
  csRoleId: null | number,
): RouteRecordStringComponent {
  const csCodes = nodes === null ? null : new Set(nodes.map((n) => n.code));
  const isVisibleInMenu = (code: string): boolean => {
    const roles = CS_ROLE_GATED[code];
    if (roles) return csRoleId !== null && roles.includes(csRoleId);
    const agentCode = AGENT_PERMISSION_CODES[code];
    if (agentCode) return hasAgentMenuCode(agentCode);
    if (code === 'index') return true;
    return csCodes === null || csCodes.has(code);
  };
  const children: RouteRecordStringComponent[] = [];
  for (const n of CS_NODES) {
    const visible = isVisibleInMenu(n.code);
    if (!visible && !ALWAYS_ROUTED_CODES.has(n.code)) continue;
    children.push({
      name: `cs_${n.code}`,
      path: `/cs/${n.url}`,
      component: csView(n.url),
      meta: {
        keepAlive: true,
        title: tl(n.nameKey),
        // 沒權限但列在 ALWAYS_ROUTED_CODES：路由照註冊，只從側欄隱藏
        ...(visible ? {} : { hideInMenu: true }),
      },
    });
  }
  return {
    name: 'CsSystem',
    path: '/cs',
    meta: { icon: 'lucide:headset', order: 9000, title: tl('cs') },
    children,
  } as RouteRecordStringComponent;
}
