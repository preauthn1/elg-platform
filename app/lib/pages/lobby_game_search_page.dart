import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/widgets/base/appbar_title_text.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';
import 'package:futter_web_frontend/widgets/base/base_text_field.dart';

import 'package:futter_web_frontend/widgets/lobby/lobby_game_list.dart';
import 'package:get/get.dart';
import 'lobby_tab_page.dart';

class LobbyGameSearchPage extends StatefulWidget {
  @override
  _LobbyGameSearchPageState createState() => _LobbyGameSearchPageState();
}

class _LobbyGameSearchPageState extends State<LobbyGameSearchPage>
    with SingleTickerProviderStateMixin {
  TextEditingController _searchController = TextEditingController();
  bool _isLoading = false;

  @override
  void dispose() {
    super.dispose();
    _searchController.dispose();
  }

  Widget _buildSearchBar() {
    return BaseTextField(
      margin: null,
      controller: _searchController,
      textAlign: TextAlign.left,
      hintText: 'search'.tr,
      onChanged: (_) => setState(() {}),
      suffix: Icon(
        Icons.search,
        size: 32,
        color: currentTheme.icon,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: BaseScaffold(
        appbarBackgroundIndex: 1,
        bodyBackgroundIndex: 0,
        appbarTitle: AppbarTitleText('search'.tr),
        leading: IconButton(
          icon: Icon(Icons.arrow_back_ios),
          onPressed: () => LobbyTabPage.lobbyTabController.animateTo(1),
        ),
        child: _isLoading
            ? Center(child: CircularProgressIndicator())
            : Column(
                children: [
                  Expanded(
                    child: LobbyGameList(
                      filterName: _searchController.text,
                      filterHeight: 100,
                      flexibleSpace: Container(
                        padding: const EdgeInsets.all(8.0),
                        child: _buildSearchBar(),
                      ),
                    ),
                  ),
                ],
              ),
      ),
    );
  }
}
