import 'package:flutter/material.dart';
import 'package:futter_web_frontend/controller/auth_controller.dart';
import 'package:futter_web_frontend/models/my_game.dart';
import 'package:futter_web_frontend/models/vendor.dart';
import 'package:futter_web_frontend/pages/lobby_game_agree_to_enter_page.dart';
import 'package:futter_web_frontend/pages/webview_page.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/http_service.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:futter_web_frontend/widgets/lobby/lobby_gift_image.dart';
import 'package:get/get.dart';
import 'package:url_launcher/url_launcher.dart';
import 'lobby_hot_image.dart';
import 'lobby_new_image.dart';

// ignore: must_be_immutable
class LobbyGameGrid extends StatelessWidget {
  LobbyGameGrid({
    @required this.games,
    this.vendor,
    this.filterTypeId,
    this.filterName,
    this.onBackToGrid,
  });
  final List<MyGame> games;
  final Vendor vendor;
  final String filterTypeId;
  final String filterName;
  final Function onBackToGrid;
  List<MyGame> _showGames = [];

  void setGame() {
    _showGames.addAll(
      games.where((element) => element.isNew == '1' && element.isHot == '1'),
    );
    _showGames.addAll(
      games.where((element) => element.isNew == '0' && element.isHot == '1'),
    );
    _showGames.addAll(
      games.where((element) => element.isNew == '1' && element.isHot == '0'),
    );
    _showGames.addAll(
      games.where((element) => element.isNew == '0' && element.isHot == '0'),
    );
  }

  ///啟動APP
  void _launchApp(MyGame game) async {
    showLoadingDialog();
    try {
      Map<String, dynamic> vendorInfo = await fetchVendorInfo(game.vendorId);
      String androidOpen = vendorInfo['android_open'].toString().toLowerCase();
      //開啟App
      if (await canLaunch(androidOpen)) {
        await launch(androidOpen);
      } else {
        String downloadUrl = vendorInfo['android_download'];
        await openBrowser(downloadUrl);
      }
    } catch (e) {
      print(e);
    }
    pop();
  }

  void showVPowerDialog(MyGame game) {
    double height = 70;
    showMessageDialog(
      title: game.name,
      content: Container(
        child: Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            InkWell(
              onTap: () => _pushToGame(game),
              child: Image.asset(
                'assets/images/lobby/icon_html.png',
                height: height,
              ),
            ),
            SizedBox(width: 30),
            InkWell(
              onTap: () => _launchApp(game),
              child: Image.asset(
                'assets/images/lobby/icon_android.png',
                height: height,
              ),
            ),
          ],
        ),
      ),
    );
  }

  String getRedirectDomain(MyGame game) {
    if (game.redirectUrl != null &&
        game.redirectUrl != 'null' &&
        game.redirectUrl != '') {
      return game.redirectUrl;
    } else {
      return currentEnv.domain;
    }
  }

  void _pushToGame(MyGame game) async {
    Widget page;
    //小艾
    if (game.vendorId == '21') {
      Vendor vendor21 = vendor;
      if (vendor21 == null) {
        showLoadingDialog();
        vendor21 = await fetchVendor('21');
        pop();
      }
      page = LobbyGameAgreeToEnterPage(vendor: vendor21, game: game);
    } else if (game.vendorId == '17') {
      //PT
      String token = [REDACTED];
      String gameId = game.id;
      String domain = getRedirectDomain(game);
      String gameUrl =
          'https://$domain/PTLogin?id=$gameId&web_view_token=[REDACTED]';
      await openBrowser(gameUrl);
      return;
    } else if (game.vendorId == '41') {
      //PT
      String token = [REDACTED];
      String gameId = game.id;
      String domain = getRedirectDomain(game);
      String gameUrl =
          'https://$domain/NPTLogin?id=$gameId&web_view_token=[REDACTED]';
      await openBrowser(gameUrl);
      return;
    } else if (game.vendorId == '36' || game.vendorId == '45') {
      //另開窗
      showLoadingDialog();
      String gameUrl = await fetchGameUrl(game.id);
      pop();
      await openBrowser(gameUrl);
      return;
    } else {
      page = WebviewPage(game: game);
    }

    push(page).then((value) {
      //返回遊戲列表
      if (onBackToGrid != null) onBackToGrid();
    });
  }

  void onClickGameItem(MyGame game) {
    if (game.vendorId == '22' && GetPlatform.isAndroid) {
      showVPowerDialog(game);
    } else {
      _pushToGame(game);
    }
  }

  Widget _buildGameItem(BuildContext context, MyGame game) {
    return InkWell(
      onTap: () => onClickGameItem(game),
      child: Column(
        children: [
          Container(
            height: 100,
            width: 100,
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(20),
              border: Border.all(
                color: currentTheme.gameItemBorder,
                width: 2,
              ),
            ),
            child: Container(
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(18),
                border:
                    Border.all(color: currentTheme.textBody2Color, width: 2),
                color: Colors.black,
              ),
              child: ClipRRect(
                borderRadius: BorderRadius.circular(15),
                child: Stack(
                  alignment: Alignment.center,
                  children: [
                    Image.network(game.image),
                    if (game.isHot == '1') LobbyHotImage(),
                    if (game.isNew == '1') LobbyNewImage(),
                    if (game.eventBet != "" || game.eventWin != "")
                      LobbyGiftImage(),
                  ],
                ),
              ),
            ),
          ),
          SizedBox(height: 5),
          Text(
            game.name,
            textAlign: TextAlign.center,
          ),
        ],
      ),
    );
  }

  List<Widget> _getGameItems(BuildContext context) {
    List<Widget> gameList = [];
    for (final game in _showGames) {
      if ((filterTypeId == null || filterTypeId == game.typeId) &&
          (filterName == null ||
              filterName == '' ||
              game.name.toLowerCase().contains(filterName.toLowerCase()))) {
        gameList.add(
          _buildGameItem(context, game),
        );
      }
    }
    return gameList;
  }

  @override
  Widget build(BuildContext context) {
    setGame();
    return SliverPadding(
      padding: const EdgeInsets.all(8),
      sliver: SliverGrid(
        gridDelegate: SliverGridDelegateWithMaxCrossAxisExtent(
          maxCrossAxisExtent: 150,
          childAspectRatio: 0.8,
          mainAxisSpacing: 10,
        ),
        delegate: SliverChildBuilderDelegate(
          (BuildContext context, int index) {
            return _getGameItems(context)[index];
          },
          childCount: _getGameItems(context).length,
        ),
      ),
    );
  }
}
