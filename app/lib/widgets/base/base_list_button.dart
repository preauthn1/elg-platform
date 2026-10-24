import 'package:flutter/material.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';

import 'base_card.dart';

class BaseListButton extends StatelessWidget {
  final List<Color> colors;
  final Widget child;
  final Function onPressed;
  BaseListButton({
    this.colors,
    this.child,
    this.onPressed,
  });

  @override
  Widget build(BuildContext context) {
    return BaseCard(
      margin: EdgeInsets.symmetric(horizontal: 20, vertical: 10),
      height: 40,
      width: double.infinity,
      colors: colors,
      borderColors: currentTheme.borderListButton,
      child: RawMaterialButton(
        onPressed: onPressed,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(8),
        ),
        child: Center(child: child),
      ),
    );
  }
}
