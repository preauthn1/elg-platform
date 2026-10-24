import 'package:flutter/material.dart';
import 'package:futter_web_frontend/controller/member_controller.dart';
import 'package:futter_web_frontend/models/flow_limit.dart';
import 'package:futter_web_frontend/models/withdrawal_bank.dart';
import 'package:futter_web_frontend/models/withdrawal_info.dart';
import 'package:futter_web_frontend/pages/phone_verification_page.dart';
import 'package:futter_web_frontend/pages/wallet_withdrawal_amount_page.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:futter_web_frontend/widgets/base/base_button.dart';
import 'package:futter_web_frontend/widgets/base/base_card.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';
import 'package:futter_web_frontend/widgets/base/base_text_field.dart';
import 'package:futter_web_frontend/widgets/base/appbar_title_text.dart';

import 'package:futter_web_frontend/utils/http_service.dart';
import 'package:futter_web_frontend/widgets/wallet/flow_limit_text.dart';
import 'package:get/get.dart';

class WalletWithdrawalBankBindPage extends StatefulWidget {
  @override
  _WalletWithdrawalBankBindPageState createState() =>
      _WalletWithdrawalBankBindPageState();
}

class _WalletWithdrawalBankBindPageState
    extends State<WalletWithdrawalBankBindPage> {
  final TextEditingController _accountNoController = TextEditingController();
  final TextEditingController _accountNameController = TextEditingController();
  bool _initializing = true;
  bool _canSubmit = false;
  WithdrawalBank _chooseBank;
  WithdrawalInfo _withdrawalInfo;
  List<WithdrawalBank> _bankList;
  List<FlowLimit> _flowLimitList;
  @override
  void initState() {
    super.initState();
    _fetchData();
  }

  Future<void> _fetchData() async {
    setState(() {
      _initializing = true;
    });
    _withdrawalInfo = await fetchWithdrawalInfo();
    _bankList = await fetchWithdrawalBank();
    _flowLimitList = await fetchFlowLimit();
    if (_withdrawalInfo.bankCode == 'null') {
      _chooseBank = _bankList[0];
    } else {
      _chooseBank = _bankList.firstWhere(
        (element) => element.bankCode == _withdrawalInfo.bankCode,
        orElse: () => _chooseBank = _bankList[0],
      );
      if (_withdrawalInfo.accountName != 'null')
        _accountNameController.text = _withdrawalInfo.accountName;
      if (_withdrawalInfo.accountNo != 'null')
        _accountNoController.text = _withdrawalInfo.accountNo;
      confirmationForm();
    }
    setState(() {
      _initializing = false;
    });
  }

  //選擇銀行
  void _selectBank(WithdrawalBank bank) {
    setState(() {
      _chooseBank = bank;
    });
  }

  //確認表單
  void confirmationForm() {
    num reach = num.parse(_flowLimitList[0].reach);
    num flowLimit = num.parse(_flowLimitList[0].flowLimit);
    if (reach < flowLimit ||
        _accountNoController.text.isBlank ||
        _accountNameController.text.isBlank) {
      setState(() {
        _canSubmit = false;
      });
    } else {
      setState(() {
        _canSubmit = true;
      });
    }
  }

  //下個頁面
  void pushNextPage() {
    if (_withdrawalInfo.mobile != 'null') {
      //手機未認證
      push(
        PhoneVerificationPage(4, MemberController.to.member.value.id,
            onSuccess: (_, __) {
          push(WalletWithdrawalAmount(), cancelPrevious: true);
        }),
        cancelPrevious: true,
      );
    } else {
      push(
        WalletWithdrawalAmount(),
        cancelPrevious: true,
      );
    }
  }

  //新增訂單
  Future<void> _submit() async {
    if (!_canSubmit) return;
    try {
      showLoadingDialog();
      await bindWithdrawalBank(
          bankId: _chooseBank.bankId,
          accountNo: _accountNoController.text,
          accountName: _accountNameController.text);
      pushNextPage();
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
            SizedBox(width: 30),
            content,
          ],
        ),
      ),
    );
  }

  //銀行選單
  Widget _buildBankDropdown() {
    return _buildItem(
      'Wallet_Bank'.tr,
      Expanded(
        flex: 3,
        child: DropdownButton(
          underline: SizedBox(),
          value: _chooseBank,
          isExpanded: true,
          items: _bankList
              .map(
                (bank) => DropdownMenuItem(
                  value: bank,
                  child: Text(
                    bank.bankName,
                    style: TextStyle(
                      fontSize: Theme.of(context).textTheme.bodyText2.fontSize,
                    ),
                  ),
                  onTap: () {},
                ),
              )
              .toList(),
          onChanged: (value) {
            _selectBank(value);
          },
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
                      FlowLimitText(flowLimitList: _flowLimitList),
                      //提示
                      Container(
                        width: double.infinity,
                        child: Text(
                          '${'Wallet_FillBankAccount'.tr}',
                          style: TextStyle(
                            color: currentTheme.icon,
                            fontSize: 11,
                          ),
                        ),
                      ),
                      //銀行
                      _buildBankDropdown(),
                      //帳戶名稱
                      BaseTextField(
                        controller: _accountNameController,
                        keyboardType: TextInputType.number,
                        prefix: Text("Wallet_AccountName".tr),
                        onChanged: (value) => confirmationForm(),
                      ),
                      //銀行帳號
                      BaseTextField(
                        controller: _accountNoController,
                        keyboardType: TextInputType.number,
                        prefix: Text("Wallet_AccountNo".tr),
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
