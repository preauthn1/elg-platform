import 'package:flutter/material.dart';
import 'package:futter_web_frontend/controller/auth_controller.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:futter_web_frontend/widgets/base/appbar_title_text.dart';
import 'package:futter_web_frontend/widgets/base/base_button.dart';
import 'package:futter_web_frontend/widgets/base/base_card.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';
import 'package:futter_web_frontend/widgets/base/base_text_field.dart';

import 'package:get/get.dart';

class ChangePasswordPage extends StatelessWidget {
  final TextEditingController _passwordController = TextEditingController();
  final TextEditingController _newPasswordController = TextEditingController();
  final TextEditingController _newPasswordConfirmationController =
      TextEditingController();

  //提交
  void _submit() async {
    try {
      showLoadingDialog();
      await AuthController.to.changePassword(
        _passwordController.text,
        _newPasswordController.text,
        _newPasswordConfirmationController.text,
      );
      pop();
      showMessageDialog(message: 'successfully'.tr);
    } catch (error) {
      pop();
    }
  }

  @override
  Widget build(BuildContext context) {
    return BaseScaffold(
      appbarBackgroundIndex: 0,
      bodyBackgroundIndex: 0,
      appbarTitle: AppbarTitleText('changePassword'.tr),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 20.0),
        child: SingleChildScrollView(
          child: Column(
            children: [
              //帳號
              BaseCard(
                margin: EdgeInsets.only(top: 30),
                height: 40,
                colors: currentTheme.buttonList,
                borderColors: currentTheme.borderListButton,
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 8),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Text('account'.tr),
                      Text(AuthController.to.auth.value.username),
                    ],
                  ),
                ),
              ),

              //舊密碼
              BaseTextField(
                controller: _passwordController,
                prefix: Text('oldPassword'.tr),
                hintText: 'oldPassword'.tr,
                obscureText: true,
              ),
              //新密碼
              BaseTextField(
                controller: _newPasswordController,
                prefix: Text('newPassword'.tr),
                hintText: 'newPassword'.tr,
                obscureText: true,
              ),
              //確認密碼
              BaseTextField(
                controller: _newPasswordConfirmationController,
                prefix: Text('confirmpassword'.tr),
                hintText: 'confirmpassword'.tr,
                obscureText: true,
              ),
              SizedBox(height: 50),
              //提交
              BaseButton(
                colors: currentTheme.buttonPrimary,
                text: 'finish'.tr,
                onPressed: _submit,
              ),
            ],
          ),
        ),
      ),
    );
  }
}
