// import 'dart:io';
import 'package:flutter/material.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:futter_web_frontend/widgets/base/base_button.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';
// import 'package:image_gallery_saver/image_gallery_saver.dart';
// import 'package:permission_handler/permission_handler.dart';
// import 'package:screenshot/screenshot.dart';
import 'package:qr_flutter/qr_flutter.dart';
import 'package:futter_web_frontend/widgets/base/appbar_title_text.dart';

import 'package:get/get.dart';

class WalletTopupQrcodePage extends StatelessWidget {
  final String code;
  WalletTopupQrcodePage(this.code);
  // ScreenshotController _screenshotController = ScreenshotController();

  void _leave() async {
    // await Permission.storage.request().isGranted;
    // await _screenshotController.capture().then((File image) async {
    //   final result = await ImageGallerySaver.saveImage(image.readAsBytesSync());
    // }).catchError((onError) {
    //   print(onError);
    // });
    pop();
  }

  @override
  Widget build(BuildContext context) {
    return BaseScaffold(
      appbarBackgroundIndex: 0,
      bodyBackgroundIndex: 0,
      appbarTitle: AppbarTitleText('storedValuPoints'.tr),
      leading: Container(),
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          // Screenshot(
          //   controller: _screenshotController,
          //   child: Container(
          //     color: currentTheme.textColors[0],
          //     child: QrImage(
          //       data: code,
          //       size: 275,
          //     ),
          //   ),
          // ),
          Container(
            color: Colors.white,
            child: QrImage(
              data: code,
              size: 275,
            ),
          ),
          SizedBox(height: 10),
          Text(
            'POS QR Code',
            style: TextStyle(fontSize: 30),
            textAlign: TextAlign.center,
          ),
          SizedBox(height: 80),
          BaseButton(
            colors: currentTheme.buttonPrimary,
            text: 'depositQrcodePrompt'.tr,
            onPressed: _leave,
          ),
        ],
      ),
    );
  }
}
