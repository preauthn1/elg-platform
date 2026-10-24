import { ref } from 'vue';

import { getMemberDrawerAccessApi } from '#/api/member-detail';

/**
 * 會員詳情抽屜的全域開啟狀態（4274）。
 *
 * 抽屜單一實例掛在主版面（layouts/basic.vue），全站 130 個頁面經
 * MemberAccountLink 呼叫 openMemberDetail() 開啟——避免逐頁掛載抽屜。
 * 比照 api/cs.ts setPendingChatUsername 的模組級狀態模式。
 *
 * memberId／username 擇一：只有帳號字串的入口（聊天表頭、部分報表列）
 * 以 username 開啟，由 view 接口解析出 member_id 後再載入其餘區塊。
 */
export interface MemberDetailTarget {
  memberId?: number;
  /** 每次點擊遞增，確保連點同一會員也會觸發 watch（抽屜內容直接切換，B-T2） */
  seq: number;
  username?: string;
}

let seq = 0;

export const memberDetailTarget = ref<MemberDetailTarget | null>(null);

export function openMemberDetail(target: {
  memberId?: null | number | string;
  username?: null | string;
}) {
  const id = Number(target.memberId ?? 0);
  const username = String(target.username ?? '');
  if (!id && !username) return;
  seq += 1;
  memberDetailTarget.value = {
    memberId: id || undefined,
    seq,
    username: username || undefined,
  };
}

/**
 * 刷新訊號：其他頁面改了會員狀態（如實名驗證審核頁審完）呼叫 refreshMemberDetail()，
 * 抽屜若開著就重抓卡片＋基本資料，畫面與資料同步。以遞增序號觸發 watch，
 * 不帶會員 id——抽屜只會顯示一位會員，刷新當前這位即可。
 */
export const memberDetailRefreshSeq = ref(0);

export function refreshMemberDetail() {
  memberDetailRefreshSeq.value += 1;
}

/**
 * 反方向的刷新訊號（4438）：抽屜裡改了會員資料（狀態切換、開洗分送出）呼叫 notifyMemberChanged()，
 * 開著的列表頁（帳號管理）收到就重抓，抽屜與列表才不會顯示不同步。
 * 抽屜收到 refreshMemberDetail() 時只重抓自己、不再呼叫本函式，避免兩邊來回觸發。
 */
export const memberChangedSeq = ref(0);

export function notifyMemberChanged() {
  memberChangedSeq.value += 1;
}

/**
 * 登入者能不能用抽屜（4438 第 7 項）：由後端判斷（系統管理者、總代理含子帳號、客服），
 * 全站共用一份、第一次用到時載入一次；載入前視為不可用（會員帳號先以純文字呈現，拿到結果再變可點）。
 */
export const memberDrawerAllowed = ref(false);
let accessLoading: null | Promise<void> = null;

export function ensureMemberDrawerAccess(): Promise<void> {
  if (!accessLoading) {
    accessLoading = getMemberDrawerAccessApi()
      .then((res) => {
        memberDrawerAllowed.value = Boolean(res?.can_use_drawer);
      })
      .catch(() => {
        // 查不到就維持不可用；下次再試
        accessLoading = null;
      });
  }
  return accessLoading;
}

/** 登出或換帳號時清掉，下一個登入者重新判斷 */
export function resetMemberDrawerAccess() {
  memberDrawerAllowed.value = false;
  accessLoading = null;
}
