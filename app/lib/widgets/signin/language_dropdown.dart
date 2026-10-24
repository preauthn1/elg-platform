import 'package:flutter/material.dart';
import 'package:flutter_svg/svg.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/app_transitions.dart';
import 'package:futter_web_frontend/widgets/base/base_card.dart';

class LanguageDropdown extends StatefulWidget {
  @override
  _LanguageDropdownState createState() => _LanguageDropdownState();
}

class _LanguageDropdownState extends State<LanguageDropdown> {
  Map _language = {};

  @override
  void initState() {
    super.initState();
    //初始化標題
    for (var language in supportedLocales) {
      if (language['languageCode'] == currentLanguage['languageCode']) {
        _language = language;
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return BaseCard(
      borderRadius: 30,
      width: 140,
      height: 40,
      color: currentTheme.container,
      child: Row(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          SizedBox(width: 5),
          DropdownButton(
            onChanged: (language) {
              setState(() {
                changeLocale(language['locale']);
                _language = language;
              });
            },
            hint: Row(
              children: [
                //圖示
                SvgPicture.asset(
                  _language['imageFile'],
                  width: 25,
                  height: 15,
                  fit: BoxFit.fill,
                ),
                SizedBox(width: 5),
                //語言
                Text(
                  _language['name'],
                  style: TextStyle(
                    color: currentTheme.textHead,
                  ),
                ),
              ],
            ),
            underline: SizedBox(),
            icon: Icon(
              Icons.keyboard_arrow_down,
              color: currentTheme.textHead,
            ),
            dropdownColor: currentTheme.container,
            items: supportedLocales.map((language) {
              return DropdownMenuItem(
                  value: language,
                  child: Row(
                    children: [
                      //圖示
                      SvgPicture.asset(
                        language['imageFile'],
                        width: 25,
                        height: 15,
                        fit: BoxFit.fill,
                      ),
                      SizedBox(width: 5),
                      //語言
                      Text(
                        language['name'],
                        style: TextStyle(
                          color: currentTheme.textHead,
                        ),
                      ),
                    ],
                  ));
            }).toList(),
          ),
        ],
      ),
    );
  }
}
