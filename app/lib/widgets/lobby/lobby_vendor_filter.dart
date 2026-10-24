import 'package:flutter/material.dart';
import 'package:futter_web_frontend/models/filter.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:get/get.dart';

class LobbyVendorFilter extends StatefulWidget {
  final List<String> _showFilterId; //要顯示的id
  final String filterId;
  final Function onFilter;

  LobbyVendorFilter(this._showFilterId, this.filterId, this.onFilter);
  @override
  _LobbyVendorFilterState createState() => _LobbyVendorFilterState();
}

class _LobbyVendorFilterState extends State<LobbyVendorFilter> {
  List<Filter> _allFilterItems = [
    Filter(
      nameKey: "allGames",
      id: null,
      imagePath: currentTheme.getTemplateImage('filter_all.png'),
      highlightPath: currentTheme.getTemplateImage('filter_all_highlight.png'),
    ),
    Filter(
      nameKey: 'Common_GameType_Slot',
      id: "2",
      imagePath: currentTheme.getTemplateImage('filter_slots.png'),
      highlightPath:
          currentTheme.getTemplateImage('filter_slots_highlight.png'),
    ),
    Filter(
      nameKey: 'Common_GameType_Reality',
      id: "1",
      imagePath: currentTheme.getTemplateImage('filter_livecasino.png'),
      highlightPath:
          currentTheme.getTemplateImage('filter_livecasino_highlight.png'),
    ),
    Filter(
      nameKey: 'Common_GameType_Poker',
      id: "3",
      imagePath: currentTheme.getTemplateImage('filter_poker.png'),
      highlightPath:
          currentTheme.getTemplateImage('filter_poker_highlight.png'),
    ),
    Filter(
      nameKey: 'Common_GameType_Fishing',
      id: "5",
      imagePath: currentTheme.getTemplateImage('filter_fishing.png'),
      highlightPath:
          currentTheme.getTemplateImage('filter_fishing_highlight.png'),
    ),
    Filter(
      nameKey: 'Common_GameType_Lottery',
      id: "4",
      imagePath: currentTheme.getTemplateImage('filter_lottery.png'),
      highlightPath:
          currentTheme.getTemplateImage('filter_lottery_highlight.png'),
    ),
    Filter(
      nameKey: 'Common_GameType_Sports',
      id: "18",
      imagePath: currentTheme.getTemplateImage('filter_sports.png'),
      highlightPath:
          currentTheme.getTemplateImage('filter_sports_highlight.png'),
    ),
    Filter(
      nameKey: 'Common_GameType_ESports',
      id: "19",
      imagePath: currentTheme.getTemplateImage('filter_esports.png'),
      highlightPath:
          currentTheme.getTemplateImage('filter_esports_highlight.png'),
    ),
    Filter(
      nameKey: 'Common_GameType_Arcade',
      id: "15",
      imagePath: currentTheme.getTemplateImage('filter_arcade.png'),
      highlightPath:
          currentTheme.getTemplateImage('filter_arcade_highlight.png'),
    ),
    Filter(
      nameKey: 'Common_GameType_BarMachine',
      id: "21",
      imagePath: currentTheme.getTemplateImage('filter_barmachine.png'),
      highlightPath:
          currentTheme.getTemplateImage('filter_barmachine_highlight.png'),
    ),
  ];

  String _filterTypeId;

  List<Filter> get _showFilterItems {
    return _allFilterItems
        .where(
          (item) => item.id == null || widget._showFilterId.contains(item.id),
        )
        .toList();
  }

  @override
  void initState() {
    super.initState();
    _filterTypeId = widget.filterId;
  }

  Widget buildFilterItem(String title, String iconPath) {
    return Container(
      height: 70,
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Image.asset(
            iconPath,
            height: 40,
          ),
          SizedBox(height: 7),
          FittedBox(child: Text(title)),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: EdgeInsets.symmetric(vertical: 8),
      child: SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        child: ToggleButtons(
          borderColor: Colors.transparent,
          selectedBorderColor: Colors.transparent,
          fillColor: Colors.transparent,
          splashColor: Colors.transparent,
          color: Colors.grey,
          selectedColor: currentTheme.textHead,
          children: [
            ..._showFilterItems.map((item) {
              var title = item.nameKey.tr;
              var iconPath = item.id == _filterTypeId
                  ? item.highlightPath
                  : item.imagePath;
              return buildFilterItem(title, iconPath);
            }),
          ],
          isSelected: [
            ..._showFilterItems.map((item) {
              return item.id == _filterTypeId;
            }),
          ],
          onPressed: (index) {
            if (_filterTypeId == _showFilterItems[index].id) return;
            _filterTypeId = _showFilterItems[index].id;
            widget.onFilter(_filterTypeId);
          },
        ),
      ),
    );
  }
}
