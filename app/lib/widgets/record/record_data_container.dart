import 'package:flutter/material.dart';

class RecordDataContainer extends StatelessWidget {
  final String text;
  final TextStyle style;
  RecordDataContainer(this.text, {this.style});
  @override
  Widget build(BuildContext context) {
    return Container(
      constraints: BoxConstraints(minHeight: 50),
      padding: EdgeInsets.all(8),
      child: Center(
        child: Text(
          text,
          textAlign: TextAlign.center,
          style: style,
        ),
      ),
    );
  }
}
