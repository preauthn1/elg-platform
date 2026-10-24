import 'package:flutter/material.dart';
import 'package:futter_web_frontend/pages/event_description_page.dart';
import 'package:futter_web_frontend/pages/event_lucky_draw_description_page.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/http_service.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:futter_web_frontend/widgets/base/appbar_title_text.dart';
import 'package:futter_web_frontend/widgets/base/base_button.dart';
import 'package:futter_web_frontend/widgets/base/base_card.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';
import 'package:futter_web_frontend/widgets/event/event_advertisements.dart';
import 'package:futter_web_frontend/widgets/event/event_detail_column.dart';
import 'package:futter_web_frontend/widgets/event/event_get_prize_button.dart';
import 'package:futter_web_frontend/widgets/event/events_overview_title.dart';

import 'package:futter_web_frontend/models/event.dart';
import 'package:get/get.dart';

class EventsPage extends StatefulWidget {
  @override
  _EventsPageState createState() => _EventsPageState();
}

class _EventsPageState extends State<EventsPage> {
  List<Event> _events = [];
  Future<void> _refresh() async {
    setState(() {});
  }

  List<Color> getColors(Event event) {
    switch (event.type) {
      case '1':
      case '2':
      case '3':
        return currentTheme.buttonList;
      default:
        return currentTheme.buttonSecondary;
    }
  }

  //活動
  List<Widget> _buildEvent() {
    List<Event> events = [];
    //摸彩
    events.addAll(_events
        .where((element) => element.type == '4' || element.type == '5')
        .toList());
    //活動
    events.addAll(_events
        .where((element) => element.type != '4' && element.type != '5')
        .toList());
    return events.map(
      (event) {
        return BaseCard(
          margin: EdgeInsets.symmetric(vertical: 10),
          padding: EdgeInsets.all(8),
          borderWidth: 2,
          colors: getColors(event),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              //標題
              EventOverviewTitle(event),
              SizedBox(height: 10),

              if (event.type == '5')
                //抽獎券
                Text(
                  'Events_LuckyDraw_EventCard_Content'
                      .tr
                      .replaceNamedArgs({'point': event.detail[0].point}),
                  style: TextStyle(color: Colors.grey),
                )
              else
                //內容
                ...event.detail.map(
                  (detail) {
                    return EventDetailColumn(event, detail);
                  },
                ),

              //開獎日期
              if (event.type == '4')
                Text(
                  '${'drawDate'.tr} : ${dateFormatter(event.prizeDrawDate)}',
                  style: TextStyle(color: currentTheme.textDanger),
                ),
              SizedBox(height: 10),
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceAround,
                children: [
                  //兌換按鈕
                  Expanded(
                    child: EventGetPrizeButton(
                      event,
                      onRedeem: _refresh,
                    ),
                  ),
                  SizedBox(width: 20),
                  //活動說明
                  Expanded(
                    child: BaseButton(
                      colors: currentTheme.buttonPrimary,
                      text: "eventDescription".tr,
                      onPressed: () {
                        if (event.type == '4' || event.type == '5') {
                          push(EventLuckyDrawDescriptionPage(event.id));
                        } else {
                          push(
                            EventDescriptionPage(
                              event.id,
                              onRedeem: _refresh,
                            ),
                          );
                        }
                      },
                    ),
                  ),
                ],
              ),
            ],
          ),
        );
      },
    ).toList();
  }

  @override
  Widget build(BuildContext context) {
    return BaseScaffold(
      appbarBackgroundIndex: 1,
      bodyBackgroundIndex: 0,
      appbarTitle: AppbarTitleText('events'.tr),
      child: FutureBuilder(
        future: fetchEvents(),
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return Center(child: CircularProgressIndicator());
          } else {
            _events = snapshot.data as List<Event>;
            return RefreshIndicator(
              onRefresh: _refresh,
              child: SingleChildScrollView(
                scrollDirection: Axis.vertical,
                child: Container(
                  padding: EdgeInsets.symmetric(horizontal: 12),
                  child: Column(
                    children: [
                      //廣告列表
                      EventAdvertisements(),

                      //提示
                      Container(
                        width: double.infinity,
                        child: Text('*' + 'updatedTenMinutes'.tr),
                      ),
                      Container(
                        width: double.infinity,
                        child: Row(
                          children: [
                            Container(
                              height: 18,
                              child: Image.asset(
                                  'assets/images/events/ticket.png'),
                            ),
                            Text(' : ' + 'cumulativeClaim'.tr),
                          ],
                        ),
                      ),
                      //活動列表
                      ..._buildEvent(),
                    ],
                  ),
                ),
              ),
            );
          }
        },
      ),
    );
  }
}
