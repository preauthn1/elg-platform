import 'package:flutter/material.dart';

class BaseCircleAvator extends StatelessWidget {
  final double size;
  final Color color;
  final List<Color> colors;
  final Color borderColor;
  final Widget child;
  BaseCircleAvator({
    this.size,
    this.color,
    this.colors,
    this.borderColor,
    this.child,
  });
  @override
  Widget build(BuildContext context) {
    return Container(
      height: size,
      width: size,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: color,
        border: borderColor != null
            ? Border.all(color: borderColor, width: 1)
            : null,
        gradient: color == null
            ? LinearGradient(
                colors: colors,
                begin: Alignment.topCenter,
                end: Alignment.bottomCenter,
              )
            : null,
      ),
      child: child,
    );
  }
}
