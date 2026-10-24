import 'package:flutter/material.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';

class BaseDialog extends StatelessWidget {
  final Widget title;
  final Widget content;
  final List<Widget> actions;
  BaseDialog({this.title, @required this.content, @required this.actions});
  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.all(Radius.circular(16.0))),
      contentPadding: EdgeInsets.all(0),
      content: Container(
        width: 300,
        child: Column(
          mainAxisAlignment: MainAxisAlignment.start,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            //Title
            Container(
              padding: EdgeInsets.symmetric(vertical: 15),
              decoration: BoxDecoration(
                  color: currentTheme.icon,
                  borderRadius: BorderRadius.only(
                      topLeft: Radius.circular(16.0),
                      topRight: Radius.circular(16.0)),
                  gradient: LinearGradient(
                    colors: currentTheme.dialogTitleBg,
                    begin: Alignment.topCenter,
                    end: Alignment.bottomCenter,
                  )),
              child: Center(child: title),
            ),
            //Message
            Container(
              color: currentTheme.theme.brightness == Brightness.dark
                  ? Color(0xFF151622)
                  : Color(0xfff4f5f9),
              margin: EdgeInsets.all(5),
              padding: EdgeInsets.symmetric(vertical: 40, horizontal: 10),
              child: Center(child: content),
            ),
            //Button
            Container(
              padding: EdgeInsets.symmetric(vertical: 10),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: actions,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
