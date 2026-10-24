import 'package:flutter/material.dart';
import 'package:futter_web_frontend/controller/template_controller.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';
import 'package:qr_flutter/qr_flutter.dart';
import 'package:futter_web_frontend/widgets/base/appbar_title_text.dart';

import 'package:get/get.dart';

class UnlimitQRCodePage extends StatelessWidget {
  final code;
  final url;
  UnlimitQRCodePage({this.code, this.url});

  @override
  Widget build(BuildContext context) {
    return BaseScaffold(
      appbarBackgroundIndex: 0,
      bodyBackgroundIndex: 0,
      appbarTitle: AppbarTitleText('Agent_MyQRCode'.tr),
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Text(
            '${'Agent_PromoteCode'.tr}: $code',
            style: TextStyle(fontSize: 22),
          ),
          Padding(
            padding: EdgeInsets.symmetric(vertical: 32),
            child: Container(
              color: Colors.white,
              child: QrImage(
                data: url,
                size: 275,
              ),
            ),
          ),
          Image.network(
            TemplateController.to.template.value.logoMobile,
            width: 200,
          ),
        ],
      ),
    );
  }
}
