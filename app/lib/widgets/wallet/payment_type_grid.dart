import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:futter_web_frontend/controller/payment_type_controller.dart';
import 'package:futter_web_frontend/models/payment_type.dart';
import 'package:futter_web_frontend/models/wallet_order_type.dart';
import 'package:futter_web_frontend/pages/wallet_topup_amount_page.dart';
import 'package:futter_web_frontend/pages/wallet_topup_stored_value_page.dart';
import 'package:futter_web_frontend/pages/wallet_withdrawal_bank_bind_page.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:futter_web_frontend/widgets/base/base_card.dart';
import 'package:get/get.dart';

class PaymentTypeGrid extends GetView<PaymentTypeController> {
  final int _rowCount = 3;

  void _onClickPayment(int type, WalletOrderType orderType) {
    if (type == 1) {
      push(WalletTopupAmountPage());
    } else if (type == 2) {
      push(WalletWithdrawalBankBindPage());
    } else if (type == 3) {
      if (orderType.cfId == '18') {
        // 預付
        openBrowser(orderType.url);
      } else {
        push(WalletTopupStoredValuePage(orderType: orderType));
      }
    }
  }

  Border _getBorder(int index) {
    BorderSide borderSide = BorderSide(color: currentTheme.border);
    int lastRowCount = controller.paymentType.length % _rowCount;
    if (lastRowCount == 0) lastRowCount = _rowCount;
    bool lastRow = index > controller.paymentType.length - lastRowCount;
    bool right = index % _rowCount == 0;
    if (lastRow && !right) {
      return Border(right: borderSide);
    } else if (lastRow && right) {
      return null;
    } else if (right) {
      return Border(bottom: borderSide);
    } else {
      return Border(
        right: borderSide,
        bottom: borderSide,
      );
    }
  }

  Widget _buildItem(int index, PaymentType paymentType) {
    return InkWell(
      onTap: () {
        _onClickPayment(paymentType.type, paymentType.orderType);
      },
      child: Container(
        padding: EdgeInsets.all(8),
        decoration: BoxDecoration(
          border: _getBorder(index),
        ),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            paymentType.orderType.imagePath.substring(0, 4) == 'http'
                ? Image.network(
                    paymentType.orderType.imagePath,
                    height: 40,
                  )
                : Image.asset(
                    paymentType.orderType.imagePath,
                    height: 40,
                  ),
            SizedBox(height: 10),
            Text(
              paymentType.orderType.name,
              textAlign: TextAlign.center,
              style: TextStyle(fontSize: 12),
            ),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Obx(
      () => controller.paymentType.length <= 0
          ? SizedBox()
          : BaseCard(
              margin: EdgeInsets.symmetric(horizontal: 10),
              width: double.infinity,
              insideBorderWidth: 0,
              color: currentTheme.container,
              borderColor: currentTheme.border,
              child: GridView.count(
                crossAxisCount: _rowCount,
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                children: [
                  ...controller.paymentType.asMap().entries.map((entry) {
                    int index = entry.key + 1;
                    PaymentType paymentType = entry.value;
                    Widget item = _buildItem(index, paymentType);
                    return item;
                  }),
                ],
              ),
            ),
    );
  }
}
