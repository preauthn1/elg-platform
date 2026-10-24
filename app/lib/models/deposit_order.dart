import 'package:flutter/cupertino.dart';

class DepositOrder {
  final String type;
  final String url;
  final String html;
  final String code;
  DepositOrder({
    @required this.type,
    @required this.url,
    @required this.html,
    @required this.code,
  });
}
