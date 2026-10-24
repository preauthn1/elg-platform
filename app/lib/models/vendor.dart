import 'package:flutter/material.dart';

class Vendor {
  final String id;
  final String name;
  final String rate;
  final String image;
  final String appIcon;
  final String isNew;
  final String isHot;
  final String needLogin;
  final String onlyOneGame;
  final String isSlow;
  final String status;
  final String maintenanceEndTime;
  const Vendor({
    @required this.id,
    @required this.name,
    @required this.rate,
    @required this.image,
    @required this.appIcon,
    @required this.isNew,
    @required this.isHot,
    @required this.needLogin,
    @required this.onlyOneGame,
    @required this.isSlow,
    @required this.status,
    @required this.maintenanceEndTime,
  });
}
