import 'package:flutter/material.dart';
import 'package:futter_web_frontend/models/withdrawal_record.dart';
import 'package:futter_web_frontend/utils/http_service.dart';
import 'package:futter_web_frontend/widgets/base/appbar_title_text.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';

import 'package:futter_web_frontend/widgets/record/record_data_container.dart';
import 'package:get/get.dart';

class RecordWithdrawalPage extends StatefulWidget {
  @override
  _RecordWithdrawalPageState createState() => _RecordWithdrawalPageState();
}

class _RecordWithdrawalPageState extends State<RecordWithdrawalPage> {
  final List<String> _titleList = [
    'date'.tr,
    'amount'.tr,
    'Common_Status'.tr,
  ];

  List<WithdrawalRecord> _transaction;

  Future<void> fetchData() async {
    _transaction = await fetchWithdrawalRecord();
  }

  @override
  Widget build(BuildContext context) {
    return BaseScaffold(
      appbarBackgroundIndex: 0,
      bodyBackgroundIndex: 0,
      appbarTitle: AppbarTitleText('withdrawal_record'.tr),
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
                    ..._transaction.map(
                      (transaction) {
                        return TableRow(
                          children: [
                            RecordDataContainer(transaction.createdAt),
                            RecordDataContainer(transaction.amount),
                            RecordDataContainer(transaction.status),
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
