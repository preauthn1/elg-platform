import 'package:flutter/material.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';

class BaseCard extends StatelessWidget {
  final Color color;
  final Color borderColor;
  final List<Color> borderColors;
  final double height;
  final double width;
  final List<Color> colors;
  final double borderWidth;
  final double insideBorderWidth;
  final double borderRadius;
  final EdgeInsetsGeometry margin;
  final EdgeInsetsGeometry padding;
  final BoxConstraints constraints;
  final Widget child;

  BaseCard({
    this.color,
    this.colors,
    this.height,
    this.width,
    this.borderWidth = 1,
    this.insideBorderWidth,
    this.borderRadius = 10,
    this.borderColor,
    this.borderColors,
    this.margin,
    this.padding,
    this.constraints,
    this.child,
  });
  @override
  Widget build(BuildContext context) {
    return Container(
      //border
      margin: margin,
      padding: EdgeInsets.all(borderWidth),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(borderRadius),
        color: borderColor,
        gradient: borderColor != null
            ? null
            : LinearGradient(
                colors: borderColors != null
                    ? borderColors
                    : currentTheme.borderCard,
                begin: Alignment.topCenter,
                end: Alignment.bottomCenter,
                stops: currentTheme.colorStops,
              ),
      ),
      //black border
      child: Container(
        padding: EdgeInsets.all(
          insideBorderWidth != null
              ? insideBorderWidth
              : currentTheme.containerInsideBorderWidth,
        ),
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(borderRadius),
          color: currentTheme.containerInsideBorder,
        ),
        //content
        child: Container(
          padding: padding,
          height: height,
          constraints: constraints,
          width: width,
          decoration: BoxDecoration(
            color: color,
            gradient: colors == null
                ? null
                : LinearGradient(
                    colors: colors,
                    begin: Alignment.topCenter,
                    end: Alignment.bottomCenter,
                    stops: currentTheme.colorStops,
                  ),
            borderRadius: BorderRadius.circular(borderRadius - 1),
          ),
          child: child,
        ),
      ),
    );
  }
}
