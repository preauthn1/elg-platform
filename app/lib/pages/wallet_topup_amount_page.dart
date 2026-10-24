import 'package:flutter/material.dart';
import 'package:futter_web_frontend/models/bank_account.dart';
import 'package:futter_web_frontend/models/bank_order.dart';
import 'package:futter_web_frontend/pages/wallet_topup_receipt_page.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:futter_web_frontend/widgets/base/base_button.dart';
import 'package:futter_web_frontend/widgets/base/base_card.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';
import 'package:futter_web_frontend/widgets/base/base_text_field.dart';
import 'package:futter_web_frontend/widgets/base/appbar_title_text.dart';

import 'package:futter_web_frontend/utils/http_service.dart';
import 'package:futter_web_frontend/widgets/base/copy_button.dart';
import 'package:get/get.dart';

class WalletTopupAmountPage extends StatefulWidget {
  @override
  _WalletTopupAmountPageState createState() => _WalletTopupAmountPageState();
}

class _WalletTopupAmountPageState extends State<WalletTopupAmountPage> {
  final TextEditingController _amountController = TextEditingController();
  bool _initializing = true;
  bool _canSubmit = false;
  Bank _chooseBank;
  Bank _chooseAccount;
  BankAccount _account;
  @override
  void initState() {
    super.initState();
    _fetchData();
  }

  //獲取訂單類型列表
  Future<void> _fetchData() async {
    _account = await fetchBankAccount();
    if (_account.type == '2') {
      //type = 2 直接創建訂單
      _createOrder();
    } else {
      _selectBank(_account.allBank[0]);
      setState(() {
        _initializing = false;
      });
    }
  }

  //選擇銀行
  void _selectBank(Bank bank) {
    setState(() {
      _chooseBank = bank;
      _selectAccount(bank);
    });
  }

  //選擇帳戶
  void _selectAccount(Bank bank) {
    setState(() {
      _chooseAccount = bank;
      confirmationForm();
    });
  }

  List<Bank> get _bankList {
    List<Bank> list = [];
    this._account.allBank.forEach((bank) {
      bool found = list.any((element) => element.bankCode == bank.bankCode);
      if (!found) list.add(bank);
    });
    return list;
  }

  List<Bank> get _accountList {
    List<Bank> list = this._account.allBank.where((bank) {
      return bank.bankCode == this._chooseBank.bankCode;
    }).toList();
    return list;
  }

  //確認表單
  void confirmationForm() {
    int amount = int.tryParse(_amountController.text);
    if (
        //輸入非數字
        amount == null ||
            //<=0
            amount <= 0 ||
            //最低金額
            amount < int.parse(_chooseAccount.minAmount) ||
            //最高金額
            amount > int.parse(_chooseAccount.maxAmount)) {
      setState(() {
        _canSubmit = false;
      });
    } else {
      setState(() {
        _canSubmit = true;
      });
    }
  }

  //新增訂單
  Future<void> _createOrder() async {
    try {
      BankOrder order;
      //type = 2 直接創建訂單
      if (_account.type == '2') {
        order = await createBankOrder();
      } else {
        showLoadingDialog();
        order = await createBankOrder(
          bankId: _chooseAccount.bankId,
          amount: _amountController.text,
        );
      }
      popToFirst();
      push(WalletTopupReceiptPage(order), fullscreenDialog: true);
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
                  child: Container(
                    width: double.infinity,
                    child: Text(
                      bank.bankName,
                      textAlign: TextAlign.end,
                      style: TextStyle(
                        fontSize:
                            Theme.of(context).textTheme.bodyText2.fontSize,
                      ),
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

  //帳戶選單
  Widget _buildAccountDropdown() {
    return _buildItem(
      'Wallet_AccountName'.tr,
      Expanded(
        flex: 3,
        child: DropdownButton(
          underline: SizedBox(),
          value: _chooseAccount,
          isExpanded: true,
          items: _accountList
              .map(
                (bank) => DropdownMenuItem(
                  value: bank,
                  child: Container(
                    width: double.infinity,
                    child: Text(
                      bank.accountName,
                      textAlign: TextAlign.end,
                      style: TextStyle(
                        fontSize:
                            Theme.of(context).textTheme.bodyText2.fontSize,
                      ),
                    ),
                  ),
                  onTap: () {},
                ),
              )
              .toList(),
          onChanged: (value) {
            _selectAccount(value);
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
      appbarTitle: AppbarTitleText('Wallet_Topup'.tr),
      child: _initializing
          ? Center(child: CircularProgressIndicator())
          : SingleChildScrollView(
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 20.0),
                child: SingleChildScrollView(
                  child: Column(
                    children: [
                      //銀行
                      _buildBankDropdown(),
                      //帳戶名稱
                      _buildAccountDropdown(),
                      //銀行帳號
                      _buildItem(
                        'Wallet_AccountNo'.tr,
                        Row(
                          children: [
                            Text(_chooseAccount.accountNo),
                            Container(
                              width: 30,
                              child: CopyButton(_chooseAccount.accountNo),
                            )
                          ],
                        ),
                      ),
                      //金額
                      BaseTextField(
                        controller: _amountController,
                        keyboardType: TextInputType.number,
                        prefix: Text("amount".tr),
                        hintText: 'pleaseEnterTheNumberOfPoints'.tr,
                        onChanged: (value) => confirmationForm(),
                      ),
                      SizedBox(height: 20),
                      //提示
                      Container(
                        width: double.infinity,
                        child: Text(
                          '${'Label_MinimumAmount'.tr}: ${_chooseAccount.minAmount}\n${'Label_MaximumAmount'.tr}: ${_chooseAccount.maxAmount}',
                          style:
                              TextStyle(color: currentTheme.icon, fontSize: 11),
                        ),
                      ),
                      SizedBox(height: 34),
                      //提交
                      BaseButton(
                        colors: _canSubmit
                            ? currentTheme.buttonPrimary
                            : currentTheme.buttonDisable,
                        text: 'submit'.tr,
                        onPressed: _canSubmit ? _createOrder : null,
                      ),
                    ],
                  ),
                ),
              ),
            ),
    );
  }
}
