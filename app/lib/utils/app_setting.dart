import 'dart:convert';
import 'dart:math';

import 'package:flutter/material.dart';
import 'package:futter_web_frontend/controller/template_controller.dart';
import 'package:futter_web_frontend/models/environment.dart';
import 'package:futter_web_frontend/models/l1theme.dart';
import 'package:futter_web_frontend/theme/l1_c711_theme.dart';
import 'package:futter_web_frontend/theme/l1_c712_theme.dart';
import 'package:futter_web_frontend/theme/l1_c713_theme.dart';
import 'package:futter_web_frontend/utils/app_transitions.dart';
import 'package:futter_web_frontend/utils/env_list.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:get/get.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:http/http.dart' as http;

bool _isRelease = false;
bool get isRelease => _isRelease;
int _envId = 2;
Environment _currentEnv = envList[1];
Environment get currentEnv => _currentEnv;
L1Theme currentTheme = l1C711Theme;

//ios載點
const downloadUrlIOS =
    'itms-services://?action=download-manifest&url=https://example.invalid/APPDL/IOS/Browser/VWorld.plist';
//apk載點
const downloadUrlAndroid =
    'https://example.invalid/APPDL/Android/Browser/VWorld.apk';

Map<String, dynamic> _currentLanguage = supportedLocales[0];
Map<String, dynamic> get currentLanguage => _currentLanguage;

///初始化
Future<void> init() async {
  try {
    if (_isRelease) {
      ///Release連正式環境
      await changeEnv(_envId);
    } else {
      int envId = await loadEnv();
      await changeEnv(envId);
    }

    //載入語言
    Locale locale = supportedLocales[0]['locale'];
    final prefs = await SharedPreferences.getInstance();
    if (prefs.containsKey('languageCode')) {
      final String code = prefs.getString('languageCode');
      locale = supportedLocales
          .firstWhere((element) => element['languageCode'] == code)['locale'];
    }
    _setLocale(locale);
  } catch (e) {
    throw (e);
  }

  Get.isLogEnable = false;
  Get.fallbackLocale = supportedLocales[0]['locale'];
}

//獲取最新語言包
Future<void> fetchAndSetLanguage({
  Map<String, String> obj,
  String code,
}) async {
  if (obj == null) obj = _currentLanguage['languageObj'];
  if (code == null) code = _currentLanguage['languageCode'];
  final random = Random().nextInt(100000000);
  try {
    Uri uri = Uri.parse(
        'https://${currentEnv.domain}/static/lang/$code.json?t=$random');
    final response = await http.get(uri).timeout(
      Duration(seconds: 10),
      onTimeout: () {
        throw Exception('timeout');
      },
    );
    final jsonData =
        json.decode(utf8.decode(response.bodyBytes)) as Map<String, dynamic>;
    jsonData.forEach((key, value) {
      obj[key] = value;
    });
  } catch (error) {
    throw Exception('fetch language error');
  }

  //所有語言
  // await Future.forEach(supportedLocales, (element) async {
  //   final response = await http.get(
  //       'https://example.invalid/vworld/language/${element['languageCode']}.json');
  //   final jsonData =
  //       json.decode(utf8.decode(response.bodyBytes)) as Map<String, dynamic>;
  //   final languageObj = element['languageObj'];
  //   jsonData.forEach((key, value) {
  //     languageObj[key] = value;
  //   });
  // });
}

Map<String, dynamic> _getLanguageMap(Locale locale) {
  return supportedLocales.firstWhere((element) => element['locale'] == locale);
}

void _setLocale(Locale locale) {
  Get.updateLocale(locale);
  _currentLanguage = _getLanguageMap(locale);
}

///切換語言
void changeLocale(Locale locale) async {
  showLoadingDialog();
  try {
    final prefs = await SharedPreferences.getInstance();
    var languageMap = _getLanguageMap(locale);
    await fetchAndSetLanguage(
      obj: languageMap['languageObj'],
      code: languageMap['languageCode'],
    );
    _setLocale(locale);
    prefs.setString('languageCode', _currentLanguage['languageCode']);
    await TemplateController.to.fetchAndSetTemplate();
  } catch (error) {
    throw error;
  }
  pop();
}

Future<void> changeEnv(int id) async {
  _envId = id;
  _currentEnv = envList.firstWhere(
    (element) => element.id == id,
  );
  try {
    if (id != 0) {
      await currentEnv.updateAPI();
      await currentEnv.updateDomain();
    }
  } catch (e) {
    throw e;
  }
}

void saveEnv(int id) async {
  final prefs = await SharedPreferences.getInstance();
  await prefs.setInt('environment', id);
}

Future<int> loadEnv() async {
  if (isRelease) return _envId;
  final prefs = await SharedPreferences.getInstance();
  int envId = prefs.getInt('environment');
  if (envId == null) envId = 0;
  return envId;
}

void saveTemplate(String template) async {
  final prefs = await SharedPreferences.getInstance();
  await prefs.setString('templateId', template);
}

Future<String> _loadCustomTemplate() async {
  final prefs = await SharedPreferences.getInstance();
  String templateId = prefs.getString('templateId');
  if (templateId == "Default")
    templateId = TemplateController.to.template.value.name;
  return templateId;
}

Future<void> refreshTemplate() async {
  //開發環境可自選Template
  //正式環境打包後無法更改Template
  if (!isRelease) {
    String template = await _loadCustomTemplate();
    if (template == "C712") {
      currentTheme = l1C712Theme;
    } else if (template == "C713") {
      currentTheme = l1C713Theme;
    } else {
      currentTheme = l1C711Theme;
    }
  }
  Get.changeTheme(currentTheme.theme);
}
