import 'package:flutter/material.dart';
import 'package:futter_web_frontend/pages/record_events_page.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/http_service.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:futter_web_frontend/widgets/base/appbar_title_text.dart';
import 'package:futter_web_frontend/widgets/base/base_button.dart';
import 'package:futter_web_frontend/widgets/base/base_card.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';
import 'package:futter_web_frontend/widgets/event/event_description_title.dart';
import 'package:futter_web_frontend/widgets/event/event_detail_column.dart';
import 'package:futter_web_frontend/widgets/event/event_get_prize_button.dart';

import 'package:futter_web_frontend/models/event.dart';
import 'package:get/get.dart';

class EventDescriptionPage extends StatefulWidget {
  final String eventId;
  final Function() onRedeem;
  EventDescriptionPage(this.eventId, {this.onRedeem});
  @override
  _EventDescriptionPageState createState() => _EventDescriptionPageState();
}

class _EventDescriptionPageState extends State<EventDescriptionPage> {
  bool _loading = false;
  Event _event;
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

  void _refresh() {
    _fetchData();
    if (widget.onRedeem != null) widget.onRedeem();
  }

  Widget _buildCard(Widget child) {
    return BaseCard(
      margin: EdgeInsets.only(top: 20),
      padding: EdgeInsets.all(8),
      colors: currentTheme.buttonList,
      child: child,
    );
  }

  @override
  Widget build(BuildContext context) {
    return BaseScaffold(
      appbarBackgroundIndex: 0,
      bodyBackgroundIndex: 0,
      appbarTitle: AppbarTitleText('eventDescription'.tr),
      child: _loading
          ? Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: () => _fetchData(),
              child: SingleChildScrollView(
                child: Padding(
                  padding: const EdgeInsets.all(10),
                  child: Column(
                    children: [
                      //活動標題
                      _buildCard(EventDescriptionTitle(_event)),

                      //活動內容
                      ..._event.detail.map(
                        (detail) => _buildCard(
                          Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                'achieveConditions'.tr,
                                style: TextStyle(
                                  color: currentTheme.textHead,
                                  fontSize: 17,
                                ),
                              ),
                              EventDetailColumn(_event, detail),
                            ],
                          ),
                        ),
                      ),

                      SizedBox(height: 20),
                      //遊戲下拉選單
                      ..._event.detail.map(
                        (detail) {
                          if (detail.games.length > 0) {
                            return BaseCard(
                              height: 45,
                              width: double.infinity,
                              padding: EdgeInsets.symmetric(horizontal: 8),
                              colors: currentTheme.buttonDanger,
                              child: DropdownButton(
                                underline: SizedBox(),
                                isExpanded: true,
                                items: detail.games
                                    .map(
                                      (game) => DropdownMenuItem(
                                        child: Text(game.name),
                                        value: '1',
                                      ),
                                    )
                                    .toList(),
                                hint: Row(
                                  children: [
                                    Icon(
                                      Icons.card_giftcard,
                                      color: currentTheme
                                          .theme.textTheme.button.color,
                                    ),
                                    SizedBox(width: 10),
                                    Text(
                                      '${detail.vendorName} (${detail.actionName})',
                                      style: TextStyle(
                                        color: currentTheme
                                            .theme.textTheme.button.color,
                                      ),
                                    ),
                                  ],
                                ),
                                icon: Icon(
                                  Icons.arrow_drop_down,
                                  color:
                                      currentTheme.theme.textTheme.button.color,
                                ),
                                onChanged: (value) {},
                              ),
                            );
                          } else
                            return SizedBox();
                        },
                      ),
                      SizedBox(height: 20),

                      //Button
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceAround,
                        children: [
                          Expanded(
                            child: EventGetPrizeButton(
                              _event,
                              onRedeem: _refresh,
                            ),
                          ),
                          SizedBox(width: 20),
                          Expanded(
                            child: BaseButton(
                              text: 'exchangeRecord'.tr,
                              colors: currentTheme.buttonPrimary,
                              onPressed: () => push(RecordEventsPage()),
                            ),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
              ),
            ),
    );
  }
}
