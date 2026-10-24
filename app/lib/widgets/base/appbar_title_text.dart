import 'package:flutter/material.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';

class AppbarTitleText extends StatelessWidget {
  final String text;
  AppbarTitleText(this.text);
  @override
  Widget build(BuildContext context) {
    return Text(
      text,
      style: TextStyle(color: currentTheme.textAppbar),
    );
  }
}
