import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:futter_web_frontend/controller/auth_controller.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:futter_web_frontend/widgets/unlimit/announcement_dialog.dart';
import 'package:get/get.dart';

class ChatDialog extends StatelessWidget {
  void _launchurl() async {
    String url = AuthController.to.chatUrl;
    await openBrowser(url);
  }

  Widget _buildTitle(String text) {
    return Padding(
      padding: const EdgeInsets.only(top: 20),
      child: Text(
        text,
        style: TextStyle(fontSize: 10, fontWeight: FontWeight.bold),
      ),
    );
  }

  Widget _buildDetail(String text) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 2),
      child: Text(
        text,
        style: TextStyle(fontSize: 10, color: Colors.grey),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return AnnouncementDialog(
      title: Text(
        'Talk_QA_BigTitle'.tr,
        textAlign: TextAlign.center,
        style: TextStyle(color: currentTheme.textHead, fontSize: 15),
      ),
      message: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          //方案1
          _buildTitle(
              '${'Talk_QA_Solution'.tr} 1: ${'Talk_QA_Solution_1_Title'.tr}'),
          Row(
            children: [
              _buildDetail('Talk_QA_Solution_1_Description'.tr),
              RichText(
                text: TextSpan(
                  text: 'BackupUrl'.tr,
                  style: TextStyle(fontSize: 10, color: Colors.blue),
                  recognizer: TapGestureRecognizer()..onTap = _launchurl,
                ),
              ),
            ],
          ),
          //方案2
          _buildTitle(
              '${'Talk_QA_Solution'.tr}  2: ${'Talk_QA_Solution_2_Title'.tr}'),
          _buildDetail('- ' + 'Talk_QA_Solution_2_Description_Safari'.tr),
          _buildDetail('- ' + 'Talk_QA_Solution_2_Description_Chrome'.tr),
        ],
      ),
    );
  }
}
