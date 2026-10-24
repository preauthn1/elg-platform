import 'dart:ui';
import 'package:futter_web_frontend/translations/en.dart';
import 'package:futter_web_frontend/translations/id_id.dart';
import 'package:futter_web_frontend/translations/ms_my.dart';
import 'package:futter_web_frontend/translations/ta_in.dart';
import 'package:futter_web_frontend/translations/th_th.dart';
import 'package:futter_web_frontend/translations/vi_vn.dart';
import 'package:futter_web_frontend/translations/zh_cn.dart';
import 'package:futter_web_frontend/translations/zh_tw.dart';
import 'package:get/get.dart';

class AppTransitions extends Translations {
  @override
  Map<String, Map<String, String>> get keys => {
        'en_US': en,
        'id_ID': idID,
        'ms_MY': msMY,
        'vi_VN': viVN,
        'zh_TW': zhTW,
        'zh_CN': zhCN,
        'th_TH': thTH,
        'ta_IN': taIN,
      };
}

///支援語系
final List<Map<String, dynamic>> supportedLocales = [
  {
    'name': 'English',
    'languageCode': 'en',
    'locale': Locale('en', 'US'),
    'imageFile': 'assets/images/country/uk.svg',
    'languageObj': en,
  },
  {
    'name': '简体中文',
    'languageCode': 'zh-cn',
    'locale': Locale('zh', 'CN'),
    'imageFile': 'assets/images/country/cn.svg',
    'languageObj': zhCN,
  },
  {
    'name': '繁體中文',
    'languageCode': 'zh-tw',
    'locale': Locale('zh', 'TW'),
    'imageFile': 'assets/images/country/hk.svg',
    'languageObj': zhTW,
  },
  {
    'name': 'Melayu',
    'languageCode': 'ms-my',
    'locale': Locale('ms', 'MY'),
    'imageFile': 'assets/images/country/my.svg',
    'languageObj': msMY,
  },
  {
    'name': 'Indonesia',
    'languageCode': 'id-id',
    'locale': Locale('id', 'ID'),
    'imageFile': 'assets/images/country/id.svg',
    'languageObj': idID,
  },
  {
    'name': 'Tiếng Việt',
    'languageCode': 'vi-vn',
    'locale': Locale('vi', 'VN'),
    'imageFile': 'assets/images/country/vn.svg',
    'languageObj': viVN,
  },
  {
    'name': 'ไทย',
    'languageCode': 'th-th',
    'locale': Locale('th', 'TH'),
    'imageFile': 'assets/images/country/th.svg',
    'languageObj': thTH,
  },
  {
    'name': 'தமிழ்',
    'languageCode': 'ta-in',
    'locale': Locale('ta', 'IN'),
    'imageFile': 'assets/images/country/in.svg',
    'languageObj': taIN,
  },
];
