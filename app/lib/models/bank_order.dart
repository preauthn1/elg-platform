import 'package:flutter/cupertino.dart';

class BankOrder {
  final String orderId;
  final String orderNo;
  final String bankName;
  final String bankCode;
  final String accountName;
  final String accountNo;
  final String amount;
  final String status;

  const BankOrder({
   @required this.orderId,
   @required this.orderNo,
   @required this.bankName,
   @required this.bankCode,
   @required this.accountName,
   @required this.accountNo,
   @required this.amount,
   @required this.status,
  });
}
