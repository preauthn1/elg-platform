import 'package:flutter/material.dart';
import 'package:futter_web_frontend/controller/template_controller.dart';
import 'package:futter_web_frontend/pages/record_customer_service_page.dart';
import 'package:futter_web_frontend/pages/record_events_page.dart';
import 'package:futter_web_frontend/pages/record_order_page.dart';
import 'package:futter_web_frontend/pages/record_red_envelope_menu_page.dart';
import 'package:futter_web_frontend/pages/record_red_envelope_page.dart';
import 'package:futter_web_frontend/pages/record_transactions_page.dart';
import 'package:futter_web_frontend/pages/record_withdrawal_page.dart';
import 'package:futter_web_frontend/pages/unlimit_withdraw_page.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:futter_web_frontend/widgets/base/appbar_title_text.dart';
import 'package:futter_web_frontend/widgets/base/base_list_icon_button.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';

import 'package:get/get.dart';

class RecordMenuPage extends StatelessWidget {
  void pushToRedEnvelope(BuildContext context, int type) {
    push(RecordRedEnvelopePage(type));
  }

  @override
  Widget build(BuildContext context) {
    return BaseScaffold(
      appbarBackgroundIndex: 1,
      bodyBackgroundIndex: 1,
      appbarTitle: AppbarTitleText('recordList'.tr),
      child: SingleChildScrollView(
        child: Column(
          children: [
            //紅包紀錄
            BaseListIconButton(
              text: 'redEnvelopeRecord'.tr,
              icon: ListIcon.image(
                AssetImage('assets/images/record/red_envelope.png'),
              ),
              onPressed: () => push(RecordRedEnvelopeMenuPage()),
            ),
            //充值訂單
            if (TemplateController
                    .to.template.value.customizedSetting.hasDepositRecordPage ==
                '1')
              BaseListIconButton(
                text: 'Label_DepositRecord'.tr,
                icon: ListIcon.icon(Icons.cached),
                onPressed: () => push(RecordOrderPage()),
              ),
            //活動轉入
            if (TemplateController
                    .to.template.value.customizedSetting.hasEvent ==
                '1')
              BaseListIconButton(
                text: 'scratchCardTransferRecord'.tr,
                icon: ListIcon.icon(Icons.redeem),
                onPressed: () => push(RecordEventsPage()),
              ),
            //平台轉入
            BaseListIconButton(
              text: 'platformTransferRecord'.tr,
              icon: ListIcon.icon(Icons.cached),
              onPressed: () => push(RecordTransactionsPage()),
            ),
            //洗分紀錄
            // if (TemplateController.to.template.value.customizedSetting
            //         .withdrawalButtonVisible ==
            //     '1')
            BaseListIconButton(
              text: 'withdrawal_record'.tr,
              icon: ListIcon.svg('assets/images/wallet/withdrawal.svg'),
              onPressed: () => push(RecordWithdrawalPage()),
            ),
            //客服轉入
            BaseListIconButton(
              text: 'customerServiceTransferRecord'.tr,
              icon: ListIcon.icon(Icons.record_voice_over),
              onPressed: () => push(RecordCustomerServicePage()),
            ),
            //全民代理
            if (TemplateController.to.template.value.hasAgentLayout == '1' &&
                TemplateController
                        .to.template.value.customizedSetting.isUnlimitVisible ==
                    '1')
              BaseListIconButton.widget(
                child: Obx(
                  () => Text(
                    'Agent_AgentMenu'.tr.replaceNamedArgs(
                      {'v_reward': TemplateController.to.textUnlimited},
                    ),
                    style: TextStyle(
                      color: currentTheme.textBody2Color,
                    ),
                  ),
                ),
                icon: ListIcon.icon(Icons.person),
                onPressed: () => push(UnlimitWithdrawPage()),
              ),
          ],
        ),
      ),
    );
  }
}
