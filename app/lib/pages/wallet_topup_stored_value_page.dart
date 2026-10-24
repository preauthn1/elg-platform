import 'package:flutter/material.dart';
import 'package:futter_web_frontend/controller/member_controller.dart';
import 'package:futter_web_frontend/controller/template_controller.dart';
import 'package:futter_web_frontend/models/deposit_order.dart';
import 'package:futter_web_frontend/models/web_page.dart';
import 'package:futter_web_frontend/pages/wallet_topup_qrcode_page.dart';
import 'package:futter_web_frontend/pages/webview_page.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:futter_web_frontend/widgets/base/base_button.dart';
import 'package:futter_web_frontend/widgets/base/base_card.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';
import 'package:futter_web_frontend/widgets/base/base_text_field.dart';
import 'package:futter_web_frontend/models/wallet_order_type.dart';
import 'package:futter_web_frontend/widgets/base/appbar_title_text.dart';

import 'package:futter_web_frontend/utils/http_service.dart';
import 'package:futter_web_frontend/widgets/wallet/qrcode_reader.dart';
import 'package:get/get.dart';

class WalletTopupStoredValuePage extends StatefulWidget {
  final WalletOrderType orderType;
  const WalletTopupStoredValuePage({this.orderType});

  @override
  _WalletTopupStoredValuePageState createState() =>
      _WalletTopupStoredValuePageState();
}

class _WalletTopupStoredValuePageState
    extends State<WalletTopupStoredValuePage> {
  final TextEditingController _pointController = TextEditingController();
  bool _initializing = false;
  // List<WalletOrderType> _orderTypes = [];
  // WalletOrderType _chooseOrderType;
  @override
  void initState() {
    super.initState();
    // _fetchData();
  }

  //獲取訂單類型列表
  // Future<void> _fetchData() async {
  //   setState(() {
  //     _initializing = true;
  //   });
  //   List<WalletOrderType> orderTypes = await fetchOrderTypes();
  //   _orderTypes.add(
  //     WalletOrderType(
  //         orderTypeId: null,
  //         name: 'pleaseSelectABank'.tr,
  //         minAmount: '0',
  //         maxAmount: '0',
  //         availableAmount: '0',
  //         rate: '0'),
  //   );
  //   _orderTypes.addAll(orderTypes);
  //   setState(() {
  //     _chooseOrderType = _orderTypes[0];
  //     _initializing = false;
  //   });
  // }

  //新增訂單
  Future<void> _createOrder() async {
    try {
      showLoadingDialog();
      final DepositOrder order = await createOrder(
        widget.orderType.orderTypeId,
        amount: _pointController.text,
      );
      pop();
      if (order.type == '1') {
        push(
          WebviewPage(
            webpage: WebPage(title: 'storedValuPoints'.tr, url: order.url),
          ),
          fullscreenDialog: true,
        );
      } else if (order.type == '3') {
        push(
          WebviewPage(
            webpage: WebPage(title: 'storedValuPoints'.tr, html: order.html),
          ),
          fullscreenDialog: true,
        );
      } else if (order.type == '11') {
        push(
          WalletTopupQrcodePage(order.code),
          fullscreenDialog: true,
        );
      }
    } catch (error) {
      pop();
    }
  }

  //檢查輸入資訊
  void _checkInputInfo() {
    //未選擇支付方式
    if (widget.orderType.orderTypeId == null) {
      showMessageDialog(message: 'pleaseSelectABank'.tr);
      return;
    }
    //未輸入金額
    if (_pointController.text.isEmpty) {
      showMessageDialog(message: 'pleaseEnterTheNumberOfPoints'.tr);
      return;
    }
    //輸入非數字
    if (!_pointController.text.isNum) {
      showMessageDialog(message: 'Validate_NumericOnly'.tr);
      return;
    }

    //金額超出範圍
    num amount = num.parse(_pointController.text);
    num min = num.parse(widget.orderType.minAmount);
    num max = num.parse(widget.orderType.maxAmount);
    if (amount <= 0 || amount < min || amount > max) {
      showMessageDialog(
        message:
            '${'Msg_PleaseInputNumberBetween'.tr}${widget.orderType.minAmount}-${widget.orderType.maxAmount}',
      );
      return;
    }
    num point = num.tryParse(_pointController.text);
    num pointRate = point * num.tryParse(widget.orderType.rate);

    String message = "";
    switch (widget.orderType.name) {
      case 'USDT(ERC20)':
        message =
            '$point ERC USDT = ${pointRate.toStringAsFixed(2)} ${TemplateController.to.textWalletPoint}';
        break;
      case 'USDT(TRC20)':
        message =
            '$point TRC USDT = ${pointRate.toStringAsFixed(2)} ${TemplateController.to.textWalletPoint}';
        break;
      default:
        message = '$point';
        break;
    }

    //確認
    showMessageDialog(
      title: 'confirmDepositInformation'.tr,
      content: Column(
        children: [
          Text(
            'bankName'.tr + ':',
            style: TextStyle(
              fontWeight: FontWeight.bold,
            ),
          ),
          Text(
            widget.orderType.name,
            style: TextStyle(color: currentTheme.textHead),
          ),
          SizedBox(height: 20),
          Text(
            'storedValuPoints'.tr + ':',
            style: TextStyle(fontWeight: FontWeight.bold),
          ),
          Text(
            message,
            style: TextStyle(color: currentTheme.textHead),
            textAlign: TextAlign.center,
          )
        ],
      ),
      onPressed: _createOrder,
    );
  }

  //銀行選單
  // Widget _buildDropdownButton() {
  //   return CommonCard(
  //     margin: const EdgeInsets.symmetric(vertical: 15),
  //     padding: const EdgeInsets.symmetric(horizontal: 10),
  //     width: double.infinity,
  //     height: 40,
  //     colors: currentTheme.buttonList,
  //     child: DropdownButton(
  //       underline: SizedBox(),
  //       value: _chooseOrderType,
  //       isExpanded: true,
  //       items: _orderTypes
  //           .map(
  //             (orderType) => DropdownMenuItem(
  //               value: orderType,
  //               child: Text(orderType.name),
  //               onTap: () {},
  //             ),
  //           )
  //           .toList(),
  //       onChanged: (value) {
  //         setState(() {
  //           _chooseOrderType = value;
  //         });
  //       },
  //     ),
  //   );
  // }

  Widget _buildTextInput() {
    return Column(
      children: [
        //點數
        BaseTextField(
          textAlign: TextAlign.left,
          controller: _pointController,
          hintText: 'pleaseEnterTheNumberOfPoints'.tr,
        ),
        SizedBox(height: 20),
        //兌換率顯示
        if (widget.orderType.orderTypeId != null &&
            widget.orderType.name == 'USDT(ERC20)')
          Container(
            width: double.infinity,
            child: Text(
              '1 ERC USDT = ${num.tryParse(widget.orderType.rate).toStringAsFixed(2)} ${TemplateController.to.textWalletPoint}',
              style: TextStyle(color: currentTheme.icon),
            ),
          ),
        if (widget.orderType.orderTypeId != null &&
            widget.orderType.name == 'USDT(TRC20)')
          Container(
            width: double.infinity,
            child: Text(
              '1 TRC USDT = ${num.tryParse(widget.orderType.rate).toStringAsFixed(2)} ${TemplateController.to.textWalletPoint}',
              style: TextStyle(color: currentTheme.icon),
            ),
          ),
        if (widget.orderType.orderTypeId != null &&
            widget.orderType.name == 'AsiaLife')
          Container(
            width: double.infinity,
            child: Text(
              '1 AsiaLife ${'Common_GamePoint'.tr} = ${num.tryParse(widget.orderType.rate).toStringAsFixed(2)} ${TemplateController.to.textWalletPoint}',
              style: TextStyle(color: currentTheme.icon),
            ),
          ),
        //額度
        if (widget.orderType.orderTypeId != null)
          Container(
            width: double.infinity,
            child: Text(
              '${'Label_MinimumAmount'.tr}: ${widget.orderType.minAmount}\n${'Label_MaximumAmount'.tr}: ${widget.orderType.maxAmount}',
              style: TextStyle(color: currentTheme.icon),
            ),
          ),

        SizedBox(height: 20),
        //提交
        BaseButton(
          colors: currentTheme.buttonPrimary,
          text: 'submit'.tr,
          onPressed: _checkInputInfo,
        ),
        SizedBox(height: 20),
        //提示
        Container(
          width: double.infinity,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('1.' + 'waitForTradingResults'.tr),
              Text('2.' + 'afterTheTransaction'.tr),
              Text('3.' + 'topupTip3'.tr),
            ],
          ),
        )
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    return BaseScaffold(
      appbarBackgroundIndex: 0,
      bodyBackgroundIndex: 0,
      appbarTitle: AppbarTitleText('storedValuPoints'.tr),
      child: _initializing
          ? Center(child: CircularProgressIndicator())
          : SingleChildScrollView(
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 20.0),
                child: SingleChildScrollView(
                  child: Column(
                    children: [
                      SizedBox(height: 10),
                      //VPoint
                      Text(
                        TemplateController.to.textWalletPoint,
                        style: TextStyle(fontSize: 20),
                      ),
                      SizedBox(height: 10),
                      Text(
                        MemberController.to.member.value.balance,
                        style: TextStyle(
                            fontSize: 25, color: currentTheme.textDanger),
                      ),
                      SizedBox(height: 10),
                      //支付方式
                      // _buildDropdownButton(),
                      BaseCard(
                        padding: const EdgeInsets.symmetric(horizontal: 10),
                        width: double.infinity,
                        height: 40,
                        colors: currentTheme.buttonList,
                        borderColors: currentTheme.borderListButton,
                        child: Align(
                          alignment: Alignment.centerLeft,
                          child: Text(
                            widget.orderType.name,
                          ),
                        ),
                      ),
                      if (widget.orderType.orderTypeInfo[0].type == 'qrcode')
                        QrcodeReader(widget.orderType.orderTypeId),
                      if (widget.orderType.orderTypeInfo[0].type != 'qrcode')
                        _buildTextInput(),
                    ],
                  ),
                ),
              ),
            ),
    );
  }
}
