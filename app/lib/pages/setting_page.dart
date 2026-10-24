import 'package:flutter/material.dart';
import 'package:futter_web_frontend/controller/auth_controller.dart';
import 'package:futter_web_frontend/pages/language_menu.page.dart';
import 'package:futter_web_frontend/pages/lobby_tab_page.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/widgets/base/base_button.dart';
import 'package:futter_web_frontend/widgets/base/base_list_icon_button.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';
import 'package:get/get.dart';
import 'package:futter_web_frontend/pages/about_menu_page.dart';
import 'package:futter_web_frontend/pages/change_password_page.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:futter_web_frontend/widgets/base/appbar_title_text.dart';

import 'package:futter_web_frontend/widgets/setting/setting_profile.dart';

class SettingPage extends StatefulWidget {
  final bool inLobbyPage;
  SettingPage({this.inLobbyPage = false});
  @override
  _SettingPageState createState() => _SettingPageState();
}

class _SettingPageState extends State<SettingPage>
    with AutomaticKeepAliveClientMixin<SettingPage> {
  @override
  bool get wantKeepAlive => true;

  void _logout() async {
    showLoadingDialog();
    await AuthController.to.logout();
  }

  @override
  // ignore: must_call_super
  Widget build(BuildContext context) {
    return BaseScaffold(
      appbarBackgroundIndex: 1,
      bodyBackgroundIndex: 1,
      appbarTitle: AppbarTitleText('setting'.tr),
      leading:
          //大廳頁面使用TabController返回
          widget.inLobbyPage
              ? IconButton(
                  icon: Icon(Icons.arrow_back_ios),
                  onPressed: () => LobbyTabPage.backToRoot(),
                )
              //預設上一頁
              : null,
      child: SingleChildScrollView(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            SettingProfile(),
            SizedBox(height: 30),
            BaseListIconButton(
              text: 'changePassword'.tr,
              icon: ListIcon.icon(Icons.lock),
              onPressed: () => push(ChangePasswordPage()),
            ),
            BaseListIconButton(
              text: 'language'.tr,
              icon: ListIcon.icon(Icons.language),
              onPressed: () => push(LanguageMenu()),
            ),
            BaseListIconButton(
              text: 'about'.tr,
              icon: ListIcon.icon(Icons.error_outline),
              onPressed: () => push(AboutPage()),
            ),
            BaseListIconButton(
              text: 'myShareCode'.tr + AuthController.to.auth.value.shareCode,
              icon: ListIcon.icon(Icons.person),
            ),
            SizedBox(height: 20),
            BaseButton(
              colors: currentTheme.buttonDanger,
              text: 'signOut'.tr,
              onPressed: _logout,
            ),
            SizedBox(height: 20),
          ],
        ),
      ),
    );
  }
}
