import 'package:flutter/cupertino.dart';
import 'package:flutter/material.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/http_service.dart';
import 'package:futter_web_frontend/widgets/base/appbar_title_text.dart';
import 'package:futter_web_frontend/models/unlimit_history.dart';
import 'package:futter_web_frontend/models/unlimit_withdraw_recode.dart';
import 'package:futter_web_frontend/pages/unlimit_reward_page.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:futter_web_frontend/widgets/base/base_button.dart';
import 'package:futter_web_frontend/widgets/base/base_card.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';
import 'package:futter_web_frontend/widgets/base/base_text_field.dart';

import 'package:futter_web_frontend/widgets/unlimit/unlimit_data_table.dart';
import 'package:futter_web_frontend/widgets/unlimit/unlimit_toggle.dart';
import 'package:get/get.dart';

class UnlimitWithdrawPage extends StatefulWidget {
  @override
  _UnlimitWithdrawPageState createState() => _UnlimitWithdrawPageState();
}

class _UnlimitWithdrawPageState extends State<UnlimitWithdrawPage> {
  bool _initializing = false;
  int _currentIndex = 0;
  final TextEditingController _controller = TextEditingController();
  final List<String> _withdrawRecodeHeader = [
    'account'.tr,
    'Agent_TotalBonus_Socre'.tr,
    'Agent_AlreadyApply_Socre'.tr,
    'Agent_AvailableApply_Socre'.tr,
  ];

  final List<String> _historyHeader = [
    'Common_Time'.tr,
    'Common_OrderNo'.tr,
    'amount'.tr,
    'Common_WithdrawalType'.tr,
    'Common_Status'.tr,
  ];

  UnlimitWithdrawRecode _withdrawRecode;
  List<UnlimitHistory> _history;

  @override
  void initState() {
    super.initState();
    fetchData();
  }

  void fetchData() async {
    setState(() {
      _initializing = true;
    });
    _withdrawRecode = await fetchWithdrawRecode();
    _history = await fetchHistory();
    setState(() {
      _initializing = false;
    });
  }

  void _submit() async {
    if (double.tryParse(_controller.text) == null) {
      await showMessageDialog(message: 'Validate_NumericOnly'.tr);
      _withdraw();
      return;
    }
    var amount = double.parse(_controller.text);
    try {
      showLoadingDialog();
      await withdraw(amount);
      await showMessageDialog(message: 'Msg_ApplySuccess'.tr);
      pop();
      fetchData();
    } catch (error) {
      pop();
    }
  }

  //申請提現
  void _withdraw() {
    showMessageDialog(
      title: 'Agent_Title_ApplyWithdrawl'.tr,
      content: Column(
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceAround,
            children: [
              Flexible(
                child: Text(
                  'Agent_RemainingApplyAmount_Socre'.tr,
                  textAlign: TextAlign.center,
                ),
              ),
              BaseCard(
                padding: EdgeInsets.all(8),
                height: 40,
                width: 110,
                colors: currentTheme.buttonList,
                child: Text(
                  num.tryParse(_withdrawRecode.withdrawnPoints)
                      .toStringAsFixed(2),
                  textAlign: TextAlign.right,
                ),
              )
            ],
          ),
          SizedBox(height: 20),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceAround,
            children: [
              Flexible(
                child: Text(
                  'Agent_FormApplyAmount_Socre'.tr,
                  textAlign: TextAlign.center,
                ),
              ),
              Container(
                width: 110,
                child: BaseTextField(
                  margin: null,
                  colors: currentTheme.buttonList,
                  textAlign: TextAlign.right,
                  controller: _controller,
                  keyboardType: TextInputType.datetime,
                ),
              )
            ],
          ),
        ],
      ),
      onPressed: _submit,
    );
  }

//提現總覽
  Widget _buildWithdrawRecode(BuildContext context) {
    return UnlimitDataTable(
      detailFlex: 1,
      headerFlex: 1,
      header: _withdrawRecodeHeader,
      detail: [
        [
          _withdrawRecode.username,
          num.tryParse(_withdrawRecode.totalRewardPoints).toStringAsFixed(2),
          num.tryParse(_withdrawRecode.withdrawablePoint).toStringAsFixed(2),
          num.tryParse(_withdrawRecode.withdrawnPoints).toStringAsFixed(2),
        ]
      ],
    );
  }

  //提現紀錄欄
  Widget _buildHistory(BuildContext context) {
    return UnlimitDataTable(
      header: _historyHeader,
      detail: [
        ..._history.map(
          (history) => [
            dateFormatter(history.date),
            history.no,
            history.amount,
            history.withdrawType,
            history.type,
          ],
        ),
      ],
    );
  }

  Widget _buildData() {
    switch (_currentIndex) {
      case 0:
        return _buildWithdrawRecode(context);
      case 1:
      default:
        return _buildHistory(context);
    }
  }

  @override
  Widget build(BuildContext context) {
    return BaseScaffold(
      appbarBackgroundIndex: 0,
      bodyBackgroundIndex: 0,
      appbarTitle: AppbarTitleText('Agent_Title_ApplyWithdrawl'.tr),
      child: _initializing
          ? Center(child: CircularProgressIndicator())
          : SingleChildScrollView(
              child: Column(
                children: [
                  //提現數據
                  UnlimitToggle(
                    childrenText: [
                      'Agent_Label_WithdrawalSummary'.tr,
                      'Agent_Label_WithdrawalHistory'.tr,
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

                  //申請提現
                  SizedBox(height: 20),
                  if (_currentIndex == 0)
                    BaseButton(
                      colors: currentTheme.buttonDanger,
                      text: 'Agent_Label_ApplyWithdrawal'.tr,
                      onPressed: _withdraw,
                    ),
                  SizedBox(height: 20),

                  //我的推廣
                  BaseButton(
                    colors: currentTheme.buttonWarning,
                    text: 'Agent_Label_MyPromote'.tr,
                    onPressed: () => push(UnlimitRewardPage()),
                  ),
                ],
              ),
            ),
    );
  }
}
