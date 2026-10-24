import 'package:flutter/cupertino.dart';

class MyGame {
  final String id;
  final String vendorId;
  final String vendorName;
  final String typeId;
  final String name;
  final String lotteryPool;
  final String image;
  final String isNew;
  final String isHot;
  final String isFavourite;
  final String eventBet;
  final String eventWin;
  final String disable;
  final String redirectUrl;
  const MyGame({
    @required this.id,
    @required this.vendorId,
    @required this.vendorName,
    @required this.typeId,
    @required this.name,
    @required this.lotteryPool,
    @required this.image,
    @required this.isNew,
    @required this.isHot,
    @required this.isFavourite,
    @required this.eventBet,
    @required this.eventWin,
    @required this.disable,
    @required this.redirectUrl
  });
}
