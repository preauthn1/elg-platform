import 'package:flutter/material.dart';
import 'package:futter_web_frontend/widgets/base/base_circle_button.dart';

class LobbySettingIconButton extends StatelessWidget {
  final Function() onPressed;
  const LobbySettingIconButton({this.onPressed});
  @override
  Widget build(BuildContext context) {
    return BaseCircleButton(
      child: Icon(Icons.settings, size: 30),
      size: 40,
      onPressed: onPressed,
    );
  }
}
