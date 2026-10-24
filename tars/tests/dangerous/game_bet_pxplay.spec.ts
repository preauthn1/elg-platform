import { PXPLAY_SW } from '../../config/gameVendors';
import { registerGameBetTests } from '../shared/gameBetSuite';

/**
 * Pxplay SW（遊戲商 236）的下注驗證。
 * 測試內容是共用的（tests/shared/gameBetSuite.ts），這支只指定「要測哪一家」——
 * 新增遊戲商就複製這支、換一個設定即可。
 */
registerGameBetTests(PXPLAY_SW);
