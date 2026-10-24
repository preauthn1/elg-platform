import 'package:flutter/material.dart';

class LobbyHotImage extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return Positioned(
      right: 0,
      top: 0,
      child: Image.asset(
        'assets/images/lobby/hot.png',
        height: 22,
      ),
    );
  }
}
