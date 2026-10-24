import 'package:flutter/material.dart';
import 'package:futter_web_frontend/models/withdrawal_info.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:futter_web_frontend/widgets/base/base_button.dart';
import 'package:futter_web_frontend/widgets/base/base_card.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';
import 'package:futter_web_frontend/widgets/base/base_text_field.dart';
import 'package:futter_web_frontend/widgets/base/appbar_title_text.dart';

import 'package:futter_web_frontend/utils/http_service.dart';
import 'package:get/get.dart';

class WalletWithdrawalAmount extends StatefulWidget {
  @override
  _WalletWithdrawalAmountState createState() => _WalletWithdrawalAmountState();
}

class _WalletWithdrawalAmountState extends State<WalletWithdrawalAmount> {
  final TextEditingController _amountController = TextEditingController();
  bool _initializing = true;
  bool _canSubmit = false;
  WithdrawalInfo _withdrawalInfo;
  @override
  void initState() {
    super.initState();
    _fetchData();
  }

  //獲取洗分資訊
  Future<void> _fetchData() async {
    _withdrawalInfo = await fetchWithdrawalInfo();
    setState(() {
      _initializing = false;
    });
  }

  //確認表單
  void confirmationForm() {
    int amount = int.tryParse(_amountController.text);
    if (amount == null || amount <= 0) {
      setState(() {
        _canSubmit = false;
      });
    } else {
      setState(() {
        _canSubmit = true;
      });
    }
  }

  //提交
  Future<void> _submit() async {
    if (!_canSubmit) return;
    try {
      showLoadingDialog();
      await createWithdrawalOrder(amount: _amountController.text);
      await showMessageDialog(
          message: '${'successfully'.tr}！\n${'Msg_AnyQuestionPleaseAskCS'.tr}');
      popToFirst();
    } catch (error) {
      pop();
    }
  }

  //Item
  Widget _buildItem(String title, Widget content) {
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
            Expanded(
              child: Container(),
            ),
            content,
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
      appbarTitle: AppbarTitleText('withdrawal'.tr),
      child: _initializing
          ? Center(child: CircularProgressIndicator())
          : SingleChildScrollView(
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 20.0),
                child: SingleChildScrollView(
                  child: Column(
                    children: [
                      SizedBox(height: 20),
                      //銀行
                      _buildItem(
                          'Wallet_Bank'.tr, Text(_withdrawalInfo.bankName)),
                      //帳戶名稱
                      _buildItem('Wallet_AccountName'.tr,
                          Text(_withdrawalInfo.accountName)),
                      //銀行帳號
                      _buildItem('Wallet_AccountNo'.tr,
                          Text(_withdrawalInfo.accountNo)),
                      //金額
                      BaseTextField(
                        controller: _amountController,
                        keyboardType: TextInputType.number,
                        prefix: Text("amount".tr),
                        onChanged: (value) => confirmationForm(),
                      ),

                      SizedBox(height: 34),
                      //提交
                      BaseButton(
                        colors: _canSubmit
                            ? currentTheme.buttonPrimary
                            : currentTheme.buttonDisable,
                        text: 'submit'.tr,
                        onPressed: _canSubmit ? _submit : null,
                      ),
                    ],
                  ),
                ),
              ),
            ),
    );
  }
}
