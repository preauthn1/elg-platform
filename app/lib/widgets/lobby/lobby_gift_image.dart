import 'package:flutter/material.dart';

class LobbyGiftImage extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return Positioned(
      left: 0,
      bottom: 0,
      child: Image.asset(
        'assets/images/lobby/gift.png',
        height: 22,
      ),
    );
  }
}
