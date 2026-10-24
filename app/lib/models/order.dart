import 'package:flutter/cupertino.dart';

class Order {
  final String amount;
  final String balance;
  final String createdAt;
  final String currencyId;
  final String id;
  final String memberId;
  final String orderType;
  final String point;
  final String status;
  final String statusName;
  final String updatedAt;

  Order({
    @required this.amount,
    @required this.balance,
    @required this.createdAt,
    @required this.currencyId,
    @required this.id,
    @required this.memberId,
    @required this.orderType,
    @required this.point,
    @required this.status,
    @required this.statusName,
    @required this.updatedAt,
  });
}
