import 'package:flutter/material.dart';

class UnlimitDirectPlayers {
  final String memberId;
  final String member;
  final String memberContribution;
  final String memberCount;
  final String memberType;
  final String teamContribution;
  final String teamCount;
  final String type;

  const UnlimitDirectPlayers({
    @required this.memberId,
    @required this.member,
    @required this.memberContribution,
    @required this.memberCount,
    @required this.memberType,
    @required this.teamContribution,
    @required this.teamCount,
    @required this.type,
  });
}
