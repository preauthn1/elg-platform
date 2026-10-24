import { requestClient } from '#/api/request';

/**
 * 多錢包（6008）遊戲錢包開分/扣分 API
 * agent 第二錢包 wallet_type_id=1；member 遊戲錢包 wallet_type_id=2 */

/** 代理錢包餘額（指定錢包別） */
export async function getAgentWalletViewApi(
  id: number | string,
  wallet_type_id: number | string,
) {
  return requestClient.post<{ balance: number | string; username: string }>(
    '/api/agentWallet/view',
    { id, wallet_type_id },
  );
}

/** 代理遊戲錢包開分/扣分（type 1=開分 2=扣分） */
export async function agentGameWalletTransactionApi(data: {
  agent_id: number | string;
  memo: string;
  point: number | string;
  type: number;
  wallet_type_id: number | string;
}) {
  return requestClient.post<{ code: number; data?: unknown; message: string }>(
    '/api/agentWallet/transaction',
    data,
    { responseReturn: 'body' },
  );
}

/** 會員遊戲錢包餘額 */
export async function getMemberGameWalletApi(id: number | string) {
  return requestClient.post<{ balance: number | string; username: string }>(
    '/api/member/getMemberWallet',
    { id, wallet_type_id: 2 },
  );
}

/** 會員遊戲錢包操作（type 1=充值 2=補點 3=扣分） */
export async function memberGameWalletTransactionApi(data: {
  member_id: number | string;
  memo: string;
  point: number | string;
  type: number;
}) {
  return requestClient.post<{ code: number; data?: unknown; message: string }>(
    '/api/agentWallet/transactionToMember',
    data,
    { responseReturn: 'body' },
  );
}
