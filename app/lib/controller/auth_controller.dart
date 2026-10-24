import 'dart:convert';
import 'package:futter_web_frontend/models/auth.dart';
import 'package:futter_web_frontend/pages/signin_page.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:get/get.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:futter_web_frontend/controller/template_controller.dart';
import '../utils/app_setting.dart';
import '../utils/http_service.dart';
import '../utils/secret.dart';

class AuthController extends GetxController {
  final auth = Auth().obs;
  static AuthController get to => Get.find();

  //儲存帳密資訊
  Future<void> _saveUserInfo(String username, String password) async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final userdata = json.encode({
        'username': username,
        'password': password,
      });
      final userdataEncryption = encrypt(userdata);
      await prefs.setString('userdata', userdataEncryption);
    } catch (error) {}
  }

  //註冊
  Future<void> create(
    String username,
    String password,
    String passwordConfirmation,
    String code,
  ) async {
    final parameters = {
      "username": username,
      "password": password,
      "password_confirmation": passwordConfirmation,
      "code": code,
      "domain": currentEnv.domain,
    };
    try {
      await post(
        path: 'api/create',
        parameters: parameters,
      );
    } catch (error) {
      throw (error);
    }
  }

  //登入
  Future<void> login(String username, String password) async {
    String deviceid = await deviceId;
    final parameters = {
      "username": username,
      "password": password,
      "device_id": deviceid,
      "app": true,
      "domain": currentEnv.domain,
    };
    try {
      await _saveUserInfo(username, password);
      final responseData = await post(
        path: 'api/login',
        parameters: parameters,
      );
      final userData = responseData['data'];
      auth.update((value) {
        value.id = userData['id'];
        value.username = userData['username'];
        value.email = userData['email'];
        value.nickname = userData['nickname'];
        value.balance = userData['balance'];
        value.image = userData['image'];
        value.firstName = userData['first_name'];
        value.lastName = userData['last_name'];
        value.mobile = userData['mobile'];
        value.eventMode = userData['event_mode'];
        value.isForceUnlockEventMode = userData['is_force_unlock_event_mode'];
        value.lastLogin = userData['last_login'];
        value.token = [REDACTED]'token'];
        value.csApiUser = userData['CS_API_USER'];
        value.csApiKey = [REDACTED]'CS_API_KEY'];
        value.shareCode = userData['SHARE_CODE'];
      });
      await TemplateController.to.fetchAndSetTemplate();
    } catch (error) {
      throw (error);
    }
  }

  void clear() {
    auth.update((value) {
      value.id = null;
      value.username = null;
      value.email = null;
      value.nickname = null;
      value.balance = null;
      value.image = null;
      value.firstName = null;
      value.lastName = null;
      value.mobile = null;
      value.eventMode = null;
      value.isForceUnlockEventMode = null;
      value.lastLogin = null;
      value.token = [REDACTED];
      value.csApiUser = null;
      value.csApiKey = [REDACTED];
      value.shareCode = null;
    });
    Get.offAll(SigninPage());
  }

  //登出
  Future<void> logout() async {
    try {
      await post(path: 'api/logout');
    } catch (error) {}
    clear();
  }

  //變更會員密碼
  Future<void> changePassword(String password, String newPassword,
      String newPasswordConfirmation) async {
    final parameters = {
      "password": password,
      "new_password": newPassword,
      "new_password_confirmation": newPasswordConfirmation,
    };
    try {
      await post(
        path: 'api/changePassword',
        parameters: parameters,
      );
    } catch (error) {
      throw (error);
    }
  }

  //重設會員密碼
  Future<void> resetPassword(
    String temporaryToken,
    String username,
    String newPassword,
    String newPasswordConfirmation,
  ) async {
    final parameters = {
      "token": temporaryToken,
      "new_password": newPassword,
      "new_password_confirmation": newPasswordConfirmation,
    };
    try {
      await post(
        path: 'api/resetPassword',
        parameters: parameters,
      );
      await login(username, newPassword);
    } catch (error) {
      throw (error);
    }
  }

  String get chatUrl {
    final signData = json.encode({
      'thirdpartyId': auth.value.username,
      'agentuser': auth.value.csApiUser,
      'agentkey': auth.value.csApiKey,
      'lang': currentLanguage['languageCode'],
      'templateMain': currentTheme.name.toUpperCase(),
      'EventMode': '0'
    });
    final signDataSecret = [REDACTED];
    final url =
        currentEnv.apiChatPath + signDataSecret.replaceAll('/', '5s1g7er0A');
    return url;
  }
}
