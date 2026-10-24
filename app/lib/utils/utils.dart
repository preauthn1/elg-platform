import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:connectivity/connectivity.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/material.dart';
import 'package:futter_web_frontend/models/advertisements.dart';
import 'package:futter_web_frontend/models/announcement_message.dart';
import 'package:futter_web_frontend/models/vendor.dart';
import 'package:futter_web_frontend/models/version.dart';
import 'package:futter_web_frontend/pages/lobby_game_agree_to_enter_page.dart';
import 'package:futter_web_frontend/pages/lobby_game_launch_page.dart';
import 'package:futter_web_frontend/pages/lobby_game_list_page.dart';
import 'package:futter_web_frontend/pages/webview_page.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/widgets/base/base-sheet-button.dart';
import 'package:futter_web_frontend/widgets/base/base_button.dart';
import 'package:futter_web_frontend/widgets/base/base_card.dart';

import 'package:futter_web_frontend/widgets/base/base_dialog.dart';
import 'package:futter_web_frontend/widgets/unlimit/announcement_dialog.dart';
import 'package:image_picker/image_picker.dart';
import 'package:intl/intl.dart';
import 'package:get/get.dart';
import 'package:package_info/package_info.dart';
import 'package:url_launcher/url_launcher.dart';

import 'http_service.dart';

///日期格式
String dateFormatter(String date, {String format = 'yyyy/MM/dd HH:mm'}) {
  if (date == null || date.isEmpty) return '';
  return DateFormat(format)
      .format(DateFormat('yyyy-MM-dd HH:mm:ss').parse(date));
}

///數字格式
String numberFormatter(dynamic value, {String format = '#,##0.00'}) {
  if (value is String) {
    value = value.replaceAll(',', '');
    value = num.tryParse(value);
  }
  value = (value * 100).floor() / 100;
  return NumberFormat(format).format(value);
}

bool get isDsg => currentEnv.name == 'dsg';

Future<String> get deviceId async {
  String identifier = '';
  try {
    identifier = await FirebaseMessaging.instance.getToken();
  } catch (error) {
    print(error);
  }

/*
  final DeviceInfoPlugin deviceInfoPlugin = new DeviceInfoPlugin();
  try {
    if (Platform.isAndroid) {
      var info = await deviceInfoPlugin.androidInfo;
      identifier = info.androidId; //UUID for Android
    } else if (Platform.isIOS) {
      var info = await deviceInfoPlugin.iosInfo;
      identifier = info.identifierForVendor; //UUID for iOS
    }
  } catch (error) {
    print(error);
  }
*/
  return identifier;
}

///push到頁面
Future<T> push<T>(
  Widget page, {
  bool fullscreenDialog = false,
  bool cancelPrevious = false,
}) async {
  Transition transition = Transition.cupertino;
  Duration duration = Duration(milliseconds: 200);
  //取消上一頁
  if (cancelPrevious) {
    return await Get.off(
      page,
      fullscreenDialog: fullscreenDialog,
      transition: transition,
      duration: duration,
      popGesture: true,
      preventDuplicates: false,
    );
  } else {
    return await Get.to(
      page,
      fullscreenDialog: fullscreenDialog,
      transition: transition,
      duration: duration,
      popGesture: true,
      preventDuplicates: false,
    );
  }

  /*
  Navigator.push(
    Get.context,
    MaterialPageRoute(
      builder: (context) {
        return page;
      },
      fullscreenDialog: fullscreenDialog,
    ),
  );
  */
}

///push到遊戲商遊戲頁面
void pushToVendorGamePage(Vendor vendor) {
  Widget page;
  if (vendor.id == '22') {
    page = LobbyGameListPage(vendor);
  } else if (vendor.needLogin == '1') {
    page = LobbyGameLaunchPage(vendor);
  } else if (vendor.onlyOneGame == '1') {
    page = LobbyGameAgreeToEnterPage(vendor: vendor);
  } else {
    page = LobbyGameListPage(vendor);
  }
  push(page);
}

///開啟瀏覽器
Future<void> openBrowser(String url) async {
  if (await canLaunch(url)) {
    await launch(url, forceSafariVC: false);
  } else {
    await showMessageDialog(message: 'openBrowserError'.tr);
  }
}

///回到主畫面
void popToFirst() {
  Navigator.of(Get.context).popUntil((route) => route.isFirst);
}

///上一頁
void pop<T>({T result}) {
  Get.back(result: result);
}

Future<PickedFile> _takePicture(ImageSource imageSource) async {
  final picker = ImagePicker();
  PickedFile imageFile = await picker.getImage(
    source: imageSource,
    maxHeight: 1200,
    maxWidth: 1200,
    imageQuality: 100,
  );
  if (imageFile == null) return null;
  return imageFile;
}

//相機相簿選單
void showTakePictureButtonSheet(
    {@required Function(PickedFile image) onSelect}) async {
  showModalBottomSheet(
    backgroundColor: Colors.transparent,
    context: Get.context,
    builder: (context) {
      return SafeArea(
        child: Wrap(
          children: [
            BaseCard(
              margin: EdgeInsets.symmetric(horizontal: 8),
              colors: currentTheme.buttonSecondary,
              insideBorderWidth: 0,
              child: Wrap(
                children: [
                  //相機
                  TextButton(
                    style: ButtonStyle(
                      minimumSize: MaterialStateProperty.all<Size>(
                        Size(double.infinity, 40),
                      ),
                    ),
                    child: Text(
                      'camera'.tr,
                      style: TextStyle(
                        color: currentTheme.textHead,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                    onPressed: () async {
                      pop();
                      var selectedImage =
                          await _takePicture(ImageSource.camera);
                      if (selectedImage != null) onSelect(selectedImage);
                    },
                  ),
                  //分隔線
                  Container(
                    height: 1,
                    width: double.infinity,
                    color: Colors.grey,
                  ),
                  //相簿
                  TextButton(
                    style: ButtonStyle(
                      minimumSize: MaterialStateProperty.all<Size>(
                        Size(double.infinity, 40),
                      ),
                    ),
                    child: Text(
                      'chooseFromAlbums'.tr,
                      style: TextStyle(
                        color: currentTheme.textHead,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                    onPressed: () async {
                      pop();
                      var selectedImage =
                          await _takePicture(ImageSource.gallery);
                      if (selectedImage != null) onSelect(selectedImage);
                    },
                  ),
                ],
              ),
            ),
            BaseSheetButton(
              text: 'cancel1'.tr,
              onPressed: () => pop(),
            ),
          ],
        ),
      );
    },
  );
}

Future<String> fileToBase64(File file) async {
  Uint8List bytes = await file.readAsBytes();
  String encode = base64Encode(bytes);
  String base64 = 'data:image/jpeg;base64,$encode';
  return base64;
}

///顯示訊息
Future<void> showMessageDialog({
  String title,
  String message,
  Widget content,
  Function onPressed,
}) async {
  await Get.dialog(
    BaseDialog(
      title: Text(title != null ? title : 'prompt'.tr),
      content: content != null
          ? content
          : Text(
              message,
              textAlign: TextAlign.center,
            ),
      actions: [
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 5),
          child: BaseButton(
            colors: currentTheme.buttonPrimary,
            text: 'confirm'.tr,
            onPressed: () {
              pop();
              if (onPressed != null) onPressed();
            },
          ),
        ),
        if (onPressed != null)
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 5),
            child: BaseButton(
              colors: currentTheme.buttonDisable,
              text: 'cancel'.tr,
              onPressed: () {
                pop();
              },
            ),
          ),
      ],
    ),
  );
}

//顯示廣告
showAd() async {
  List<Advertisements> advertisements = await fetchAdvertisements('4');
  if (advertisements.isEmpty) return;
  await Future.forEach(advertisements, (element) async {
    await Get.dialog(
      Dialog(
        child: Stack(
          clipBehavior: Clip.none,
          children: [
            GestureDetector(
              onTap: () async {
                if (element.gameId != '0') {
                  showLoadingDialog();
                  var game = await fetchGame(element.gameId);
                  pop();
                  pop();
                  push(WebviewPage(game: game));
                }
              },
              child: Image.network(
                element.image,
                fit: BoxFit.fill,
              ),
            ),
            Positioned(
              top: 0,
              right: 0,
              child: Container(
                height: 20,
                width: 20,
                color: Colors.black.withAlpha(150),
                child: RawMaterialButton(
                  child: Icon(
                    Icons.close,
                    color: currentTheme.icon,
                    size: 20,
                  ),
                  onPressed: Get.back,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  });
}

///取得未讀群發訊息
void fetchAnnouncement() async {
  AnnouncementMessage announcement = await fetchAnnouncementMessage();
  if (announcement != null) {
    await showAnnouncementDialog(
      title: Text(
        announcement.title,
        textAlign: TextAlign.center,
        style: TextStyle(color: currentTheme.textHead, fontSize: 15),
      ),
      message: Text(announcement.message),
      barrierDismissible: false,
    );
    announcementMessageRead(announcement.announcementMessageId);
  }
}

///顯示公告訊息
Future<void> showAnnouncementDialog({
  Widget title,
  Widget message,
  bool barrierDismissible = false,
}) async {
  await Get.dialog(
    AnnouncementDialog(
      title: title,
      message: message,
    ),
    barrierDismissible: barrierDismissible,
  );
}

///讀取中
showLoadingDialog() {
  Get.dialog(
    AlertDialog(
      backgroundColor: Colors.transparent,
      elevation: 0,
      content: Center(
        child: CircularProgressIndicator(),
      ),
    ),
    barrierDismissible: false,
  );
}

///更新訊息
Future<void> showUpdateDialog({@required String downloadLink}) async {
  await Get.dialog(
    BaseDialog(
      title: Text('prompt'.tr),
      content: Text(
        'downloadNewVersion'.tr,
        textAlign: TextAlign.center,
      ),
      actions: [
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 5),
          child: BaseButton(
            colors: currentTheme.buttonPrimary,
            text: 'update'.tr,
            onPressed: () {
              launch(downloadLink);
            },
          ),
        ),
      ],
    ),
    barrierDismissible: false,
  );
}

///版本比較
bool _compareVersions(String currentVer, String newVer) {
  List<String> currentParts = currentVer.split('.');
  List<String> newParts = newVer.split('.');
  if (newParts.length > currentParts.length) {
    var count = newParts.length - currentParts.length;
    for (int i = 0; i < count; i++) {
      currentParts.add('0');
    }
  }
  for (var i = 0; i < newParts.length; i++) {
    int a = int.tryParse(newParts[i]);
    int b = int.tryParse(currentParts[i]);
    if (a == null) a = 0;
    if (b == null) b = 0;
    if (a > b) return true;
    if (a < b) return false;
  }
  return false;
}

///取得版號
Future<String> getVersion() async {
  PackageInfo platform;
  try {
    platform = await PackageInfo.fromPlatform();
  } catch (error) {
    print(error);
  }
  return platform != null ? platform.version : '0.0.0';
}

//檢查網路
Future<ConnectivityResult> getConnectivityResult() async {
  return await (Connectivity().checkConnectivity());
}

///檢查是否有新版本下載連結
Future<String> checkHaveNewVersionDownloadLink() async {
  if (GetPlatform.isWeb) return null;
  try {
    String version = await getVersion();
    VersionInfo versionInfo = await fetchVersion();
    var newVersion =
        GetPlatform.isIOS ? versionInfo.iosVersion : versionInfo.androidVersion;
    var downloadLink = GetPlatform.isIOS
        ? versionInfo.iosDownloadLink
        : versionInfo.androidDownloadLink;
    bool hasNewVersion = _compareVersions(version, newVersion);
    return hasNewVersion ? downloadLink : null;
  } catch (e) {
    throw (e);
  }
}

///灰階
const ColorFilter greyscale = ColorFilter.matrix(<double>[
  0.2126,
  0.7152,
  0.0722,
  0,
  0,
  0.2126,
  0.7152,
  0.0722,
  0,
  0,
  0.2126,
  0.7152,
  0.0722,
  0,
  0,
  0,
  0,
  0,
  1,
  0,
]);

extension Trans on String {
  //替換字串
  String replaceNamedArgs(Map<String, String> args) {
    String res = this;
    if (args == null || args.isEmpty) return res;
    args.forEach((String key, String value) =>
        res = res.replaceAll(RegExp('{$key}'), value));
    return res;
  }
}
