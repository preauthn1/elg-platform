import 'package:flutter/material.dart';
import 'package:futter_web_frontend/models/red_envelope.dart';
import 'package:futter_web_frontend/utils/http_service.dart';
import 'package:futter_web_frontend/widgets/base/appbar_title_text.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';

import 'package:futter_web_frontend/widgets/record/record_data_container.dart';
import 'package:get/get.dart';

class RecordRedEnvelopePage extends StatefulWidget {
  final int type;
  RecordRedEnvelopePage(this.type);

  @override
  _RecordRedEnvelopePageState createState() => _RecordRedEnvelopePageState();
}

class _RecordRedEnvelopePageState extends State<RecordRedEnvelopePage> {
  final List<String> _tableTitleList = [
    'date'.tr,
    'friend'.tr,
    'amount'.tr,
  ];
  String get title {
    switch (widget.type) {
      case 1:
        return 'redEnvelope'.tr;
        break;
      case 2:
        return 'receivedRedEnvelope'.tr;
        break;
      case 3:
        return 'redEnvelopeRefund'.tr;
        break;
      default:
        return '';
        break;
    }
  }

  List<RedEnvelope> _redEnvelope;

  Future<void> fetchData() async {
    _redEnvelope = await fetchRedEnvelopes(widget.type);
  }

  @override
  Widget build(BuildContext context) {
    return BaseScaffold(
      appbarBackgroundIndex: 0,
      bodyBackgroundIndex: 0,
      appbarTitle: AppbarTitleText(title),
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
                        ..._tableTitleList.map(
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
                    ..._redEnvelope.map(
                      (redEnvelope) {
                        return TableRow(
                          children: [
                            RecordDataContainer(redEnvelope.createdAt),
                            RecordDataContainer(redEnvelope.nickname),
                            RecordDataContainer(redEnvelope.point),
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
