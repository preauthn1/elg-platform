import 'package:flutter/material.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';

class BaseScaffold extends StatelessWidget {
  final int appbarBackgroundIndex;
  final int bodyBackgroundIndex;
  final Widget appbarTitle;
  final List<Widget> actions;
  final Widget leading;
  final Widget child;

  BaseScaffold({
    this.appbarBackgroundIndex = 0,
    this.bodyBackgroundIndex = 0,
    this.appbarTitle,
    this.actions,
    this.leading,
    this.child,
  });

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: Scaffold(
        appBar: AppBar(
          iconTheme: IconThemeData(color: currentTheme.appbarIcon),
          actionsIconTheme: IconThemeData(color: currentTheme.appbarIcon),
          flexibleSpace: currentTheme.getAppbarBg(appbarBackgroundIndex),
          title: appbarTitle,
          centerTitle: true,
          actions: actions,
          leading: leading,
        ),
        body: Container(
          height: double.infinity,
          width: double.infinity,
          decoration: currentTheme.getBgDecoration(bodyBackgroundIndex),
          child: child,
        ),
      ),
    );
  }
}
