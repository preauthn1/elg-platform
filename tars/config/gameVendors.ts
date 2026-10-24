/**
 * 要跑下注測試的遊戲商清單。
 *
 * 新增一家：在這裡加一個 export，再到 `tests/dangerous/` 開一支三行的 spec
 * （複製 `game_bet_pxplay.spec.ts` 改一個名字即可）。
 *
 * 轉動鈕位置是「相對視窗的比例」，在 1280×720 實測校準的——老虎機畫面是遊戲商用
 * canvas 畫的，沒有元素可以定位，只能按比例點（詳見 pages/frontend/games/SlotGame.ts）。
 * 遊戲改版或換遊戲，就要重新校準這兩個數字。
 */
export interface GameVendor {
  /** 遊戲商編號（後台 SR12 的「平臺」就是用這個編號，例如 Pxplay SW = 236） */
  id: number;
  /** 遊戲商名稱（前台遊戲列表頁與後台報表顯示的名稱） */
  name: string;
  /** 要玩哪一款遊戲（前台遊戲卡片上的名稱） */
  gameName: string;
  /** 測試標籤，UI 模式與指令都可以用它單獨挑這家跑（例如 @pxplay） */
  tag: string;
  /** 轉動鈕相對視窗的位置（1280×720 校準） */
  spinButton: { xRatio: number; yRatio: number };
}

export const PXPLAY_SW: GameVendor = {
  id: 236,
  name: 'Pxplay SW',
  gameName: 'Jokers Jewels',
  tag: '@pxplay',
  spinButton: { xRatio: 0.773, yRatio: 0.903 },
};
