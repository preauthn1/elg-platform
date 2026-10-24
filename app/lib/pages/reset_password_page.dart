import 'package:flutter/material.dart';
import 'package:futter_web_frontend/controller/auth_controller.dart';
import 'package:futter_web_frontend/pages/tabs_page.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/widgets/base/base_button.dart';
import 'package:futter_web_frontend/widgets/base/base_card.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';
import 'package:futter_web_frontend/widgets/base/base_text_field.dart';
import 'package:get/get.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:futter_web_frontend/widgets/base/appbar_title_text.dart';

class ResetPasswordPage extends StatelessWidget {
  final String token;
  final String username;
  ResetPasswordPage(this.token, this.username);

  final TextEditingController _newPasswordController = TextEditingController();
  final TextEditingController _newPasswordConfirmationController =
      TextEditingController();

  //提交
  void _submit() async {
    try {
      showLoadingDialog();
      await AuthController.to.resetPassword(
        token,
        username,
        _newPasswordController.text,
        _newPasswordConfirmationController.text,
      );
      await AuthController.to.login(
        username,
        _newPasswordController.text,
      );
      Get.offAll(TabsPage());
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
                      Text(username),
                    ],
                  ),
                ),
              ),

              //新密碼
              BaseTextField(
                controller: _newPasswordController,
                hintText: 'newPassword'.tr,
                prefix: Text('newPassword'.tr),
                obscureText: true,
              ),
              //確認密碼
              BaseTextField(
                controller: _newPasswordConfirmationController,
                hintText: 'confirmpassword'.tr,
                prefix: Text('confirmpassword'.tr),
                obscureText: true,
              ),
              SizedBox(height: 50),
              //提交
              BaseButton(
                colors: currentTheme.buttonPrimary,
                text: 'finish'.tr,
                onPressed: _submit,
              )
            ],
          ),
        ),
      ),
    );
  }
}
