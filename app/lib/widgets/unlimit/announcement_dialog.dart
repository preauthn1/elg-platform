import 'package:flutter/material.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/widgets/base/base_card.dart';
import 'package:get/get.dart';

class AnnouncementDialog extends StatelessWidget {
  final Widget title;
  final Widget message;
  const AnnouncementDialog({this.title, this.message});

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.all(Radius.circular(16.0))),
      contentPadding: EdgeInsets.all(0),
      content: BaseCard(
        width: double.infinity,
        color: currentTheme.container,
        child: Stack(
          children: [
            Container(
              padding: EdgeInsets.symmetric(horizontal: 12, vertical: 25),
              margin: EdgeInsets.all(10),
              color: currentTheme.container,
              width: double.infinity,
              constraints: BoxConstraints(minHeight: 300),
              child: SingleChildScrollView(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    if (title != null)
                      //標題
                      Center(
                        child: title,
                      ),
                    if (message != null)
                      //訊息
                      message,
                  ],
                ),
              ),
            ),
            Positioned(
              top: 0,
              right: 0,
              child: IconButton(
                icon: Icon(
                  Icons.close,
                  color: currentTheme.icon,
                ),
                onPressed: Get.back,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
