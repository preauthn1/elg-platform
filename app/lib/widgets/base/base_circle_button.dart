import 'package:flutter/material.dart';

class BaseCircleButton extends StatelessWidget {
  final double size;
  final Widget child;
  final Function onPressed;
  BaseCircleButton({this.size, this.child, this.onPressed});

  @override
  Widget build(BuildContext context) {
    return Container(
      height: size,
      width: size,
      child: RawMaterialButton(
        shape: CircleBorder(),
        padding: EdgeInsets.all(3),
        child: child,
        onPressed: onPressed,
      ),
    );
  }
}
