import 'package:flutter/material.dart';
import 'package:flutter_svg/flutter_svg.dart';
import 'package:futter_web_frontend/controller/member_controller.dart';
import 'package:futter_web_frontend/controller/template_controller.dart';
import 'package:futter_web_frontend/pages/lobby_tab_page.dart';
import 'package:futter_web_frontend/pages/video_page.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:futter_web_frontend/widgets/base/appbar_lobby_title_image.dart';
import 'package:futter_web_frontend/widgets/base/base_circle_button.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';

import 'package:futter_web_frontend/widgets/lobby/lobby_game_list.dart';
import 'package:futter_web_frontend/widgets/lobby/lobby_setting_icon_button.dart';
import 'package:futter_web_frontend/widgets/lobby/lobby_vendor_list.dart';

class LobbyVendorPage extends StatefulWidget {
  @override
  _LobbyVendorPageState createState() => _LobbyVendorPageState();
}

class _LobbyVendorPageState extends State<LobbyVendorPage>
    with AutomaticKeepAliveClientMixin<LobbyVendorPage> {
  @override
  bool get wantKeepAlive => true;
  @override
  void initState() {
    super.initState();
    fetchData();
  }

  Future<void> fetchData() async {
    await MemberController.to.fetchAndSetMember();
  }

  @override
  // ignore: must_call_super
  Widget build(BuildContext context) {
    bool hasPgsLobbyLayout = TemplateController
            .to.template.value.customizedSetting.hasPgsLobbyLayout ==
        '1';
    return SafeArea(
      child: BaseScaffold(
        appbarBackgroundIndex: 1,
        bodyBackgroundIndex: 1,
        appbarTitle: AppbarLobbyTitleImage(
          TemplateController.to.template.value.logoMobile,
        ),
        actions: [
          if (TemplateController.to.template.value.hasShowMedia == '1')
            BaseCircleButton(
              child: SvgPicture.asset(
                'assets/images/lobby/movie_icon.svg',
                height: 24,
                width: 24,
              ),
              size: 40,
              onPressed: () => push(VideoPage()),
            ),
          LobbySettingIconButton(
            onPressed: LobbyTabPage.toSetting,
          ),
        ],
        leading: IconButton(
          icon: Icon(Icons.search),
          splashRadius: 20,
          iconSize: 30,
          onPressed: () => LobbyTabPage.lobbyTabController.animateTo(0),
        ),
        child: hasPgsLobbyLayout ? LobbyGameList() : LobbyVendorList(),
      ),
    );
  }
}
