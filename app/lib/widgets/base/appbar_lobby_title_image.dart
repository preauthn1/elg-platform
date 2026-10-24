import 'package:flutter/material.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';

class AppbarLobbyTitleImage extends StatelessWidget {
  final String src;
  AppbarLobbyTitleImage(this.src);
  @override
  Widget build(BuildContext context) {
    return Container(
      margin: EdgeInsets.only(top: currentTheme.lobbyImageMarginTop),
      child: Image.network(
        src,
        height: 45,
        width: 170,
      ),
    );
  }
}
