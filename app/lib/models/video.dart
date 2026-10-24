import 'package:flutter/material.dart';

class Video {
  final String androidDownload;
  final String androidOpen;
  final String appIcon;
  final String iosDownload;
  final String iosOpen;
  final String password;
  final String username;

  const Video({
    @required this.androidDownload,
    @required this.androidOpen,
    @required this.appIcon,
    @required this.iosDownload,
    @required this.iosOpen,
    @required this.password,
    @required this.username,
  });
}
