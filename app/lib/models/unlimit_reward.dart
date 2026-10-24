import 'package:flutter/material.dart';

class UnlimitReward {
  final String date;
  final String totalContribution;
  final String teamContribution;
  final String memberContribution;
  final String reward;
  final String type;

  const UnlimitReward({
    @required this.date,
    @required this.totalContribution,
    @required this.teamContribution,
    @required this.memberContribution,
    @required this.reward,
    @required this.type,
  });
}
