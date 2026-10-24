import 'dart:io';
import 'package:flutter/material.dart';
import 'package:futter_web_frontend/controller/template_controller.dart';
import 'package:get/get.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:url_launcher/url_launcher.dart';

class SignInBottomButton extends StatelessWidget {
  Widget _buildBottomButton({String title, Widget image, Function onPressed}) {
    return Container(
      height: 120,
      width: 100,
      child: TextButton(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            image,
            SizedBox(height: 4),
            Text(
              title,
              maxLines: 3,
              textAlign: TextAlign.center,
              style: TextStyle(
                fontSize: 12,
                color: currentTheme.textBody2Color,
              ),
            ),
          ],
        ),
        onPressed: onPressed,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceEvenly,
      children: [
        //客服
        if (TemplateController
                .to.template.value.customizedSetting.csIconVisible ==
            '1')
          _buildBottomButton(
            title: 'customerService'.tr,
            image: Image.network(
              TemplateController
                  .to.template.value.customizedSetting.csIconPicUrl,
              height: 50,
            ),
            onPressed: () => launch(TemplateController
                .to.template.value.customizedSetting.csIconLinkUrl),
          ),
        /*
        if (TemplateController.to.template.value.hasApp == '1')
          //下載APP
          _buildBottomButton(
            title: 'downLoadAPP'.tr,
            image: Image.network(
              TemplateController.to.template.value.icon,
              height: 50,
            ),
            onPressed: () {
              if (Platform.isAndroid) {
                launch(downloadUrlAndroid);
              } else if (Platform.isIOS) {
                launch(downloadUrlIOS);
              }
            },
          ),
          */
        if (!isRelease)
          _buildBottomButton(
            title: '更新',
            image: Icon(
              Icons.system_update_alt,
              size: 50,
            ),
            onPressed: () {
              if (Platform.isAndroid) {
                launch(
                    'https://example.invalid/s/lavft0w0bgg3i9x/app-release.apk');
              } else if (Platform.isIOS) {
                launch(
                    'itms-services://0.0.0.0?action=download-manifest&url=https://example.invalid/s/o90is0qwjtftctu/manifest.plist');
              }
            },
          ),
      ],
    );
  }
}
