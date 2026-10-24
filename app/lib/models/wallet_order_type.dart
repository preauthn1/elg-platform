import 'package:flutter/cupertino.dart';

class WalletOrderType {
  final String orderTypeId;
  final List<OrderTypeInfo> orderTypeInfo;
  final String name;
  final String imagePath;
  final String minAmount;
  final String maxAmount;
  final String availableAmount;
  final String rate;
  final String cfId;
  final String cfName;
  final String url;

  const WalletOrderType({
    @required this.orderTypeId,
    @required this.orderTypeInfo,
    @required this.name,
    @required this.imagePath,
    @required this.minAmount,
    @required this.maxAmount,
    @required this.availableAmount,
    @required this.rate,
    @required this.cfId,
    @required this.cfName,
    @required this.url,
  });
}

class OrderTypeInfo {
  final String display;
  final String name;
  final List<OrderTypeOption> option;
  final String placeholder;
  final String require;
  final String type;

  OrderTypeInfo({
    @required this.display,
    @required this.name,
    @required this.option,
    @required this.placeholder,
    @required this.require,
    @required this.type,
  });
}

class OrderTypeOption {
  final String key;
  final String value;

  OrderTypeOption({
    @required this.key,
    @required this.value,
  });
}
