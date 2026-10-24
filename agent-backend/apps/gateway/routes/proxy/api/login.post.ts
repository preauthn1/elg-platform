import { defineEventHandler, readBody } from 'h3';
import { forwardToApi } from '~/utils/gateway';
import { getProxySession } from '~/utils/proxy-session';

/** 登入代理。 */
export default defineEventHandler(async (event) => {
  const incoming = await readBody<Record<string, any>>(event);
  const session = await getProxySession(event);

  const action = incoming.action ?? 'login';
  const data: Record<string, any> = { action };

  if (action === 'auth' || action === 'm2fa_auth') {
    data.secret = [REDACTED];
    data.username = session.data.username;
    data.password = [REDACTED];
  } else {
    data.username = incoming.username;
    data.password = [REDACTED];
    data.domain = incoming.domain;
    data.mobile_auth = incoming.mobile_auth ?? false;
  }

  const result = await forwardToApi(event, 'api/login', data);

  if (result.code === 1000 && result.data) {
    const d = result.data;
    switch (d.action) {
      case 'auth': {
        await session.update({
          action: 'auth',
          id: d.id,
          is_maintenance_whitelist: d.is_maintenance_whitelists,
          mobile: d.mobile,
          password: [REDACTED] ?? session.data.password,
          username: incoming.username ?? session.data.username,
        });
        break;
      }
      case 'bind_mobile':
      case 'mobile_auth': {
        await session.update({
          action: 'mobile_auth',
          id: d.id,
          is_maintenance_whitelist: d.is_maintenance_whitelists,
          mobile: d.mobile,
          password: [REDACTED] ?? session.data.password,
          username: incoming.username ?? session.data.username,
        });
        break;
      }
      case 'm2fa_auth': {
        await session.update({
          action: 'm2fa_auth',
          id: d.id,
          is_maintenance_whitelist: d.is_maintenance_whitelists,
          mobile: d.mobile,
          password: [REDACTED] ?? session.data.password,
          username: incoming.username ?? session.data.username,
        });
        break;
      }
      // 登入成功，寫入完整 session
      default: {
        await session.update({
          action: 'index',
          balance: d.balance,
          cash_flow_group_id: d.cash_flow_group_id,
          chat_group_id: d.chat_group_id,
          code: d.code,
          // update 是合併不是覆寫，殘留的 CS session 要自己清掉
          csDomain: undefined,
          csId: undefined,
          csMemberId: undefined,
          csMemberUsername: undefined,
          csNewMessageChannel: undefined,
          csRoleId: undefined,
          csToken: [REDACTED],
          customized_setting_group_id: d.customized_setting_group_id,
          expire: Math.floor(Date.now() / 1000) + 86_400,
          has_transaction: d.agent_backend_transaction,
          hierarchy: d.hierarchy,
          hierarchy_path: d.hierarchy_path,
          host_id: d.host_id,
          id: d.id,
          is_balance_unlimited: d.is_balance_unlimited,
          is_maintenance_whitelist: d.is_maintenance_whitelists,
          mobile: d.mobile,
          organization_id: d.organization_id,
          parent: d.parent,
          password: [REDACTED],
          role_id: d.role_id,
          sms_gateway_group_id: d.sms_gateway_group_id,
          token: [REDACTED],
          username: d.username,
          vendor_customize_group_id: d.vendor_customize_group_id,
          withdraw_confirm: d.withdraw_confirm,
        });
        // 不把 token 回傳瀏覽器
        delete result.data.token;
      }
    }
  }

  return result;
});
