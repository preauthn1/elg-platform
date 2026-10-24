import 'package:flutter/material.dart';

class Event {
  final String id;
  final String title;
  final String content;
  final String typeId;
  final String agentId;
  final List<EventPrize> prize;
  final String prizeId;
  final String prizeNumber;
  final String prizeIsRepeatedly;
  final String prizeExpireDate;
  final String expireDate;
  final String prizeDrawDate;
  final String count;
  final String type;
  final String showExchange;
  final String showExchangeText;
  final String remainingNumber;
  final String startTime;
  final String endTime;
  final List<EventDetail> detail;

  const Event({
    @required this.id,
    @required this.title,
    @required this.content,
    @required this.typeId,
    @required this.agentId,
    @required this.prize,
    @required this.prizeId,
    @required this.prizeNumber,
    @required this.prizeIsRepeatedly,
    @required this.prizeExpireDate,
    @required this.expireDate,
    @required this.prizeDrawDate,
    @required this.count,
    @required this.type,
    @required this.showExchange,
    @required this.showExchangeText,
    @required this.remainingNumber,
    @required this.startTime,
    @required this.endTime,
    @required this.detail,
  });
}

class EventDetail {
  final String id;
  final String actionId;
  final String orderTypeId;
  final String orderTypeName;
  final String gameTypeId;
  final String gameTypeName;
  final String vendorId;
  final String vendorName;
  final List<EventGame> games;
  final String gameId;
  final String rate;
  final String point;
  final String startTime;
  final String endTime;
  final String nowPoint;
  final String actionName;
  EventDetail({
    @required this.id,
    @required this.actionId,
    @required this.orderTypeId,
    @required this.orderTypeName,
    @required this.gameTypeId,
    @required this.gameTypeName,
    @required this.vendorId,
    @required this.vendorName,
    @required this.games,
    @required this.gameId,
    @required this.rate,
    @required this.point,
    @required this.startTime,
    @required this.endTime,
    @required this.nowPoint,
    @required this.actionName,
  });

  ///進度百分比
  int get percentageProgress {
    double percentage = (int.parse(nowPoint) / int.parse(point)) * 100;
    return percentage.round();
  }
}

class EventPrize {
  final String id;
  final String name;
  final String number;
  final String point;
  EventPrize({
    @required this.id,
    @required this.name,
    @required this.number,
    @required this.point,
  });
}

class EventGame {
  final String id;
  final String image;
  final String isFavourite;
  final String isHot;
  final String isNew;
  final String lotteryPool;
  final String name;
  final String typeId;
  final String typeName;
  final String vendorId;
  final String vendorName;

  EventGame({
    @required this.id,
    @required this.image,
    @required this.isFavourite,
    @required this.isHot,
    @required this.isNew,
    @required this.lotteryPool,
    @required this.name,
    @required this.typeId,
    @required this.typeName,
    @required this.vendorId,
    @required this.vendorName,
  });
}
