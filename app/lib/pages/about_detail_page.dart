import 'package:flutter/material.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/widgets/base/appbar_title_text.dart';
import 'package:futter_web_frontend/widgets/base/base_card.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';

class AboutDetailPage extends StatelessWidget {
  final String title;
  final String detail;
  AboutDetailPage(this.title, this.detail);

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: BaseScaffold(
        appbarBackgroundIndex: 0,
        bodyBackgroundIndex: 0,
        appbarTitle: AppbarTitleText(title),
        child: SingleChildScrollView(
          child: BaseCard(
            colors: currentTheme.buttonList,
            margin: EdgeInsets.symmetric(horizontal: 15, vertical: 15),
            padding: EdgeInsets.symmetric(horizontal: 10, vertical: 5),
            child: Text(detail),
          ),
        ),
      ),
    );
  }
}
