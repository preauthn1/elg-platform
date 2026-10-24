import 'package:flutter/material.dart';
import 'package:futter_web_frontend/models/my_game.dart';
import 'package:futter_web_frontend/models/vendor.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/http_service.dart';
import 'package:futter_web_frontend/widgets/lobby/lobby_game_filter.dart';
import 'package:futter_web_frontend/widgets/lobby/lobby_game_grid.dart';

class LobbyGameList extends StatefulWidget {
  final Vendor vendor;
  final String filterName;
  final double filterHeight;
  final Widget flexibleSpace;
  final Function onBackToGrid;
  const LobbyGameList({
    this.vendor,
    this.filterName,
    this.filterHeight = 35,
    this.flexibleSpace,
    this.onBackToGrid,
  });
  @override
  _LobbyGameListState createState() => _LobbyGameListState();
}

class _LobbyGameListState extends State<LobbyGameList> {
  bool _isLoading = true;
  List<MyGame> _myGames = [];
  String filterTypeId;

  @override
  void initState() {
    super.initState();
    fetchData();
  }

  void fetchData() async {
    setState(() {
      _isLoading = true;
    });
    try {
      String vendorId = widget.vendor == null ? '' : widget.vendor.id;
      _myGames = await fetchGames(vendorId);
    } catch (error) {
      print(error);
    }

    setState(() {
      _isLoading = false;
    });
  }

  void _setFilterId(String typeId) {
    setState(() {
      filterTypeId = typeId;
    });
  }

  List<String> get _allGameTypeId {
    List<String> typeId = [];
    _myGames.map((game) {
      if (!typeId.contains(game.typeId)) {
        typeId.add(game.typeId);
      }
    }).toList();
    return typeId;
  }

  @override
  Widget build(BuildContext context) {
    return _isLoading
        ? Center(child: CircularProgressIndicator())
        : CustomScrollView(
            slivers: <Widget>[
              SliverAppBar(
                leading: Container(),
                backgroundColor: Colors.transparent,
                pinned: false,
                snap: false,
                floating: true,
                bottom: PreferredSize(
                  preferredSize: Size.fromHeight(widget.filterHeight),
                  child: Container(),
                ),
                flexibleSpace: Container(
                  decoration: currentTheme.getBgDecoration(1),
                  child: Column(
                    children: [
                      widget.flexibleSpace != null
                          ? widget.flexibleSpace
                          : SizedBox(),
                      LobbyGameFilter(_allGameTypeId, _setFilterId),
                    ],
                  ),
                ),
              ),
              LobbyGameGrid(
                vendor: widget.vendor,
                games: _myGames,
                filterTypeId: filterTypeId,
                onBackToGrid: widget.onBackToGrid,
                filterName: widget.filterName,
              ),
            ],
          );
  }
}
