import 'package:flutter/material.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/widgets/base/base_card.dart';

// ignore: must_be_immutable
class BaseButton extends StatelessWidget {
  Widget child;
  final double height;
  final double width;
  final List<Color> colors;
  final Function onPressed;
  final double borderWidth;
  final List<Color> borderColors;
  final double insideBorderWidth;
  final double borderRadius;

  BaseButton({
    String text,
    this.height = 40,
    this.width,
    this.colors,
    this.onPressed,
    this.borderWidth = 1,
    this.borderColors,
    this.insideBorderWidth = 1,
    this.borderRadius,
  }) {
    this.child = Text(
      text,
      style: TextStyle(color: currentTheme.theme.textTheme.button.color),
    );
  }

  BaseButton.widget({
    this.height = 40,
    this.width,
    this.colors,
    this.child,
    this.onPressed,
    this.borderWidth = 1,
    this.borderColors,
    this.insideBorderWidth,
    this.borderRadius,
  });

  @override
  Widget build(BuildContext context) {
    return BaseCard(
      height: height,
      width: width,
      colors: colors,
      borderColors:
          borderColors != null ? borderColors : currentTheme.borderButton,
      borderWidth: borderWidth,
      borderRadius:
          borderRadius != null ? borderRadius : currentTheme.buttonRadius,
      insideBorderWidth: insideBorderWidth != null
          ? insideBorderWidth
          : currentTheme.containerInsideBorderWidth,
      child: RawMaterialButton(
        onPressed: onPressed,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(currentTheme.buttonRadius),
        ),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 8),
          child: child,
        ),
      ),
    );
  }
}
