import 'package:flutter/material.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:futter_web_frontend/models/event.dart';
import 'package:get/get.dart';

///活動說明標題
class EventDescriptionTitle extends StatelessWidget {
  final Event event;
  EventDescriptionTitle(this.event);
  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            //標題
            Row(
              children: [
                Text(
                  event.title,
                  style: TextStyle(
                    color: currentTheme.textHead,
                    fontSize: 17,
                  ),
                ),
                //可重複獲獎圖示
                Expanded(child: Container()),
                if (event.prizeIsRepeatedly == '1')
                  Container(
                    height: 17,
                    child: Image.asset('assets/images/events/ticket.png'),
                  ),
              ],
            ),
            SizedBox(height: 10),
            //期限
            Text(
                '${'duringTheEvent'.tr}: ${dateFormatter(event.startTime)} ~ ${dateFormatter(event.endTime)}'),
            Text(
              '${'redemptionPeriod'.tr}: ${dateFormatter(event.expireDate)}',
            ),
            //領獎說明
            Text('${'description'.tr}:'),
            Text('remarks'.tr),
          ],
        ),
      ],
    );
  }
}
