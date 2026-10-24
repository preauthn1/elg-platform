import 'dart:io';

import 'package:flutter/material.dart';
import 'package:futter_web_frontend/models/bank_order.dart';
import 'package:futter_web_frontend/pages/wallet_topup_result_page.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:futter_web_frontend/widgets/base/appbar_title_text.dart';
import 'package:futter_web_frontend/widgets/base/base_button.dart';
import 'package:futter_web_frontend/widgets/base/base_card.dart';
import 'package:futter_web_frontend/widgets/base/base_circle_avator.dart';
import 'package:futter_web_frontend/widgets/base/base_circle_button.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';

import 'package:futter_web_frontend/utils/http_service.dart';
import 'package:futter_web_frontend/widgets/base/copy_button.dart';
import 'package:get/get.dart';

class WalletTopupReceiptPage extends StatefulWidget {
  final BankOrder order;
  WalletTopupReceiptPage(this.order);

  @override
  _WalletTopupReceiptPageState createState() => _WalletTopupReceiptPageState();
}

class _WalletTopupReceiptPageState extends State<WalletTopupReceiptPage> {
  File _receiptImage;
  bool _canSubmit = false;

  //上傳收據
  Future<void> _uploadImage() async {
    showLoadingDialog();
    try {
      String imageBase64 = await fileToBase64(_receiptImage);
      var amount = await uploadBankReceiptImage(
        widget.order.orderId,
        imageBase64,
      );
      popToFirst();
      push(WalletTopupResultPage(amount), fullscreenDialog: true);
    } catch (error) {
      pop();
    }
  }

  //選擇收據
  void _takePicture() async {
    showTakePictureButtonSheet(
      onSelect: (image) async {
        setState(() {
          File file = File(image.path);
          _receiptImage = file;
          _canSubmit = true;
        });
      },
    );
  }

  String get statusText {
    if (widget.order.status == '0') {
      return 'Order_Create'.tr;
    } else if (widget.order.status == '1') {
      return 'Order_Pending'.tr;
    } else if (widget.order.status == '2') {
      return 'Order_Refuse'.tr;
    } else if (widget.order.status == '3') {
      return 'successfully'.tr;
    }
    return '';
  }

  Widget _buildItem(String title, String content, {Widget suffix}) {
    return BaseCard(
      margin: EdgeInsets.only(top: 19),
      height: 38,
      colors: currentTheme.buttonList,
      borderColors: currentTheme.borderListButton,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 8),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Text(title),
            Row(
              mainAxisAlignment: MainAxisAlignment.end,
              children: [
                Text(content),
                if (suffix != null) suffix,
              ],
            ),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return BaseScaffold(
      appbarBackgroundIndex: 1,
      bodyBackgroundIndex: 0,
      appbarTitle: AppbarTitleText('Wallet_Topup'.tr),
      leading: SizedBox(),
      actions: [
        IconButton(
          icon: Icon(
            Icons.close,
            color: currentTheme.icon,
          ),
          onPressed: () {
            showMessageDialog(
              message: 'Wallet_CancelOrder'.tr,
              onPressed: () async {
                showLoadingDialog();
                await deleteBankOrder(widget.order.orderId);
                popToFirst();
              },
            );
          },
        ),
      ],
      child: SingleChildScrollView(
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 20.0),
          child: SingleChildScrollView(
            child: Column(
              children: [
                //Bank
                _buildItem('Wallet_Bank'.tr, widget.order.bankName),
                //Account Name
                _buildItem('Wallet_AccountName'.tr, widget.order.accountName),
                //Account No
                _buildItem(
                  'Wallet_AccountNo'.tr,
                  widget.order.accountNo,
                  suffix: Container(
                    width: 30,
                    child: CopyButton('1234567'),
                  ),
                ),
                //Amount
                _buildItem('amount'.tr, widget.order.amount),
                //Status
                _buildItem('Common_Status'.tr, statusText),

                SizedBox(height: 35),
                //選擇收據
                _receiptImage == null
                    ? Column(
                        children: [
                          BaseCircleButton(
                            size: 68,
                            child: BaseCircleAvator(
                              size: 68,
                              color: currentTheme.container,
                              borderColor: currentTheme.border,
                              child: Icon(
                                Icons.photo,
                                size: 40,
                                color: currentTheme.icon,
                              ),
                            ),
                            onPressed: _takePicture,
                          ),
                          SizedBox(height: 8),
                          Text('Wallet_UploadReceipt'.tr),
                        ],
                      )
                    : GestureDetector(
                        child: Image.file(
                          _receiptImage,
                          height: 150,
                        ),
                        onTap: _takePicture,
                      ),
                SizedBox(height: 30),

                //提交
                BaseButton(
                  colors: _canSubmit
                      ? currentTheme.buttonPrimary
                      : currentTheme.buttonDisable,
                  text: 'submit'.tr,
                  onPressed: _canSubmit ? _uploadImage : null,
                ),
                SizedBox(height: 33),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
