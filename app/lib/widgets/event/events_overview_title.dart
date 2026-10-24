import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:futter_web_frontend/models/event.dart';
import 'package:get/get.dart';

///活動總覽標題
class EventOverviewTitle extends StatelessWidget {
  final Event event;
  EventOverviewTitle(this.event);

  void _toResultReference() async {
    await openBrowser('http://example.invalid/results/today/');
  }

  Widget _buildRedeemable() => Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          //可領獎次數
          Text(
            '${'redeemable'.tr}: ${event.count}',
            style: TextStyle(color: currentTheme.textDanger),
          ),
          //領獎說明
          Text('${'description'.tr}:\n' + 'remarks'.tr),
        ],
      );

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        //標題
        Row(
          children: [
            if (event.typeId == '2')
              Container(
                height: 20,
                margin: EdgeInsets.only(right: 10),
                child: Image.asset('assets/images/events/group.png'),
              ),
            RichText(
              textAlign: TextAlign.end,
              text: TextSpan(
                children: [
                  TextSpan(
                    text: event.title,
                    style: TextStyle(
                      color: currentTheme.textHead,
                      fontSize: 17,
                    ),
                  ),
                  WidgetSpan(child: SizedBox(width: 10)),
                  TextSpan(
                    text: event.id,
                    style: TextStyle(color: Colors.grey),
                  ),
                ],
              ),
            ),

            //可重複獲獎圖示
            Expanded(child: Container()),
            if (event.prizeIsRepeatedly == '1' &&
                (event.type == '3' || event.type == '5'))
              Container(
                height: 17,
                child: Image.asset('assets/images/events/ticket.png'),
              ),
          ],
        ),

        SizedBox(height: 10),
        //活動期間
        Text(
            '${'duringTheEvent'.tr}: ${dateFormatter(event.startTime)} ~ ${dateFormatter(event.endTime)}'),
        //豪龍 6+1D 期數
        if (event.type == '5')
          Row(
            children: [
              Text(
                  '${'Events_LuckyDraw_ThirdPartyIssueID'.tr}: ${dateFormatter(event.prizeDrawDate, format: 'yyyy/MM/dd')}'),
              SizedBox(width: 10),
              //開獎參考
              RichText(
                text: TextSpan(
                  text: 'Label_ResultReference'.tr,
                  style: TextStyle(
                    color: currentTheme.textLink,
                    fontSize: Theme.of(context).textTheme.bodyText2.fontSize,
                  ),
                  recognizer: TapGestureRecognizer()
                    ..onTap = _toResultReference,
                ),
              ),
            ],
          ),

        //可領獎次數
        if (event.type == '1' || event.type == '2' || event.type == '3')
          _buildRedeemable(),
      ],
    );
  }
}
