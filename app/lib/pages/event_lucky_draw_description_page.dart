import 'package:flutter/material.dart';
import 'package:flutter_svg/flutter_svg.dart';
import 'package:futter_web_frontend/models/event.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/http_service.dart';
import 'package:futter_web_frontend/widgets/base/base_card.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';

import 'package:get/get.dart';
import 'package:futter_web_frontend/utils/utils.dart';

class EventLuckyDrawDescriptionPage extends StatefulWidget {
  final String eventId;
  EventLuckyDrawDescriptionPage(this.eventId);

  @override
  _EventLuckyDrawDescriptionPageState createState() =>
      _EventLuckyDrawDescriptionPageState();
}

class _EventLuckyDrawDescriptionPageState
    extends State<EventLuckyDrawDescriptionPage> {
  bool _loading = false;
  Event _event;

  final List<Map<String, String>> _prizes = [
    {
      'image': 'assets/images/events/activity_icon_level_1.svg',
      'title': 'Events_LuckyDraw_Reward_Type_Ultimate',
      'prize': 'Events_LuckyDraw_Reward_Type_Ultimate_Prize',
      'description': 'Events_LuckyDraw_Reward_Description_Ultimate',
    },
    {
      'image': 'assets/images/events/activity_icon_level_2.svg',
      'title': 'Events_LuckyDraw_Reward_Type_Level1',
      'prize': 'Events_LuckyDraw_Reward_Type_Level_Prize1',
      'description': 'Events_LuckyDraw_Reward_Description_Type_Level1',
    },
    {
      'image': 'assets/images/events/activity_icon_level_3.svg',
      'title': 'Events_LuckyDraw_Reward_Type_Level2',
      'prize': 'Events_LuckyDraw_Reward_Type_Level_Prize2',
      'description': 'Events_LuckyDraw_Reward_Description_Type_Level2',
    },
    {
      'image': 'assets/images/events/activity_icon_level_4.svg',
      'title': 'Events_LuckyDraw_Reward_Type_Level3',
      'prize': 'Events_LuckyDraw_Reward_Type_Level_Prize3',
      'description': 'Events_LuckyDraw_Reward_Description_Type_Level3',
    },
    {
      'image': 'assets/images/events/activity_icon_level_5.svg',
      'title': 'Events_LuckyDraw_Reward_Type_Level4',
      'prize': 'Events_LuckyDraw_Reward_Type_Level_Prize4',
      'description': 'Events_LuckyDraw_Reward_Description_Type_Level4',
    },
  ];
  @override
  void initState() {
    super.initState();
    _fetchData();
  }

  Future<void> _fetchData() async {
    setState(() {
      _loading = true;
    });
    _event = await fetchEvent(widget.eventId);
    setState(() {
      _loading = false;
    });
  }

  List<Widget> _buildPrizeContent() {
    return _prizes
        .asMap()
        .entries
        .map(
          (entry) => BaseCard(
            margin: EdgeInsets.symmetric(vertical: 5),
            padding: EdgeInsets.symmetric(horizontal: 15, vertical: 10),
            borderRadius: 5,
            insideBorderWidth: 0,
            borderColor: currentTheme.border,
            color: currentTheme.container,
            child: Row(
              children: [
                //image
                SvgPicture.asset(
                  entry.value['image'],
                  height: 40,
                ),
                SizedBox(width: 10),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisAlignment: MainAxisAlignment.spaceAround,
                    children: [
                      Row(
                        children: [
                          //標題
                          Text(
                            entry.value['title'].tr,
                            style: TextStyle(
                              color: currentTheme.textHead,
                              fontWeight: FontWeight.bold,
                            ),
                          ),
                          SizedBox(width: 5),
                          //獎金
                          Expanded(
                            child: Container(
                              child: Text(_event.prize[entry.key].name),
                            ),
                          ),
                        ],
                      ),
                      Container(
                        margin: EdgeInsets.symmetric(vertical: 5),
                        height: 0.5,
                        width: double.infinity,
                        color: Colors.grey,
                      ),
                      //說明
                      Text(entry.value['description'].tr),
                    ],
                  ),
                )
              ],
            ),
          ),
        )
        .toList();
  }

  String get getContent200 => 'Events_LuckyDraw_Description_Content_200'
      .tr
      .replaceNamedArgs({'point': _event.detail[0].point});
  String get getContent510 => 'Events_LuckyDraw_Description_Content_510'
      .tr
      .replaceNamedArgs({'prize': _event.prize[0].name});
  String get getContent520 => 'Events_LuckyDraw_Description_Content_520'
      .tr
      .replaceNamedArgs({'prize': _event.prize[1].name});
  String get getContent530 => 'Events_LuckyDraw_Description_Content_530'
      .tr
      .replaceNamedArgs({'prize': _event.prize[2].name});
  String get getContent540 => 'Events_LuckyDraw_Description_Content_540'
      .tr
      .replaceNamedArgs({'prize': _event.prize[3].name});
  String get getContent550 => 'Events_LuckyDraw_Description_Content_550'
      .tr
      .replaceNamedArgs({'prize': _event.prize[4].name});
  @override
  Widget build(BuildContext context) {
    return BaseScaffold(
      appbarBackgroundIndex: 1,
      bodyBackgroundIndex: 1,
      appbarTitle: Text(
        'eventDescription'.tr,
        style: TextStyle(
          color: currentTheme.textAppbar,
        ),
      ),
      child: _loading
          ? Center(child: CircularProgressIndicator())
          : SingleChildScrollView(
              child: Padding(
                padding: const EdgeInsets.all(10),
                child: BaseCard(
                  padding: EdgeInsets.all(8),
                  color: currentTheme.container,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      //活動說明
                      Text(
                        'eventDescription'.tr,
                        style: TextStyle(color: currentTheme.textHead),
                      ),
                      Text(
                        '''
1.${'Events_LuckyDraw_Description_Content_100'.tr}
2.$getContent200
3.${'Events_LuckyDraw_Description_Content_300'.tr}
4.${'Events_LuckyDraw_Description_Content_400'.tr}
5.${'Events_LuckyDraw_Description_Content_500'.tr}
    1.$getContent510
    2.$getContent520
    3.$getContent530
    4.$getContent540
    5.$getContent550
6.${'Events_LuckyDraw_Description_Content_600'.tr}
''',
                      ),
                      //獎項
                      Text(
                        'Commom_Label_PrizeContent'.tr,
                        style: TextStyle(color: currentTheme.textHead),
                      ),
                      ..._buildPrizeContent(),
                    ],
                  ),
                ),
              ),
            ),
    );
  }
}
