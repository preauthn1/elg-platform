import 'package:flutter/material.dart';

enum GameType {
  Slot,
  Reality,
  Poker,
  Fishing,
  Lottery,
  Sports,
  E_Sports,
  Arcade
}

class Filter {
  final String id;
  final String nameKey;
  final String imagePath;
  final String highlightPath;
  const Filter({
    @required this.id,
    @required this.nameKey,
    @required this.imagePath,
    @required this.highlightPath,
  });
}
