import 'package:flutter/material.dart';
import 'package:futter_web_frontend/pages/record_red_envelope_page.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:futter_web_frontend/widgets/base/appbar_title_text.dart';
import 'package:futter_web_frontend/widgets/base/base_button.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';

import 'package:get/get.dart';

class RecordDatePicker extends StatefulWidget {
  @override
  _RecordDatePickerState createState() => _RecordDatePickerState();
}

class _RecordDatePickerState extends State<RecordDatePicker> {
  void pushToRedEnvelope(BuildContext context, int type) {
    push(RecordRedEnvelopePage(type));
  }

  final DateTime _earliestDate = DateTime.now().subtract(Duration(days: 30));
  DateTime _startDate;
  DateTime _endDate;

  @override
  void initState() {
    super.initState();
    _startDate = _earliestDate;
    _endDate = DateTime.now();
  }

  Future<void> _selectStartDate() async {
    final DateTime picked = await showDatePicker(
      locale: Get.locale,
      context: context,
      initialDate: _startDate,
      firstDate: _earliestDate,
      lastDate: _endDate,
    );
    if (picked != null && picked != _startDate)
      setState(() {
        _startDate = picked;
      });
  }

  Future<void> _selectEndDate() async {
    final DateTime picked = await showDatePicker(
      locale: Get.locale,
      context: context,
      initialDate: _endDate,
      firstDate: _startDate,
      lastDate: DateTime.now(),
    );
    if (picked != null && picked != _endDate)
      setState(() {
        _endDate = picked;
      });
  }

  @override
  Widget build(BuildContext context) {
    String startDateStr = "${_startDate.toLocal()}".split(' ')[0];
    String endDateStr = "${_endDate.toLocal()}".split(' ')[0];
    return BaseScaffold(
      appbarBackgroundIndex: 0,
      bodyBackgroundIndex: 0,
      appbarTitle: AppbarTitleText('dateSelection'.tr),
      child: Column(
        children: [
          SizedBox(height: 10),
          Text(
            'searchForRecordAndDetailsFor30Days'.tr,
            style: TextStyle(color: Colors.grey),
          ),
          SizedBox(height: 20),
          Text('startDate'.tr, style: TextStyle(fontSize: 15)),
          BaseButton(
            colors: currentTheme.buttonList,
            text: startDateStr,
            onPressed: () => _selectStartDate(),
          ),
          SizedBox(height: 20),
          Text('endDate'.tr, style: TextStyle(fontSize: 15)),
          BaseButton(
            colors: currentTheme.buttonList,
            text: endDateStr,
            onPressed: () => _selectEndDate(),
          ),
          SizedBox(height: 20),
          BaseButton(
            colors: currentTheme.buttonPrimary,
            text: 'search'.tr,
            onPressed: () => Navigator.pop(
              context,
              [startDateStr, endDateStr],
            ),
          ),
        ],
      ),
    );
  }
}
