import 'package:flutter/material.dart';
import 'package:futter_web_frontend/pages/lobby_game_search_page.dart';
import 'package:futter_web_frontend/pages/lobby_vendor_page.dart';
import 'package:futter_web_frontend/pages/setting_page.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';

class LobbyTabPage extends StatefulWidget {
  static TabController lobbyTabController;

  static void backToRoot() {
    lobbyTabController.animateTo(1);
  }

  static void toSetting() {
    lobbyTabController.animateTo(2);
  }

  @override
  _LobbyTabPageState createState() => _LobbyTabPageState();
}

class _LobbyTabPageState extends State<LobbyTabPage>
    with SingleTickerProviderStateMixin {
  bool _initializing = true;
  List<Widget> _tabChildren = [
    LobbyGameSearchPage(),
    LobbyVendorPage(),
    SettingPage(inLobbyPage: true),
  ];

  @override
  void initState() {
    super.initState();

    _setLobbyTab().then((_) {
      setState(() {
        _initializing = false;
      });
    });
  }

  Future<void> _setLobbyTab() async {
    LobbyTabPage.lobbyTabController = TabController(
      length: _tabChildren.length,
      vsync: this,
      initialIndex: 1,
    );
  }

  @override
  void dispose() {
    LobbyTabPage.lobbyTabController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: _initializing
          ? BaseScaffold(
              appbarBackgroundIndex: 1,
              bodyBackgroundIndex: 1,
              child: Center(child: CircularProgressIndicator()),
            )
          : DefaultTabController(
              length: _tabChildren.length,
              child: TabBarView(
                controller: LobbyTabPage.lobbyTabController,
                children: _tabChildren,
              ),
            ),
    );
  }
}
