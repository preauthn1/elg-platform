import 'package:flutter/material.dart';
import 'package:futter_web_frontend/models/lucky_draw_issues_detail.dart';
import 'package:futter_web_frontend/pages/event_lucky_draw_tickets_page.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/http_service.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:futter_web_frontend/widgets/base/appbar_title_text.dart';
import 'package:futter_web_frontend/widgets/base/base_button.dart';
import 'package:futter_web_frontend/widgets/base/base_card.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';

import 'package:get/get.dart';

class EventLuckyDrawDetailPage extends StatefulWidget {
  final String issueId;
  EventLuckyDrawDetailPage(this.issueId);

  @override
  _EventLuckyDrawDetailPageState createState() =>
      _EventLuckyDrawDetailPageState();
}

class _EventLuckyDrawDetailPageState extends State<EventLuckyDrawDetailPage> {
  LuckyDrawIssuesDetail _detail;

  void _receiveAll() async {
    showLoadingDialog();
    try {
      await luckyDrawReceiveAll(_detail.issueId);
      pop();
      await showMessageDialog(
          message: '${'successfully'.tr}！\n${'Msg_AnyQuestionPleaseAskCS'.tr}');
      setState(() {});
    } catch (error) {
      pop();
    }
  }

  Widget _buildDetail() {
    return Container(
      width: double.infinity,
      padding: EdgeInsets.all(15),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: currentTheme.buttonSecondary,
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          //標題
          Row(
            children: [
              // Image.asset(
              //   'assets/images/events/group.png',
              //   height: 20,
              // ),
              // SizedBox(width: 10),
              Text(
                _detail.issueName,
                style: TextStyle(
                  color: currentTheme.textHead,
                  fontSize: 15,
                ),
              ),
            ],
          ),
          SizedBox(height: 10),
          //活動期間
          Text('duringTheEvent'.tr),
          Text(
            dateFormatter(
                  _detail.beginTime,
                  format: 'yyyy/MM/dd HH:mm:ss',
                ) +
                ' ~ ' +
                dateFormatter(
                  _detail.endTime,
                  format: 'yyyy/MM/dd HH:mm:ss',
                ),
            style: TextStyle(color: Colors.grey[800]),
          ),
          SizedBox(height: 10),
          //開獎號碼
          Text('Events_LuckyDraw_Label_WinningNumbers'.tr),
          Text(
            _detail.resultNumber,
            style: TextStyle(color: Colors.grey),
          ),
        ],
      ),
    );
  }

  Widget _buildRaffleTicket() {
    return BaseCard(
      color: currentTheme.container,
      insideBorderWidth: 0,
      margin: EdgeInsets.all(20),
      padding: EdgeInsets.all(20),
      width: double.infinity,
      child: Column(
        children: [
          //我的抽獎券
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Image.asset(
                'assets/images/events/activity_icon_lottery.png',
                width: 40,
              ),
              SizedBox(width: 5),
              Text(
                'Events_LuckyDraw_Label_MyRaffleTicket'.tr,
                style: TextStyle(
                  color: currentTheme.textHead,
                  fontSize: 15,
                  fontWeight: FontWeight.bold,
                ),
              ),
              SizedBox(width: 45),
            ],
          ),
          SizedBox(height: 10),
          //抽獎券數
          Text(
            'Events_LuckyDraw_Label_Count_LotteryTickets'.tr +
                ' : ' +
                _detail.summary.totalCountTickets,
            style: TextStyle(color: Colors.grey),
          ),
          SizedBox(height: 5),
          //中獎總額
          Text(
            'Events_LuckyDraw_Label_Count_TotalWinnings'.tr +
                ' : ' +
                _detail.summary.totalCountWinnings,
            style: TextStyle(color: Colors.grey),
          ),
          SizedBox(height: 5),
          //未領取獎額
          Text(
            'Events_LuckyDraw_Label_Count_UnclaimedPrize'.tr +
                ' : ' +
                _detail.summary.totalCountUnclaimed,
            style: TextStyle(color: Colors.grey),
          ),
          SizedBox(height: 20),
          //一次領取
          double.parse(_detail.summary.totalCountUnclaimed) > 0
              //可領取
              ? BaseButton(
                  width: 200,
                  height: 30,
                  colors: currentTheme.buttonPrimary,
                  text: 'Events_LuckyDraw_Label_UnclaimedOnetime'.tr,
                  onPressed: _receiveAll,
                )
              //不可領取
              : BaseButton.widget(
                  width: 200,
                  height: 30,
                  colors: currentTheme.buttonDisable,
                  child: Text(
                    'Events_LuckyDraw_Label_UnclaimedOnetime'.tr,
                    style: TextStyle(color: currentTheme.textMuted),
                  ),
                ),
          SizedBox(height: 15),
          //抽獎券
          BaseButton(
            width: 200,
            height: 30,
            colors: currentTheme.buttonWarning,
            text: 'Events_LuckyDraw_Label_LotteryTicket'.tr,
            onPressed: () => push(
              EventLuckyDrawTicketsPage(
                widget.issueId,
                onReceive: () {
                  setState(() {});
                },
              ),
            ),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return BaseScaffold(
      appbarBackgroundIndex: 1,
      bodyBackgroundIndex: 1,
      appbarTitle: AppbarTitleText('Events_LuckyDraw_Label_MyRaffleTicket'.tr),
      child: FutureBuilder(
        future: fetchLuckyDrawIssueDetail(widget.issueId),
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return Center(child: CircularProgressIndicator());
          } else {
            _detail = snapshot.data as LuckyDrawIssuesDetail;
            return SingleChildScrollView(
              child: Column(
                children: [
                  _buildDetail(),
                  _buildRaffleTicket(),
                ],
              ),
            );
          }
        },
      ),
    );
  }
}
