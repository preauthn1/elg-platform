import 'dart:io' show Platform;
import 'dart:ui';

import 'package:flutter/material.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/http_service.dart';
import 'package:futter_web_frontend/widgets/base/appbar_lobby_title_image.dart';
import 'package:futter_web_frontend/widgets/base/base_button.dart';
import 'package:futter_web_frontend/widgets/base/base_card.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';
import 'package:futter_web_frontend/widgets/base/base_text_field.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:futter_web_frontend/models/vendor.dart';
import 'package:futter_web_frontend/utils/utils.dart';

import 'package:futter_web_frontend/widgets/base/copy_button.dart';
import 'package:futter_web_frontend/widgets/vendor_balance_card.dart';
import 'package:get/get.dart';

class LobbyGameLaunchPage extends StatefulWidget {
  final Vendor vendor;
  LobbyGameLaunchPage(this.vendor);
  @override
  _LobbyGameLaunchPageState createState() => _LobbyGameLaunchPageState();
}

class _LobbyGameLaunchPageState extends State<LobbyGameLaunchPage>
    with WidgetsBindingObserver {
  final TextEditingController _passwordController = TextEditingController();
  String _balance = '0.00';
  bool _isLoading = true;
  Map<String, dynamic> vendorInfo;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _fetchData();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    super.didChangeAppLifecycleState(state);
    if (state == AppLifecycleState.resumed) {
      _fetchData();
    }
  }

  ///獲取遊戲商
  void _fetchData() async {
    setState(() {
      _isLoading = true;
    });
    try {
      await fetchVendor(widget.vendor.id);
      vendorInfo = await fetchVendorInfo(widget.vendor.id);
      _balance = await fetchSingleWalletBalance(widget.vendor.id);
    } catch (e) {
      pop();
    }

    setState(() {
      _isLoading = false;
    });
  }

  ///重設遊戲商密碼
  void _resetVendorPassword() async {
    try {
      await resetVendorPassword(widget.vendor.id, _passwordController.text);
      showMessageDialog(message: 'successfully'.tr);
    } catch (error) {}
  }

  ///前往下載頁面 預設載點1
  void _launchDownloadUrl({int index = 1}) async {
    String downloadUrl;
    String keyName;
    if (Platform.isAndroid) {
      if (index > 1) {
        keyName = 'android_download$index';
      } else {
        keyName = 'android_download';
      }
    } else if (Platform.isIOS) {
      if (index > 1) {
        keyName = 'ios_download$index';
      } else {
        keyName = 'ios_download';
      }
    }
    downloadUrl = vendorInfo[keyName];
    await openBrowser(downloadUrl);
  }

  ///啟動APP
  void _launchApp() async {
    String openUrl;
    if (Platform.isAndroid) {
      openUrl = vendorInfo['android_open'];
    } else if (Platform.isIOS) {
      openUrl = vendorInfo['ios_open'];
    }

    //開啟App
    if (await canLaunch(openUrl)) {
      await launch(openUrl);
      return;
    }

    //未安裝App 開啟下載Url
    _launchDownloadUrl();
  }

  ///是否有啟動App url
  bool get canOpenApp {
    return vendorInfo['android_open'] != null &&
        vendorInfo['android_open'] != '' &&
        vendorInfo['ios_open'] != null &&
        vendorInfo['ios_open'] != '';
  }

  ///是否有多個下載點
  bool get multipleDownload {
    String keyName = '';
    if (Platform.isAndroid) {
      keyName = 'android_download2';
    } else if (Platform.isIOS) {
      keyName = 'ios_download2';
    }
    return keyName != '' &&
        vendorInfo[keyName] != null &&
        vendorInfo[keyName] != '';
  }

  Widget _buildLaunchCard(
      {String title, String buttonText, Function onPressed}) {
    return BaseCard(
      height: 150,
      width: double.infinity,
      colors: currentTheme.buttonList,
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Text(title),
          SizedBox(height: 20),
          BaseButton(
            height: 40,
            width: 150,
            colors: currentTheme.buttonWarning,
            text: buttonText,
            onPressed: onPressed,
          )
        ],
      ),
    );
  }

  Widget _buildColumn() {
    //可開啟遊戲
    if (canOpenApp) {
      return Column(
        children: [
          _buildLaunchCard(
              title: 'openTheGame'.tr,
              buttonText: 'open'.tr,
              onPressed: _launchApp),
          Text(
            '${'afterDownloading'.tr}\n\n${'pleaseReturnTo'.tr}',
            textAlign: TextAlign.center,
          ),
        ],
      );

      //只有下載頁面
    } else {
      return Column(
        children: [
          _buildLaunchCard(
            title: 'downLoadAPP'.tr,
            buttonText: 'downLoad'.tr,
            onPressed: () => _launchDownloadUrl(index: 1),
          ),
          SizedBox(height: 15),
          if (multipleDownload)
            _buildLaunchCard(
              title: '${'downLoadAPP'.tr}2',
              buttonText: 'downLoad'.tr,
              onPressed: () => _launchDownloadUrl(index: 2),
            ),
          Text(
            '${'ifDownLoadGame'.tr}\n\n${'pleaseEnterTheGame'.tr}',
            textAlign: TextAlign.center,
          ),
          SizedBox(height: 15),
          BaseCard(
            height: 45,
            padding: EdgeInsets.symmetric(horizontal: 15),
            width: double.infinity,
            colors: currentTheme.buttonList,
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text('${'loginAccount'.tr}: ${vendorInfo['username']}'),
                CopyButton(vendorInfo['username']),
              ],
            ),
          ),
          SizedBox(height: 15),
          BaseCard(
            height: 45,
            padding: EdgeInsets.symmetric(horizontal: 15),
            width: double.infinity,
            colors: currentTheme.buttonList,
            child: Align(
              alignment: Alignment.centerLeft,
              child: Text(
                '${'loginPassword'.tr}: ${vendorInfo['password']}',
              ),
            ),
          ),
          SizedBox(height: 15),
          SizedBox(
            width: double.infinity,
            child: Text(
                '${'youCanChangeYourLoginPassword'.tr}\n${'ifYouHaveModifiedIt'.tr}'),
          ),
          SizedBox(
            width: double.infinity,
            child: Text(
              '''
${'yourNewPasswordMustContain'.tr}
1.${'atLeast6characters'.tr}
2.${'uppercaseAndLowercaseLetters'.tr}
3.${'atLeastOneNumber'.tr}
              ''',
              style: TextStyle(color: currentTheme.textHead),
            ),
          ),
          BaseTextField(
            controller: _passwordController,
            prefix: Text('setnewpassword'.tr),
            hintText: 'Ex:Aa12345',
            obscureText: true,
          ),
          SizedBox(height: 15),
          BaseButton(
            colors: currentTheme.buttonPrimary,
            text: 'changePassword'.tr,
            onPressed: _resetVendorPassword,
          ),
        ],
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: BaseScaffold(
        appbarBackgroundIndex: 2,
        bodyBackgroundIndex: 0,
        appbarTitle: AppbarLobbyTitleImage(widget.vendor.image),
        actions: [
          VendorBalanceCard(_balance),
        ],
        child: _isLoading
            ? Center(child: CircularProgressIndicator())
            : SingleChildScrollView(
                child: Container(
                  padding: EdgeInsets.all(15),
                  child: _buildColumn(),
                ),
              ),
      ),
    );
  }
}
