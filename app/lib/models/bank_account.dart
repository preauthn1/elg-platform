import 'package:flutter/cupertino.dart';

class Bank {
  final String orderTypeId;
  final String bankId;
  final String bankName;
  final String bankCode;
  final String accountName;
  final String accountNo;
  final String minAmount;
  final String maxAmount;

  Bank({
    @required this.orderTypeId,
    @required this.bankId,
    @required this.bankName,
    @required this.bankCode,
    @required this.accountName,
    @required this.accountNo,
    @required this.minAmount,
    @required this.maxAmount,
  });
}

class BankAccount {
  final String type;
  final List<Bank> allBank;
  const BankAccount({
    @required this.type,
    @required this.allBank,
  });
}
