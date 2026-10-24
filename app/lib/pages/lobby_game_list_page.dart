import 'package:flutter/material.dart';
import 'package:futter_web_frontend/utils/http_service.dart';
import 'package:futter_web_frontend/models/vendor.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:futter_web_frontend/widgets/base/appbar_lobby_title_image.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';

import 'package:futter_web_frontend/widgets/lobby/lobby_game_list.dart';
import 'package:futter_web_frontend/widgets/vendor_balance_card.dart';

class LobbyGameListPage extends StatefulWidget {
  final Vendor vendor;
  final Widget leading;
  LobbyGameListPage(
    this.vendor, {
    this.leading,
  });
  @override
  _LobbyGameListPageState createState() => _LobbyGameListPageState();
}

class _LobbyGameListPageState extends State<LobbyGameListPage>
    with WidgetsBindingObserver {
  String _balance = '0.00';
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    fetchData();
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
      fetchData();
    }
  }

  void fetchData() async {
    //取得未讀群發訊息
    fetchAnnouncement();
    try {
      await fetchVendor(widget.vendor.id);
      String balance = await fetchSingleWalletBalance(widget.vendor.id);
      setState(() {
        _balance = balance;
      });
    } catch (e) {
      pop();
    }
  }

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: BaseScaffold(
        appbarBackgroundIndex: 2,
        bodyBackgroundIndex: 1,
        appbarTitle: AppbarLobbyTitleImage(widget.vendor.image),
        leading: widget.leading,
        actions: [VendorBalanceCard(_balance)],
        child: LobbyGameList(
          vendor: widget.vendor,
          onBackToGrid: fetchData,
        ),
      ),
    );
  }
}
