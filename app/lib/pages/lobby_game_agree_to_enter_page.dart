import 'dart:ui';

import 'package:flutter/material.dart';
import 'package:futter_web_frontend/models/my_game.dart';
import 'package:futter_web_frontend/models/vendor.dart';
import 'package:futter_web_frontend/pages/webview_page.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/http_service.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:futter_web_frontend/widgets/base/appbar_lobby_title_image.dart';
import 'package:futter_web_frontend/widgets/base/base_list_button.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';

import 'package:futter_web_frontend/widgets/vendor_balance_card.dart';
import 'package:get/get.dart';

class LobbyGameAgreeToEnterPage extends StatefulWidget {
  final Vendor vendor;
  final MyGame game;
  LobbyGameAgreeToEnterPage({this.vendor, this.game});
  @override
  _LobbyGameAgreeToEnterPageState createState() =>
      _LobbyGameAgreeToEnterPageState();
}

class _LobbyGameAgreeToEnterPageState extends State<LobbyGameAgreeToEnterPage>
    with SingleTickerProviderStateMixin {
  String _balance = '0.00';
  bool _isLoading = true;
  bool _haveAgreed = false;

  //顯示聲明
  bool get displayStatement {
    List<String> showVendors = ['40', '18', '21', '38', '31', '43', '44'];
    return showVendors.contains(widget.vendor.id);
  }

  @override
  void initState() {
    super.initState();
    _fetchData();
  }

  void _fetchData() async {
    setState(() {
      _isLoading = true;
    });
    try {
      await fetchVendor(widget.vendor.id);
      _balance = await fetchSingleWalletBalance(widget.vendor.id);
    } catch (e) {
      pop();
    }
    setState(() {
      _isLoading = false;
    });
  }

  void pushToGame() {
    Widget page;
    if (widget.game != null) {
      page = WebviewPage(game: widget.game);
    } else if (widget.vendor != null) {
      page = WebviewPage(vendor: widget.vendor);
    }
    push(page).then((value) => _fetchData());
  }

  Widget _buildEnterButton(String title) {
    return BaseListButton(
      colors: currentTheme.buttonWarning,
      child: Text(
        title,
        style: TextStyle(
          color: currentTheme.theme.textTheme.button.color,
        ),
      ),
      onPressed: pushToGame,
    );
  }

  Widget _agreeToEnter() {
    return Column(
      children: [
        if (displayStatement)
          Text(
            'companyWillVoid'.tr,
            textAlign: TextAlign.center,
            style: TextStyle(fontSize: 18),
          ),
        if (!_haveAgreed && displayStatement)
          BaseListButton(
            colors: currentTheme.buttonPrimary,
            child: Text(
              'agree'.tr,
              style: TextStyle(
                color: currentTheme.theme.textTheme.button.color,
              ),
            ),
            onPressed: () {
              setState(() {
                _haveAgreed = true;
              });
            },
          ),
        if (_haveAgreed || !displayStatement)
          _buildEnterButton('clickHerToEnter'.tr),
        if (widget.vendor.id == '4')
          Image.asset(
            'path_app_vendor4Path'.tr,
            fit: BoxFit.scaleDown,
          ),
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: BaseScaffold(
        appbarBackgroundIndex: 2,
        bodyBackgroundIndex: 0,
        appbarTitle: AppbarLobbyTitleImage(widget.vendor.image),
        actions: [VendorBalanceCard(_balance)],
        child: _isLoading
            ? Center(child: CircularProgressIndicator())
            : SingleChildScrollView(
                child: Container(
                  padding: EdgeInsets.all(15),
                  child: _agreeToEnter(),
                ),
              ),
      ),
    );
  }
}
