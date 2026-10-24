import 'package:flutter/material.dart';
import 'package:futter_web_frontend/models/game_types.dart';
import 'package:futter_web_frontend/models/vendor.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/http_service.dart';
import 'package:futter_web_frontend/widgets/lobby/lobby_vendor_filter.dart';
import 'package:futter_web_frontend/widgets/lobby/lobby_vendor_grid.dart';

class LobbyVendorList extends StatefulWidget {
  @override
  _LobbyVendorListState createState() => _LobbyVendorListState();
}

class _LobbyVendorListState extends State<LobbyVendorList> {
  bool _initializing = true;
  String _filterTypeId;
  List<Vendor> _vendors = [];
  List<GameTypes> _gameTypes = [];

  @override
  void initState() {
    super.initState();
    _initializing = true;
    fetchData().then((_) {
      setState(() {
        _initializing = false;
      });
    });
  }

  void setFilterVendorsId(String id) {
    setState(() {
      _filterTypeId = id;
    });
  }

  Future<void> fetchData() async {
    if (_vendors.isNotEmpty) return;
    _vendors = await fetchVendors();
    _gameTypes = await fetchVendorGameTypes();
  }

  List<String> get _showFilterId {
    List<String> filterId = [];
    _vendors.forEach((vendor) {
      for (GameTypes gameType in _gameTypes) {
        if (gameType.vendorId == vendor.id &&
            !filterId.contains(gameType.typeId)) {
          filterId.add(gameType.typeId);
        }
      }
    });
    return filterId;
  }

  @override
  Widget build(BuildContext context) {
    return _initializing
        ? Center(child: CircularProgressIndicator())
        : CustomScrollView(
            slivers: <Widget>[
              SliverAppBar(
                backgroundColor: Colors.transparent,
                pinned: false,
                snap: false,
                floating: true,
                bottom: PreferredSize(
                  preferredSize: Size.fromHeight(30),
                  child: Container(),
                ),
                flexibleSpace: Container(
                  decoration: currentTheme.getBgDecoration(1),
                  child: LobbyVendorFilter(
                    _showFilterId,
                    _filterTypeId,
                    setFilterVendorsId,
                  ),
                ),
              ),
              LobbyVendorGrid(_vendors, _gameTypes, _filterTypeId),
            ],
          );
  }
}
