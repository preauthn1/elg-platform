import 'package:flutter/material.dart';

class LobbyNewImage extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return Positioned(
      left: 0,
      top: 0,
      child: Image.asset(
        'assets/images/lobby/new.png',
        height: 22,
      ),
    );
  }
}
