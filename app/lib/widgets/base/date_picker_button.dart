import 'package:flutter/material.dart';
import 'package:futter_web_frontend/pages/record_date_picker.dart';

class DatePickerButton extends StatelessWidget {
  final Function(dynamic result) searchDate;
  DatePickerButton(this.searchDate);

  @override
  Widget build(BuildContext context) {
    return IconButton(
      icon: Icon(Icons.search),
      iconSize: 35,
      onPressed: () async {
        final result = await Navigator.push(
          context,
          MaterialPageRoute(
            builder: (context) => RecordDatePicker(),
          ),
        );
        searchDate(result);
      },
    );
  }
}
