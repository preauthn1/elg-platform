import 'package:flutter/cupertino.dart';

class WithdrawalInfo {
  final String bankName;
  final String bankCode;
  final String accountName;
  final String accountNo;
  final String mobile;

  const WithdrawalInfo({
    @required this.bankName,
    @required this.bankCode,
    @required this.accountName,
    @required this.accountNo,
    @required this.mobile,
  });
}
