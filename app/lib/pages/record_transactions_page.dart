import 'package:flutter/material.dart';
import 'package:futter_web_frontend/models/transaction.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/http_service.dart';
import 'package:futter_web_frontend/widgets/base/appbar_title_text.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';

import 'package:futter_web_frontend/widgets/record/record_data_container.dart';
import 'package:get/get.dart';

class RecordTransactionsPage extends StatefulWidget {
  @override
  _RecordTransactionsPageState createState() => _RecordTransactionsPageState();
}

class _RecordTransactionsPageState extends State<RecordTransactionsPage> {
  final List<String> _titleList = [
    'num'.tr,
    'date'.tr,
    'gamepublisher'.tr,
  ];

  List<Transaction> _transaction;

  Future<void> fetchData() async {
    _transaction = await fetchTransaction();
  }

  @override
  Widget build(BuildContext context) {
    return BaseScaffold(
      appbarBackgroundIndex: 0,
      bodyBackgroundIndex: 0,
      appbarTitle: AppbarTitleText('platformTransferRecord'.tr),
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
                        Column(
                          children: [
                            Text(
                              'transferIn'.tr,
                              textAlign: TextAlign.center,
                              style: TextStyle(
                                fontWeight: FontWeight.bold,
                                color: currentTheme.textLink,
                              ),
                            ),
                            Text('transferOut'.tr,
                                textAlign: TextAlign.center,
                                style: TextStyle(
                                    fontWeight: FontWeight.bold,
                                    color: currentTheme.textDanger)),
                          ],
                        ),
                      ],
                    ),
                    ..._transaction.map(
                      (transaction) {
                        return TableRow(
                          children: [
                            RecordDataContainer(transaction.id),
                            RecordDataContainer(transaction.updatedAt),
                            RecordDataContainer(transaction.vendorName),
                            RecordDataContainer(
                              transaction.point,
                              style: TextStyle(
                                  color: transaction.type == '1'
                                      ? currentTheme.textLink
                                      : currentTheme.textDanger),
                            ),
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
