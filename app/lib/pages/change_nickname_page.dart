import 'package:flutter/material.dart';
import 'package:futter_web_frontend/controller/member_controller.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/http_service.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:futter_web_frontend/widgets/base/appbar_title_text.dart';
import 'package:futter_web_frontend/widgets/base/base_button.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';
import 'package:futter_web_frontend/widgets/base/base_text_field.dart';

import 'package:get/get.dart';

class ChangeNicknamePage extends StatelessWidget {
  final TextEditingController _nicknameController = TextEditingController();

  //提交
  void _submit() async {
    try {
      showLoadingDialog();
      await updateNickname(_nicknameController.text);
      pop();
      showMessageDialog(message: 'successfully'.tr);
    } catch (error) {
      pop();
    }
  }

  @override
  Widget build(BuildContext context) {
    _nicknameController.text = MemberController.to.member.value.nickname;
    return BaseScaffold(
      appbarBackgroundIndex: 0,
      bodyBackgroundIndex: 0,
      appbarTitle: AppbarTitleText('setNickname'.tr),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 20.0),
        child: SingleChildScrollView(
          child: Column(
            children: [
              BaseTextField(
                prefix: Text('nickname'.tr),
                controller: _nicknameController,
                hintText: '',
              ),
              SizedBox(height: 50),
              BaseButton(
                text: 'store'.tr,
                colors: currentTheme.buttonPrimary,
                onPressed: _submit,
              ),
            ],
          ),
        ),
      ),
    );
  }
}
