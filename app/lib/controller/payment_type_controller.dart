import 'package:futter_web_frontend/controller/template_controller.dart';
import 'package:futter_web_frontend/models/payment_type.dart';
import 'package:futter_web_frontend/models/wallet_order_type.dart';
import 'package:futter_web_frontend/utils/http_service.dart';
import 'package:get/get.dart';

class PaymentTypeController extends GetxController {
  final paymentType = <PaymentType>[].obs;
  static PaymentTypeController get to => Get.find();
  Future<void> fetchAndSetPaymentType() async {
    List<PaymentType> payment = [];
    //是否顯示自助上分
    bool _hasAutoDespoiste = await hasAutoDespoiste();
    if (_hasAutoDespoiste) {
      WalletOrderType type = WalletOrderType(
        orderTypeId: '',
        name: 'Wallet_TopupBankTransfer'.tr,
        imagePath: 'assets/images/wallet/topup.png',
        minAmount: '',
        maxAmount: '',
        availableAmount: '',
        rate: '',
        cfId: '',
        cfName: '',
        url: '',
      );
      payment.add(PaymentType(type: 1, orderType: type));
    }

    //洗分
    if (TemplateController
            .to.template.value.customizedSetting.withdrawalButtonVisible ==
        '1') {
      WalletOrderType type = WalletOrderType(
        orderTypeId: '',
        name: TemplateController
            .to.template.value.customizedSetting.textWithdrawal,
        imagePath: 'assets/images/wallet/withdrawal.png',
        minAmount: '',
        maxAmount: '',
        availableAmount: '',
        rate: '',
        cfId: '',
        cfName: '',
        url: '',
      );
      payment.add(PaymentType(type: 2, orderType: type));
    }

    //支付列表
    List<WalletOrderType> orderType = await fetchOrderTypes();
    orderType.map((type) {
      payment.add(PaymentType(type: 3, orderType: type));
    }).toList();
    paymentType.clear();
    paymentType.addAll(payment);
  }
}
