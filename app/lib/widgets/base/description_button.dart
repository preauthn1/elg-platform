import 'package:flutter/material.dart';
import 'base_circle_avator.dart';

class DescriptionButton extends StatelessWidget {
  final double size;
  final Function() onPressed;

  DescriptionButton({this.size = 25, this.onPressed});
  @override
  Widget build(BuildContext context) {
    return Container(
      height: size,
      width: size,
      child: IconButton(
        padding: EdgeInsets.all(0),
        icon: BaseCircleAvator(
          size: size,
          colors: [
            Color(0xFFE0FCE2),
            Color(0xFF61D861),
            Color(0xFF2FC52F),
          ],
          child: Center(
            child: Text(
              '?',
              style: TextStyle(color: Colors.white),
            ),
          ),
        ),
        onPressed: onPressed,
      ),
    );
  }
}
