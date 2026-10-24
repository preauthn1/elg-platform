import 'package:flutter/material.dart';
import 'package:futter_web_frontend/controller/auth_controller.dart';
import 'package:futter_web_frontend/models/http_exception.dart';
import 'package:futter_web_frontend/pages/phone_verification_page.dart';
import 'package:futter_web_frontend/pages/tabs_page.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/widgets/base/base_button.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';
import 'package:futter_web_frontend/widgets/base/base_text_field.dart';
import 'package:futter_web_frontend/widgets/signin/signin_bottom_button.dart';
import 'package:get/get.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:futter_web_frontend/widgets/base/appbar_title_text.dart';

class SignupPage extends StatelessWidget {
  final TextEditingController _usernameController = TextEditingController();
  final TextEditingController _passwordController = TextEditingController();
  final TextEditingController _passwordConfirmationController =
      TextEditingController();
  final TextEditingController _codeController = TextEditingController();

  //登入
  Future<void> _login() async {
    try {
      await AuthController.to.login(
        _usernameController.text.trim(),
        _passwordController.text,
      );
      Get.offAll(TabsPage());
    } on HttpException catch (error) {
      pop();
      if (error.code == '9007') {
        push(
          PhoneVerificationPage(
            1,
            error.data,
            onSuccess: (token, username) {
              _login();
            },
          ),
        );
      }
    } catch (error) {}
  }

  void signup() async {
    try {
      showLoadingDialog();
      await AuthController.to.create(
        _usernameController.text.trim(),
        _passwordController.text,
        _passwordConfirmationController.text,
        _codeController.text.trim(),
      );
      await _login();
    } catch (error) {
      pop();
    }
  }

  void _onChangeUsername(String value) {
    _usernameController.text =
        value.replaceAll(new RegExp(r'[^0-9a-zA-Z]'), '');
    if (value.length > 10) {
      _usernameController.text = value.characters.take(10).toString();
    }
    // cursor移到最後
    _usernameController.selection = TextSelection.fromPosition(
      TextPosition(offset: _usernameController.text.length),
    );
  }

  @override
  Widget build(BuildContext context) {
    return BaseScaffold(
      appbarBackgroundIndex: 0,
      bodyBackgroundIndex: 0,
      appbarTitle: AppbarTitleText('signUp'.tr),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 20.0),
        child: SingleChildScrollView(
          child: Column(
            children: [
              //帳號
              BaseTextField(
                prefix: Text('account'.tr),
                hintText: 'pleaseEnterYourAccountNumber'
                    .tr
                    .replaceNamedArgs({'min': '4', 'max': '10'}),
                controller: _usernameController,
                textAlign: TextAlign.right,
                onChanged: (value) => _onChangeUsername(value),
              ),
              Container(
                width: double.infinity,
                child: Text(
                  'accountFormatExample'.tr,
                  style: TextStyle(color: currentTheme.icon),
                ),
              ),
              //密碼
              BaseTextField(
                prefix: Text('password'.tr),
                hintText: 'setpassword'.tr +
                    ':' +
                    'pleaseEnterYourAccountNumber'
                        .tr
                        .replaceNamedArgs({'min': '4', 'max': '10'}),
                controller: _passwordController,
                textAlign: TextAlign.right,
                obscureText: true,
              ),

              //確認密碼
              BaseTextField(
                prefix: Text('confirmpassword'.tr),
                hintText: 'confirmpassword'.tr +
                    ':' +
                    'pleaseEnterYourAccountNumber'
                        .tr
                        .replaceNamedArgs({'min': '4', 'max': '10'}),
                controller: _passwordConfirmationController,
                textAlign: TextAlign.right,
                obscureText: true,
              ),

              //分享碼
              BaseTextField(
                prefix: Text('sharingCode'.tr),
                hintText: 'pleaseEnterTheSharingCode'.tr,
                controller: _codeController,
                textAlign: TextAlign.right,
              ),
              SizedBox(height: 50),
              BaseButton(
                colors: currentTheme.buttonPrimary,
                text: 'signUp'.tr,
                onPressed: signup,
              ),
              SizedBox(height: 30),
              SignInBottomButton(),
            ],
          ),
        ),
      ),
    );
  }
}
