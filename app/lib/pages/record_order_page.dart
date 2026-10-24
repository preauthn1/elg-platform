import 'package:flutter/material.dart';
import 'package:futter_web_frontend/models/order.dart';
import 'package:futter_web_frontend/utils/http_service.dart';
import 'package:futter_web_frontend/widgets/base/appbar_title_text.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';

import 'package:futter_web_frontend/widgets/record/record_data_container.dart';
import 'package:get/get.dart';

class RecordOrderPage extends StatefulWidget {
  @override
  _RecordOrderPageState createState() => _RecordOrderPageState();
}

class _RecordOrderPageState extends State<RecordOrderPage> {
  List<Order> _orderList;
  Future<void> fetchData() async {
    _orderList = await fetchOrder();
  }

  Widget _buildTitleText(String text) {
    return Container(
      padding: EdgeInsets.all(3),
      child: Text(
        text,
        style: TextStyle(fontWeight: FontWeight.bold),
        textAlign: TextAlign.center,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return BaseScaffold(
      appbarBackgroundIndex: 0,
      bodyBackgroundIndex: 0,
      appbarTitle: AppbarTitleText('Label_DepositRecord'.tr),
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
                        _buildTitleText('date'.tr),
                        _buildTitleText('Common_PaymentWay'.tr),
                        Column(
                          children: [
                            _buildTitleText('amount'.tr),
                            Container(
                              width: double.infinity,
                              height: 0.3,
                              color: Colors.grey,
                            ),
                            _buildTitleText('Common_GamePoint'.tr),
                          ],
                        ),
                        _buildTitleText('Common_Status'.tr),
                      ],
                    ),
                    ..._orderList.map(
                      (order) {
                        return TableRow(
                          children: [
                            RecordDataContainer(order.createdAt),
                            RecordDataContainer(order.orderType),
                            Column(children: [
                              Text(order.amount),
                              Text(order.point),
                            ]),
                            RecordDataContainer(order.statusName),
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
