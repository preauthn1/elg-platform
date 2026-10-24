import 'package:flutter/material.dart';
import 'package:futter_web_frontend/pages/about_detail_page.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:futter_web_frontend/widgets/base/appbar_title_text.dart';
import 'package:futter_web_frontend/widgets/base/base_list_button.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';

import 'package:get/get.dart';

class AboutPage extends StatelessWidget {
  final String _name = 'platform'.tr;
  final String _email = 'email';
  final List<Map<String, String>> _items = [
    //0:常見問題
    {
      'title': 'commonProblem'.tr,
      'detail':
          '${'technicalSupport'.tr}\n${'about_content_common_problem'.tr}',
    },
    //1:條款與政策
    {
      'title': 'termsAndConditions'.tr,
      'detail': 'about_content_terms_and_conditions1'.tr +
          'about_content_terms_and_conditions2'.tr +
          'about_content_terms_and_conditions3'.tr,
    },
    //2:隱私權政策
    {
      'title': 'privacyPolicy'.tr,
      'detail': 'about_content_privacy_policy'.tr,
    },
    //3.Cookies政策
    {
      'title': 'cookiesPolicy'.tr,
      'detail': 'about_content_cookies_policy'.tr,
    },
  ];

  void pushToDetail(String titleKey, String detail) {
    detail = detail.replaceAll("{name}", _name);
    detail = detail.replaceAll("{email}", _email);
    push(AboutDetailPage(titleKey, detail));
  }

  @override
  Widget build(BuildContext context) {
    return BaseScaffold(
      appbarBackgroundIndex: 0,
      bodyBackgroundIndex: 0,
      appbarTitle: AppbarTitleText('about'.tr),
      child: Column(
        children: [
          ..._items.map(
            (item) => BaseListButton(
              child: Text(item['title']),
              colors: currentTheme.buttonList,
              onPressed: () => pushToDetail(item['title'], item['detail']),
            ),
          ),
        ],
      ),
    );
  }
}
