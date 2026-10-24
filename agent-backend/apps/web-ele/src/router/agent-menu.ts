import type { RouteRecordStringComponent } from '@vben/types';

import type { CsMenuNode } from '#/api/cs';

import { useUserStore } from '@vben/stores';

import { getCustomizedSettingApi } from '#/api';
import { getCsMenuNodes, getCsRoleId, runCsBootstrap } from '#/api/cs';
import { requestClient } from '#/api/request';
import { tl } from '#/locales';

import { csWorkspaceRoute } from './cs-menu';

/** 動態選單。 */

interface AgentMenuNode {
  code: string;
  id: number;
  is_any_url_submenu?: boolean;
  is_url_menu: boolean;
  name: string;
  sort?: number;
  submenu?: AgentMenuNode[];
  symbol?: string;
  url: string;
}

/** 使用者選單裡出現過的所有 code（含 url 為空的純權限旗標節點）。 */
const menuCodes = new Set<string>();
/**
 * 使用者選單裡出現過的所有頁面 url（各層節點皆收）。
 * 對齊舊版 _auth.php：$check_auth 以各層 node 的 url 為鍵，
 * 隱藏子頁面（編輯/詳細）就靠它判斷父頁面權限是否存在。
 */
const menuUrls = new Set<string>();

/** 該使用者選單是否含此 code（權限旗標） */
export function hasMenuCode(code: string): boolean {
  return menuCodes.has(code);
}

/** 該使用者選單是否含此頁面 url（對齊舊版 isset($check_auth[url])） */
export function hasMenuUrl(url: string): boolean {
  return menuUrls.has(url);
}

function collectMenuCodes(nodes: AgentMenuNode[]) {
  for (const n of nodes) {
    if (n.code) menuCodes.add(n.code);
    const u = (n.url ?? '').trim();
    if (u) menuUrls.add(u);
    if (n.submenu) collectMenuCodes(n.submenu);
  }
}

/** 已搬遷的頁面（用來判斷該指真頁面或佔位頁） */
const pages = import.meta.glob('../views/**/*.vue');
function viewExists(url: string) {
  return Boolean(pages[`../views/${url}.vue`]);
}
const COMING_SOON = '/_core/fallback/coming-soon';

/** themify 圖示 → iconify(lucide) */
const ICON_MAP: Record<string, string> = {
  'ti-android': 'lucide:bot',
  'ti-announcement': 'lucide:megaphone',
  'ti-credit-card': 'lucide:credit-card',
  'ti-email': 'lucide:mail',
  'ti-envelope': 'lucide:mail',
  'ti-file': 'lucide:file-text',
  'ti-game': 'lucide:gamepad-2',
  'ti-harddrives': 'lucide:hard-drive',
  'ti-home': 'lucide:house',
  'ti-infinite': 'lucide:infinity',
  'ti-layout-grid4-alt': 'lucide:layout-grid',
  'ti-list': 'lucide:list',
  'ti-lock': 'lucide:lock',
  'ti-map-alt': 'lucide:map',
  'ti-marker-alt': 'lucide:map-pin',
  'ti-money': 'lucide:banknote',
  'ti-palette': 'lucide:palette',
  'ti-world': 'lucide:globe',
};

function mapIcon(symbol?: string): string | undefined {
  const s = (symbol ?? '').trim();
  if (!s) return undefined;
  return ICON_MAP[s] ?? 'lucide:circle-dot';
}

/**
 * 是否為「真實節點」：本身是頁面（有 url），或底下含真實頁面的群組。
 * 用來排除選單裡 url='' 且無頁面子項的「權限旗標」（如 member_list_flow_limit）。 */
/** 取乾淨的 url（後端可能回空白字串 ' '，需 trim 才不會被誤判成頁面） */
function cleanUrl(node: AgentMenuNode): string {
  return (node.url ?? '').trim();
}

function isRealNode(node: AgentMenuNode): boolean {
  if (cleanUrl(node)) return true;
  return (node.submenu ?? []).some((c) => isRealNode(c));
}

function transform(
  node: AgentMenuNode,
  usedNames: Set<string>,
  depth = 0,
): null | RouteRecordStringComponent {
  const url = cleanUrl(node);
  // 只保留含真實頁面的子節點（過濾掉純權限旗標）
  const realChildren = (node.submenu ?? []).filter((c) => isRealNode(c));
  const isGroup = realChildren.length > 0;

  // 既非群組又沒有 url → 純權限旗標，略過
  if (!isGroup && !url) return null;

  const title = tl(`menu_${node.code}`, node.name || node.code);
  // 只有一級選單顯示圖示；次級（含以下各層）一律不給，即使後端有回 symbol。
  const icon = depth === 0 ? mapIcon(node.symbol) : undefined;
  const order = node.sort ?? 0;

  // 確保 route name 唯一
  let name = node.code || `menu-${node.id}`;
  if (usedNames.has(name)) name = `${name}-${node.id}`;
  usedNames.add(name);

  if (isGroup) {
    return {
      name,
      path: `/${url || node.code}`,
      meta: { icon, order, title },
      children: realChildren
        .map((c) => transform(c, usedNames, depth + 1))
        .filter((r): r is RouteRecordStringComponent => r !== null),
    } as RouteRecordStringComponent;
  }

  // 頁面（葉節點）：未搬遷的導向 coming-soon 佔位
  return {
    name,
    path: `/${url}`,
    component: viewExists(url) ? `/${url}` : COMING_SOON,
    meta: { icon, keepAlive: true, order, title },
  };
}

/**
 * 不在選單裡、但要能用網址或連結進入的子頁（編輯 / 詳細 / 設定等）。
 * 進頁時 router guard 會檢查權限（見 guard.ts）：
 *   - requireUrl：父頁不是 /index 時，用它覆寫要檢查的 url。
 *   - public: true：完全不擋（操作自己的頁，如改自己手機 / 密碼、agent/edit），後端把關。
 */
const EXTRA_HIDDEN_PAGES: Array<{
  public?: boolean;
  requireUrl?: string;
  titleKey: string;
  url: string;
}> = [
  // 內部工具（舊版無 check_auth，登入即可用）
  { titleKey: 'excel_to_multilingual', url: 'internal-helper/excel_helper' },
  {
    requireUrl: 'bank_management/index',
    titleKey: 'bank_management',
    url: 'bank_management/bank_list',
  },
  {
    requireUrl: 'bank/index',
    titleKey: 'bank_management',
    url: 'bank/bank_list',
  },
  { titleKey: 'modify_mobile', url: 'agent/modify_mobile' },
  {
    titleKey: 'modify_agent_payment_password',
    url: 'agent/modify_agent_payment_password',
  },
  {
    titleKey: 'forget_agent_payment_password',
    url: 'agent/forget_agent_payment_password',
  },
  {
    requireUrl: 'agent/index',
    titleKey: 'menu_agent_other_setting',
    url: 'agent/personal_setting',
  },
  {
    titleKey: 'menu_agent_customized_setting',
    url: 'agent/agent_customized_setting',
  },
  {
    requireUrl: 'agent/group_list',
    titleKey: 'menu_agent_advance_setting',
    url: 'agent/agent_customized_setting_new',
  },
  {
    requireUrl: 'agent/index',
    titleKey: 'menu_agent_advance_setting',
    url: 'agent/advance_setting',
  },
  {
    // 工單4080：權限組別（角色）建立／編輯頁，另開分頁不用彈窗。
    // requireUrl 綁列表頁 permission_group/index，沒有列表權限者直接打網址也進不來。
    requireUrl: 'permission_group/index',
    titleKey: 'menu_permission_group_management',
    url: 'permission_group/edit',
  },
  {
    requireUrl: 'agent/index',
    titleKey: 'agent_group_permission',
    url: 'agent/agent_group_permission',
  },
  {
    requireUrl: 'agent/group_list',
    titleKey: 'menu_agent_advance_setting',
    url: 'agent/agent_sms_setting',
  },
  {
    titleKey: 'menu_agent_real_name_validate',
    url: 'agent/realname_validate/index',
  },
  { titleKey: 'menu_agent_advance_setting', url: 'agent/vendor_image_setting' },
  {
    requireUrl: 'agent/group_list',
    titleKey: 'menu_agent_advance_setting',
    url: 'agent/vendor_customized_items_setting',
  },
  {
    requireUrl: 'agent/group_list',
    titleKey: 'online_deposit_setting',
    url: 'agent/agent_online_deposit_setting',
  },
  // 首登強制改密/設付款密碼改用全域覆蓋層（layouts/forced-setup-overlay.vue），不走路由。
  { titleKey: 'bind_mobile', url: 'agent/bind_mobile' },
  { titleKey: 'detailinfo_edit', url: 'agent/edit' },
  // 後台通知頁（鈴鐺入口；最新消息 + 系統通知），常駐可達、不掛選單。
  { titleKey: 'menu_backend_notice', url: 'backend_notice/index' },
];

function extraHiddenRoutes(): RouteRecordStringComponent[] {
  // 一律註冊（只要頁面存在），權限改由 router guard 進頁時用 requireUrl 檢查（fail-closed）。
  return EXTRA_HIDDEN_PAGES.filter((p) => viewExists(p.url)).map((p) => ({
    name: p.url.replaceAll('/', '_'),
    path: `/${p.url}`,
    component: `/${p.url}`,
    meta: {
      hideInMenu: true,
      keepAlive: true,
      requireUrl: p.requireUrl,
      title: tl(p.titleKey, p.url),
    },
  }));
}

/** 首頁 */
function homeRoute(): RouteRecordStringComponent {
  return {
    name: 'Home',
    path: '/home',
    component: '/home/index',
    meta: {
      affixTab: true,
      icon: 'lucide:house',
      order: -100,
      title: tl('dashboard'),
    },
  };
}

/** CS 選單權限查詢的等待上限；超過就用 fallback，不讓路由生成卡住 */
const CS_BOOTSTRAP_TIMEOUT_MS = 3000;

export async function fetchAgentMenuList(): Promise<
  RouteRecordStringComponent[]
> {
  const userStore = useUserStore();
  // 每次重建選單前清空權限快取；放在 try 前，連失敗 fallback 也是乾淨狀態
  // （menuUrls 空 → 有 requireUrl 的隱藏頁全部不註冊，不會殘留舊權限）。
  menuCodes.clear();
  menuUrls.clear();
  let agentId = (userStore.userInfo as any)?.id;
  if (!agentId) {
    const me = await requestClient
      .get<Record<string, any>>('/me')
      .catch(() => null);
    agentId = me?.id;
  }

  // 選單 API 失敗（如 token 錯誤 9998）時，至少回首頁路由，
  // 避免整個 app 因路由生成拋錯而空白（9998 攔截器會再導回首頁）。
  let menuData: AgentMenuNode[];
  let settings: any[];
  try {
    [menuData, settings] = await Promise.all([
      requestClient.post<AgentMenuNode[]>(
        '/agent/api/agent-permission/menu-tree',
        {
          agent_id: agentId,
          is_exclude_tag_invisible: true,
        },
      ),
      getCustomizedSettingApi().catch(() => []),
    ]);
  } catch {
    return [homeRoute(), ...extraHiddenRoutes()];
  }

  // 跳過的選單
  const settingMap = new Map(
    (settings ?? []).map((s) => [Number(s.setting_key), s.value]),
  );
  const skipCodes = new Set<string>();
  if (Number(settingMap.get(5004)) === 0) {
    skipCodes.add('agent_self_deposit_withdrawal');
  }

  collectMenuCodes(menuData ?? []);

  const usedNames = new Set<string>(['Home']);
  const apiRoutes = (menuData ?? [])
    .filter((m) => !skipCodes.has(m.code))
    .map((m) => transform(m, usedNames))
    .filter((r): r is RouteRecordStringComponent => r !== null);

  // 客服系統選單（agent 設定 5404=1 才顯示）。
  const showCs = Number(settingMap.get(5404)) === 1;

  // 客服選單項目要對齊舊版邏輯：實際依 userMenu/list 回傳的權限決定顯示哪些項目
  // （而非固定顯示全部 8 項）。用 runCsBootstrap 沿用既有的重試機制，避免客服
  // 後端剛連線還沒就緒時被誤判成「沒有任何權限」；仍然失敗才 fallback 顯示全部，
  // 避免一次暫時性錯誤就讓整個客服工作區消失。
  //
  // 例外：客服標籤／標籤異動紀錄改由代理後台的權限設定管控（#4169），所以把
  // hasMenuCode 一併傳進去——那兩頁不吃上面這套 userMenu/list 的判斷與 fallback。
  // 另一個例外：快速回復訊息（#4485）依客服角色決定，取不到角色就隱藏，
  // 所以把 bootstrap 後的 csRoleId 一併傳進去（失敗／逾時維持 null）。
  let csNodes: CsMenuNode[] | null = null;
  let csRoleId: null | number = null;
  if (showCs) {
    try {
      await Promise.race([
        runCsBootstrap(async () => {}),
        new Promise((_, reject) => {
          setTimeout(
            () => reject(new Error('cs bootstrap timeout')),
            CS_BOOTSTRAP_TIMEOUT_MS,
          );
        }),
      ]);
      csNodes = getCsMenuNodes();
      csRoleId = getCsRoleId();
    } catch {
      csNodes = null;
    }
  }

  return [
    homeRoute(),
    ...apiRoutes,
    ...extraHiddenRoutes(),
    ...(showCs ? [csWorkspaceRoute(csNodes, hasMenuCode, csRoleId)] : []),
  ];
}
