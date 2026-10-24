import 'package:flutter/material.dart';
import 'package:futter_web_frontend/controller/member_controller.dart';
import 'package:futter_web_frontend/controller/payment_type_controller.dart';
import 'package:futter_web_frontend/controller/template_controller.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/http_service.dart';
import 'lobby_tab_page.dart';
import 'package:futter_web_frontend/pages/chat_page.dart';
import 'package:futter_web_frontend/pages/events_page.dart';
import 'package:futter_web_frontend/pages/unlimit_page.dart';
import 'package:futter_web_frontend/pages/wallet_page.dart';
import 'package:get/get.dart';
import 'package:futter_web_frontend/utils/utils.dart';

class TabsPage extends StatefulWidget {
  @override
  _TabsPageState createState() => _TabsPageState();
}

class _TabsPageState extends State<TabsPage> {
  final List<Map<String, Object>> _pages = [
    {
      'nameKey': 'lobby',
      'page': LobbyTabPage(),
      'iconPath': currentTheme.getTemplateImage('page1.png'),
      'highlightIconPath': currentTheme.getTemplateImage('page1_highLight.png'),
    },
    {
      'nameKey': 'events',
      'page': EventsPage(),
      'iconPath': currentTheme.getTemplateImage('page2.png'),
      'highlightIconPath': currentTheme.getTemplateImage('page2_highLight.png'),
    },
    {
      'nameKey': 'Agent_AgentMenu',
      'page': UnlimitPage(),
      'iconPath': currentTheme.getTemplateImage('page3.png'),
      'highlightIconPath': currentTheme.getTemplateImage('page3_highLight.png'),
    },
    {
      'nameKey': 'talk',
      'page': ChatPage(),
      'iconPath': currentTheme.getTemplateImage('page4.png'),
      'highlightIconPath': currentTheme.getTemplateImage('page4_highLight.png'),
    },
    {
      'nameKey': 'wallet',
      'page': WalletPage(),
      'iconPath': currentTheme.getTemplateImage('page5.png'),
      'highlightIconPath': currentTheme.getTemplateImage('page5_highLight.png'),
    },
  ];

  int _selectedPageIndex = 0;
  bool _showAgentAd = true;

  @override
  void initState() {
    super.initState();
    //聊天室不開放
    if (TemplateController.to.template.value.hasChat != '1') {
      _pages.removeWhere((page) => page['nameKey'] == 'talk');
    }
    //活動不開放
    if (TemplateController.to.template.value.customizedSetting.hasEvent !=
        '1') {
      _pages.removeWhere((page) => page['nameKey'] == 'events');
    }
    //代理不開放
    if (TemplateController.to.template.value.hasAgentLayout != '1' ||
        TemplateController
                .to.template.value.customizedSetting.isUnlimitVisible !=
            '1') {
      _pages.removeWhere((page) => page['nameKey'] == 'Agent_AgentMenu');
    }
    //取得未讀群發訊息
    fetchAnnouncement();
    showAd();
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();

    for (int i = 0; i < _pages.length; i++) {
      final page = _pages[i];
      precacheImage(AssetImage(page['iconPath']), context);
      precacheImage(AssetImage(page['highlightIconPath']), context);
    }
  }

  void _selectPage(int index) {
    setState(() {
      _selectedPageIndex = index;
    });
  }

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: Scaffold(
        body: _pages[_selectedPageIndex]['page'],
        /*
        IndexedStack(
          index: _selectedPageIndex,
          children: [
            ..._pages.map((page) => page['page']).toList(),
          ],
        ),
        */
        bottomNavigationBar: Container(
          constraints: BoxConstraints(maxHeight: 50),
          width: double.infinity,
          child: Stack(
            alignment: Alignment.center,
            children: [
              currentTheme.getBottomNavBg(),
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceAround,
                children: [
                  for (int i = 0; i < _pages.length; i++)
                    Expanded(
                      child: GestureDetector(
                        onTap: () async {
                          _selectPage(i);
                          //點擊大廳後切回遊戲商畫面
                          if (_pages[i]['nameKey'] == 'lobby') {
                            LobbyTabPage.backToRoot();
                            fetchAnnouncement();
                          }
                          //首次點擊全民代理顯示廣告
                          if (_pages[i]['nameKey'] == 'Agent_AgentMenu' &&
                              _showAgentAd &&
                              !isDsg) {
                            UnlimitPage.showAgentAd();
                            _showAgentAd = false;
                          }
                          if (_pages[i]['nameKey'] == 'wallet') {
                            fetchAnnouncement();
                            // String balance = await fetchTransferFromLastLog();
                            // MemberController.to.updateBalance(balance);
                            // PaymentTypeController.to.fetchAndSetPaymentType();
                          }
                        },
                        child: Stack(
                          alignment: Alignment.bottomCenter,
                          children: [
                            Image.asset(
                              _pages[i][_selectedPageIndex == i
                                  ? 'highlightIconPath'
                                  : 'iconPath'],
                            ),
                            Obx(
                              () => Text(
                                _pages[i]['nameKey']
                                    .toString()
                                    .tr
                                    .replaceNamedArgs({
                                  'v_reward':
                                      TemplateController.to.textUnlimited
                                }),
                                style: TextStyle(
                                  color: _selectedPageIndex == i
                                      ? currentTheme.textHead
                                      : currentTheme.textMuted,
                                ),
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}
