import 'package:flutter/material.dart';

class UnlimitHistory {
  final String date;
  final String no;
  final String amount;
  final String withdrawType;
  final String withdrawAccount;
  final String type;

  const UnlimitHistory({
    @required this.date,
    @required this.no,
    @required this.amount,
    @required this.withdrawType,
    @required this.withdrawAccount,
    @required this.type,
  });
}
