import 'package:flutter/material.dart';
import 'package:futter_web_frontend/models/lucky_draw_issues.dart';
import 'package:futter_web_frontend/pages/event_lucky_draw_detail_page.dart';
import 'package:futter_web_frontend/utils/http_service.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:futter_web_frontend/widgets/base/appbar_title_text.dart';
import 'package:futter_web_frontend/widgets/base/base_list_icon_button.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';

import 'package:get/get.dart';

class EventLuckyDrawIssuesPage extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return BaseScaffold(
      appbarBackgroundIndex: 1,
      bodyBackgroundIndex: 1,
      appbarTitle: AppbarTitleText('Events_LuckyDraw_Label_MyRaffleTicket'.tr),
      child: FutureBuilder(
        future: fetchLuckyDrawIssues(),
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return Center(child: CircularProgressIndicator());
          } else {
            var issues = snapshot.data as List<LuckyDrawIssues>;
            return SingleChildScrollView(
              child: Column(
                children: [
                  ...issues.map(
                    (issue) => BaseListIconButton.widget(
                      icon: ListIcon(SizedBox()),
                      child: Row(
                        children: [
                          Text(issue.issueName),
                          SizedBox(width: 10),
                          Text(
                            dateFormatter(issue.drawTime, format: 'yyyy/MM/dd'),
                            style: TextStyle(color: Colors.grey),
                          ),
                        ],
                      ),
                      onPressed: () =>
                          push(EventLuckyDrawDetailPage(issue.issueId)),
                    ),
                  ),
                ],
              ),
            );
          }
        },
      ),
    );
  }
}
