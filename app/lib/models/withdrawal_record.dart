import 'package:flutter/cupertino.dart';

class WithdrawalRecord {
  final String id;
  final String amount;
  final String createdAt;
  final String status;

  WithdrawalRecord({
    @required this.id,
    @required this.amount,
    @required this.createdAt,
    @required this.status,
  });
}
