import 'package:flutter/material.dart';
import 'package:futter_web_frontend/controller/template_controller.dart';
import 'package:futter_web_frontend/models/prize.dart';
import 'package:futter_web_frontend/utils/http_service.dart';
import 'package:futter_web_frontend/widgets/base/appbar_title_text.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';

import 'package:futter_web_frontend/widgets/record/record_data_container.dart';
import 'package:get/get.dart';

class RecordEventsPage extends StatefulWidget {
  @override
  _RecordEventsPageState createState() => _RecordEventsPageState();
}

class _RecordEventsPageState extends State<RecordEventsPage> {
  final List<String> _titleList = [
    'date'.tr,
    'eventname'.tr,
    'itemname'.tr,
    TemplateController.to.textWalletPoint,
  ];

  List<Prize> _prize;

  Future<void> fetchData() async {
    _prize = await fetchPrize();
  }

  @override
  Widget build(BuildContext context) {
    return BaseScaffold(
      appbarBackgroundIndex: 0,
      bodyBackgroundIndex: 0,
      appbarTitle: AppbarTitleText('scratchCardTransferRecord'.tr),
      child: FutureBuilder(
        future: fetchData(),
        builder: (context, snapshot) => snapshot.connectionState ==
                ConnectionState.waiting
            ? Center(child: CircularProgressIndicator())
            : SingleChildScrollView(
                child: Table(
                  defaultVerticalAlignment: TableCellVerticalAlignment.middle,
                  children: [
                    TableRow(
                      children: [
                        ..._titleList.map(
                          (title) {
                            return Container(
                              padding: EdgeInsets.all(3),
                              child: Text(
                                title,
                                style: TextStyle(fontWeight: FontWeight.bold),
                                textAlign: TextAlign.center,
                              ),
                            );
                          },
                        ),
                      ],
                    ),
                    ..._prize.map(
                      (prize) {
                        return TableRow(
                          children: [
                            RecordDataContainer(prize.updatedAt),
                            RecordDataContainer(prize.eventName),
                            RecordDataContainer(prize.name),
                            RecordDataContainer(prize.point),
                          ],
                        );
                      },
                    )
                  ],
                ),
              ),
      ),
    );
  }
}
