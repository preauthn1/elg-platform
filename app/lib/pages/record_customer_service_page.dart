import 'package:flutter/material.dart';
import 'package:futter_web_frontend/models/customer_service.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/http_service.dart';
import 'package:futter_web_frontend/widgets/base/appbar_title_text.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';

import 'package:futter_web_frontend/widgets/record/record_data_container.dart';
import 'package:get/get.dart';

class RecordCustomerServicePage extends StatefulWidget {
  @override
  _RecordCustomerServicePageState createState() =>
      _RecordCustomerServicePageState();
}

class _RecordCustomerServicePageState extends State<RecordCustomerServicePage> {
  final List<String> _titleList = [
    'orderid'.tr,
    'date'.tr,
  ];

  List<CustomerService> _customerService;

  Future<void> fetchData() async {
    _customerService = await fetchCustomerService();
  }

  @override
  Widget build(BuildContext context) {
    return BaseScaffold(
      appbarBackgroundIndex: 0,
      bodyBackgroundIndex: 0,
      appbarTitle: AppbarTitleText('customerServiceTransferRecord'.tr),
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
                            return Text(
                              title,
                              style: TextStyle(fontWeight: FontWeight.bold),
                              textAlign: TextAlign.center,
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
                            Text(
                              'transferOut'.tr,
                              textAlign: TextAlign.center,
                              style: TextStyle(
                                  fontWeight: FontWeight.bold,
                                  color: currentTheme.textDanger),
                            ),
                          ],
                        ),
                      ],
                    ),
                    ..._customerService.map(
                      (customerService) {
                        return TableRow(
                          children: [
                            RecordDataContainer(customerService.id),
                            RecordDataContainer(customerService.createdAt),
                            RecordDataContainer(
                              customerService.point,
                              style: TextStyle(
                                  color: customerService.type == '1'
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
