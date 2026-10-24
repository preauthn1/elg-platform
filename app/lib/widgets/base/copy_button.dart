import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:get/get.dart';

class CopyButton extends StatelessWidget {
  final String copyText;
  CopyButton(this.copyText);
  @override
  Widget build(BuildContext context) {
    return IconButton(
      icon: Icon(
        Icons.content_copy,
        size: 18,
        color: currentTheme.icon,
      ),
      onPressed: () {
        Clipboard.setData(new ClipboardData(text: copyText));
        ScaffoldMessenger.of(context).hideCurrentSnackBar();
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            duration: Duration(seconds: 2),
            backgroundColor: currentTheme.container,
            content: Row(
              children: [
                Icon(
                  Icons.error,
                  color: currentTheme.icon,
                ),
                SizedBox(width: 10),
                Text(
                  'successfulCopy'.tr,
                  style: TextStyle(
                    color: currentTheme.textBody2Color,
                  ),
                ),
              ],
            ),
          ),
        );
      },
    );
  }
}
