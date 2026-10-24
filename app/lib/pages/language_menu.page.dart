import 'package:flutter/material.dart';
import 'package:flutter_svg/flutter_svg.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/app_transitions.dart';
import 'package:futter_web_frontend/widgets/base/appbar_title_text.dart';
import 'package:futter_web_frontend/widgets/base/base_list_button.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';

import 'package:get/get.dart';

class LanguageMenu extends StatefulWidget {
  @override
  _LanguageMenuState createState() => _LanguageMenuState();
}

class _LanguageMenuState extends State<LanguageMenu> {
  @override
  Widget build(BuildContext context) {
    return BaseScaffold(
      appbarBackgroundIndex: 0,
      bodyBackgroundIndex: 0,
      appbarTitle: AppbarTitleText('language'.tr),
      child: SingleChildScrollView(
          child: Column(
        children: [
          ...supportedLocales.map(
            (language) => BaseListButton(
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    //圖示
                    SvgPicture.asset(
                      language['imageFile'],
                      width: 30,
                      height: 20,
                      fit: BoxFit.fill,
                    ),
                    SizedBox(width: 10),
                    //語言
                    Text(
                      language['name'],
                      style: TextStyle(
                        color: language['languageCode'] ==
                                currentLanguage['languageCode']
                            ? currentTheme.theme.textTheme.button.color
                            : currentTheme.textMuted,
                      ),
                    ),
                  ],
                ),
                colors:
                    language['languageCode'] == currentLanguage['languageCode']
                        ? currentTheme.buttonDanger
                        : currentTheme.buttonList,
                onPressed: () {
                  setState(() {
                    changeLocale(language['locale']);
                  });
                }),
          ),
        ],
      )),
    );
  }
}
