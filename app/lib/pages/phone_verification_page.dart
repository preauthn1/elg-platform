import 'dart:async';
import 'package:flutter/material.dart';
import 'package:futter_web_frontend/controller/member_controller.dart';
import 'package:futter_web_frontend/models/country.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/widgets/base/appbar_title_text.dart';
import 'package:futter_web_frontend/widgets/base/base_button.dart';
import 'package:futter_web_frontend/widgets/base/base_card.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';
import 'package:futter_web_frontend/widgets/base/base_text_field.dart';

import 'package:futter_web_frontend/utils/utils.dart';
import 'package:futter_web_frontend/utils/http_service.dart';
import 'package:get/get.dart';

class PhoneVerificationPage extends StatefulWidget {
  ///type 1:資料驗證2:忘記密碼3:找回密碼4:洗分驗證
  final int type;
  final String memberId;
  final Function(String token, String username) onSuccess;
  PhoneVerificationPage(this.type, this.memberId, {this.onSuccess});
  @override
  _PhoneVerificationPageState createState() => _PhoneVerificationPageState();
}

class _PhoneVerificationPageState extends State<PhoneVerificationPage> {
  bool _initializing = true;
  final TextEditingController _phoneController = TextEditingController();
  //忘記密碼、資料驗證用
  final TextEditingController _verificationCodeController =
      TextEditingController();
  List<Country> _countries = [];
  Country _selectCountry;

  ///發送簡訊倒數計時
  Timer _timer;
  int _waitingTime = 180;
  int _remainingTime = 0;

  @override
  void initState() {
    super.initState();
    //洗分手機驗證
    if (widget.type == 4) {
      _phoneController.text = MemberController.to.member.value.mobile;
      setState(() {
        _initializing = false;
      });
    } else {
      _fetchCountries();
    }
  }

  @override
  void dispose() {
    super.dispose();
    if (_timer != null) _timer.cancel();
    _verificationCodeController.dispose();
  }

  ///發送簡訊倒數計時
  void _startCountdown() {
    const oneSec = const Duration(seconds: 1);
    _remainingTime = _waitingTime;
    _timer = Timer.periodic(
      oneSec,
      (Timer timer) => setState(
        () {
          if (_remainingTime < 1) {
            timer.cancel();
          } else {
            _remainingTime = _remainingTime - 1;
          }
        },
      ),
    );
  }

  //國家列表
  Future<void> _fetchCountries() async {
    _countries = await fetchCountries();
    if (_countries.length <= 0) {
      _countries.add(
        Country(
          code: '0',
          countryCode: '0',
          id: '0',
          name: 'null',
          phoneRule: 'null',
        ),
      );
    }
    setState(() {
      _selectCountry = _countries[0];
      _initializing = false;
    });
  }

  String get phoneNumber {
    if (widget.type == 4) {
      return _phoneController.text;
    } else {
      return _selectCountry.countryCode + _phoneController.text;
    }
  }

  void _onPhoneTextFieldChanged(String value) {
    //刪除頭碼為0
    if (_phoneController.text.length > 0 && _phoneController.text[0] == '0') {
      _phoneController.text = _phoneController.text.substring(1);
    }
    //只保留數字
    _phoneController.text =
        _phoneController.text.replaceAll(new RegExp(r'[^0-9]'), '');
    // cursor移到最後
    _phoneController.selection = TextSelection.fromPosition(
      TextPosition(offset: _phoneController.text.length),
    );
  }

  //檢查輸入資訊
  void _checkInputInfo() async {
    if (!_phoneController.text.isNumericOnly) {
      showMessageDialog(message: 'Validate_NumericOnly'.tr);
      return;
    }

    await showMessageDialog(
      message: 'sendverifycodetophonenumber'.tr + ':\n+' + phoneNumber,
      onPressed: _sendValidateCode,
    );
  }

  //傳送驗證碼
  Future<void> _sendValidateCode() async {
    try {
      showLoadingDialog();
      await sendValidateCode(widget.type, phoneNumber, widget.memberId);
      pop();
      _startCountdown();
    } catch (error) {
      pop();
    }
  }

  //確認驗證碼
  Future<void> _checkValidateCode() async {
    String verificationCode =
        _verificationCodeController.text.replaceAll(new RegExp(r'[^0-9]'), '');
    _verificationCodeController.text = verificationCode;
    try {
      showLoadingDialog();
      final username = await checkValidateCode(verificationCode);
      pop();
      if (widget.onSuccess != null) {
        widget.onSuccess(verificationCode, username);
      }
    } catch (error) {
      pop();
    }
  }

  //地區
  Widget _buildDropdownButton() {
    return BaseCard(
      height: 40,
      colors: currentTheme.buttonList,
      borderColors: currentTheme.borderListButton,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 10),
        child: Row(
          children: [
            Expanded(flex: 1, child: Text('area'.tr)),
            Expanded(
              flex: 5,
              child: DropdownButton(
                underline: SizedBox(),
                isExpanded: true,
                items: _countries.map((country) {
                  return DropdownMenuItem(
                    value: country,
                    child: Text(country.name),
                  );
                }).toList(),
                hint: Container(
                  child: FittedBox(
                    fit: BoxFit.scaleDown,
                    child: Text(
                      _selectCountry.name,
                      style: TextStyle(color: currentTheme.textBody2Color),
                    ),
                  ),
                ),
                onChanged: (value) {
                  setState(() {
                    _selectCountry = value;
                  });
                },
              ),
            ),
          ],
        ),
      ),
    );
  }

  //輸入電話
  Widget _buildInputPhone() {
    return Column(
      children: [
        SizedBox(height: 20),
        Text('pleaseConfirmYourCountry'.tr, textAlign: TextAlign.center),
        SizedBox(height: 20),
        //地區
        _buildDropdownButton(),

        //手機號碼
        BaseTextField(
          controller: _phoneController,
          textAlign: TextAlign.right,
          hintText: 'cellphoneNumber'.tr,
          keyboardType: TextInputType.phone,
          prefix: Text('+${_selectCountry.countryCode}'),
          onChanged: _onPhoneTextFieldChanged,
        ),
        SizedBox(height: 20),
        Text(
          '*' + 'malayThaiUsers'.tr,
          textAlign: TextAlign.right,
          style: TextStyle(color: Colors.grey),
        ),
        SizedBox(height: 20),
      ],
    );
  }

  //洗分驗證
  Widget _buildWithdrawalVerification() {
    return Column(
      children: [
        //手機號碼
        BaseCard(
          margin: EdgeInsets.only(top: 19),
          height: 38,
          colors: currentTheme.buttonList,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 8),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text('cellphoneNumber'.tr),
                Expanded(
                  child: Container(),
                ),
                Text('+' + _phoneController.text),
              ],
            ),
          ),
        ),
        SizedBox(height: 20),
      ],
    );
  }

  String get titleText {
    if (widget.type != 2) {
      return 'phoneVerification'.tr;
    }
    return 'forget'.tr;
  }

  @override
  Widget build(BuildContext context) {
    return BaseScaffold(
      appbarBackgroundIndex: 0,
      bodyBackgroundIndex: 0,
      appbarTitle: AppbarTitleText(
        titleText,
      ),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 20.0),
        child: _initializing
            ? Center(child: CircularProgressIndicator())
            : SingleChildScrollView(
                child: Column(
                  children: [
                    SizedBox(height: 20),
                    if (widget.type == 4) _buildWithdrawalVerification(),
                    if (widget.type != 4) _buildInputPhone(),
                    _remainingTime > 0
                        ?
                        //發送簡訊
                        BaseCard(
                            color: Colors.grey[900],
                            width: double.infinity,
                            padding: EdgeInsets.all(6),
                            child: Text(
                              'regainVerificationCode'.tr +
                                  _remainingTime.toString(),
                              style: TextStyle(color: Colors.grey),
                            ),
                          )
                        : BaseButton(
                            colors: currentTheme.buttonWarning,
                            width: double.infinity,
                            text: 'sendANewsLetter'.tr,
                            onPressed: _checkInputInfo,
                          ),
                    //驗證碼
                    BaseTextField(
                      controller: _verificationCodeController,
                      textAlign: TextAlign.right,
                      hintText: 'pleaseEnterVerificationCode'.tr,
                      keyboardType: TextInputType.phone,
                      prefix: Text('verifycode'.tr),
                    ),
                    SizedBox(height: 20),
                    BaseButton(
                      colors: currentTheme.buttonPrimary,
                      width: double.infinity,
                      text: 'finish'.tr,
                      onPressed: () async {
                        _checkValidateCode();
                      },
                    )
                  ],
                ),
              ),
      ),
    );
  }
}
