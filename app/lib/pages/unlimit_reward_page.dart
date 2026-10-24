import 'package:flutter/cupertino.dart';
import 'package:flutter/material.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/http_service.dart';
import 'package:futter_web_frontend/widgets/base/appbar_title_text.dart';
import 'package:futter_web_frontend/widgets/base/base_button.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';
import 'package:futter_web_frontend/widgets/unlimit/unlimit_data_table.dart';
import 'package:intl/intl.dart';
import 'package:futter_web_frontend/models/unlimit_direct_players.dart';
import 'package:futter_web_frontend/models/unlimit_reward.dart';
import 'package:futter_web_frontend/pages/unlimit_withdraw_page.dart';
import 'package:futter_web_frontend/utils/utils.dart';

import 'package:futter_web_frontend/widgets/unlimit/unlimit_toggle.dart';
import 'package:get/get.dart';

class UnlimitRewardPage extends StatefulWidget {
  @override
  _UnlimitRewardPageState createState() => _UnlimitRewardPageState();
}

class _UnlimitRewardPageState extends State<UnlimitRewardPage> {
  int _currentIndex = 0;
  bool _initializing = false;
  List<UnlimitDirectPlayers> _directPlayers = [];
  List<UnlimitReward> _reward = [];
  @override
  void initState() {
    super.initState();
    fetchData();
  }

  void fetchData() async {
    setState(() {
      _initializing = true;
    });
    _directPlayers = await fetchDirectPlayers();
    _reward = await fetchReward();
    setState(() {
      _initializing = false;
    });
  }

  final List<String> _directPlayersHeader = [
    'Agent_AgentName'.tr,
    'Agent_TodayTeam_TotalScore'.tr,
    'Agent_TodayPlayer_TotalScore'.tr,
    'Agent_TeamMemberCount'.tr,
    'Agent_DirectPlayerCount'.tr,
    'type'.tr,
  ];

  final List<String> _rewardHeader = [
    'Common_SendTime'.tr,
    'Agent_Daily_TotalWinLoss'.tr,
    'Agent_Daily_TeamWinLoss'.tr,
    'Agent_Daily_DirectWinLoss'.tr,
    'Common_Bonus'.tr,
    'Common_Status'.tr,
  ];

  //我的玩家
  Widget _buildDirectPlayers(BuildContext context) {
    return UnlimitDataTable(
      header: _directPlayersHeader,
      detail: _directPlayers
          .map(
            (directPlayer) => [
              directPlayer.member,
              directPlayer.teamContribution,
              directPlayer.memberContribution,
              directPlayer.teamCount,
              directPlayer.memberCount,
              directPlayer.type,
            ],
          )
          .toList(),
    );
  }

  //我的業績
  Widget _buildReward(BuildContext context) {
    return Column(
      children: [
        UnlimitDataTable(
          header: _rewardHeader,
          detail: [
            ..._reward
                .map(
                  (reward) => [
                    dateFormatter(reward.date),
                    numberFormatter(reward.totalContribution),
                    numberFormatter(reward.teamContribution),
                    numberFormatter(reward.memberContribution),
                    numberFormatter(reward.reward),
                    reward.type,
                  ],
                )
                .toList(),
          ],
        ),
      ],
    );
  }

  Widget _buildData() {
    switch (_currentIndex) {
      case 0:
        return _buildDirectPlayers(context);
      case 1:
      default:
        return _buildReward(context);
    }
  }

  @override
  Widget build(BuildContext context) {
    return BaseScaffold(
      appbarBackgroundIndex: 0,
      bodyBackgroundIndex: 0,
      appbarTitle: AppbarTitleText('Agent_Label_MyPromote'.tr),
      child: _initializing
          ? Center(
              child: CircularProgressIndicator(),
            )
          : SingleChildScrollView(
              child: Column(
                children: [
                  UnlimitToggle(
                    childrenText: [
                      'Agent_Label_MyPlayers'.tr,
                      'Agent_Label_MyWinLoss'.tr,
                    ],
                    onPressed: (index) {
                      if (_currentIndex != index) {
                        setState(() {
                          _currentIndex = index;
                        });
                      }
                    },
                  ),
                  _buildData(),
                  SizedBox(height: 20),
                  Text(
                      '*${'Agent_Page_LastUpdateTime'.tr} : ${DateFormat('yyyy-MM-dd hh:mm').format(DateTime.now())}'),
                  SizedBox(height: 20),
                  BaseButton(
                    colors: currentTheme.buttonPrimary,
                    text: 'Agent_Label_MyWithdrawal'.tr,
                    onPressed: () => push(UnlimitWithdrawPage()),
                  ),
                ],
              ),
            ),
    );
  }
}
