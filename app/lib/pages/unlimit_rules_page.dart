import 'package:flutter/material.dart';
import 'package:futter_web_frontend/controller/template_controller.dart';
import 'package:futter_web_frontend/models/unlimit_vendor_rate.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/http_service.dart';
import 'package:futter_web_frontend/models/unlimit_withdraw_list.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:futter_web_frontend/widgets/base/appbar_title_text.dart';
import 'package:futter_web_frontend/widgets/base/base_card.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';

import 'package:get/get.dart';

class UnlimitRulesPage extends StatefulWidget {
  @override
  _UnlimitRulesPageState createState() => _UnlimitRulesPageState();
}

class _UnlimitRulesPageState extends State<UnlimitRulesPage> {
  bool _isLoading = false;

  List<UnlimitList> _unlimitList = [];
  List<UnlimitVendorRate> _unlimitVendorRate = [];

  final List<String> _teamSalesTitle = [
    'Agent_Table_Column_Level'.tr,
    'Agent_Table_Column_Min'.tr,
    'Agent_Table_Column_Max'.tr,
    'Agent_Table_Column_Rabate'.tr,
    'Agent_Table_Column_Memo'.tr,
  ];

  final List<String> _ratioTitle = [
    'Common_GameType_Label'.tr,
    'Agent_Rules_ConvertRate'.tr,
    'Agent_Table_Column_Memo'.tr,
  ];

  TableBorder get _tableBorder {
    return TableBorder(
      horizontalInside: BorderSide(
        color: Color(0xFF66502D),
      ),
    );
  }

  @override
  void initState() {
    super.initState();
    fetchData();
  }

  void fetchData() async {
    setState(() {
      _isLoading = true;
    });
    _unlimitList = await fetchUnlimitList();
    _unlimitVendorRate = await fetchUnlimitVendorRate();
    setState(() {
      _isLoading = false;
    });
  }

  Widget _buildTableTitle(String text) {
    return Container(
      constraints: BoxConstraints(minHeight: 35),
      child: Center(
        child: Text(
          text,
          textAlign: TextAlign.center,
          style: TextStyle(
            color: currentTheme.textHead,
          ),
        ),
      ),
    );
  }

  Widget _buildTableData(String text) {
    return Container(
      constraints: BoxConstraints(minHeight: 35),
      child: Center(
        child: Text(
          text,
          textAlign: TextAlign.center,
        ),
      ),
    );
  }

  Widget _buildQuestion(String text) {
    return Padding(
      padding: const EdgeInsets.only(top: 20),
      child: Text(text),
    );
  }

  Widget _buildAnswer(String text) {
    return Text(
      text,
      style: TextStyle(color: Colors.grey, fontSize: 13),
    );
  }

  @override
  Widget build(BuildContext context) {
    return BaseScaffold(
      appbarBackgroundIndex: 0,
      bodyBackgroundIndex: 0,
      appbarTitle: AppbarTitleText('Agent_Title_Rule'.tr),
      child: SingleChildScrollView(
        child: BaseCard(
          margin: EdgeInsets.symmetric(horizontal: 10, vertical: 5),
          padding: EdgeInsets.symmetric(horizontal: 10, vertical: 5),
          insideBorderWidth: 0,
          colors: currentTheme.buttonList,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'Agent_Rules_BigTitle'.tr.replaceNamedArgs(
                  {'v_reward': TemplateController.to.textUnlimited},
                ),
                style: TextStyle(
                  color: currentTheme.textHead,
                  fontSize: 17,
                ),
              ),
              SizedBox(height: 10),
              _buildQuestion(
                'Agent_Rules_Q110'.tr.replaceNamedArgs(
                    {'v_reward': TemplateController.to.textUnlimited}),
              ),
              _buildAnswer(
                'Agent_Rules_A110'.tr.replaceNamedArgs(
                  {'v_reward': TemplateController.to.textUnlimited},
                ),
              ),
              _buildQuestion(
                'Agent_Rules_Q120'.tr.replaceNamedArgs(
                  {'v_reward': TemplateController.to.textUnlimited},
                ),
              ),
              _buildAnswer('Agent_Rules_A120'.tr),
              _buildQuestion(
                'Agent_Rules_Q130'.tr.replaceNamedArgs(
                  {'v_reward': TemplateController.to.textUnlimited},
                ),
              ),
              _buildAnswer(
                'Agent_Rules_A130'.tr.replaceNamedArgs(
                  {'v_point': TemplateController.to.textWalletPoint},
                ),
              ),
              _buildQuestion('Agent_Rules_Q140'.tr),
              _buildAnswer('Agent_Rules_A140'.tr),
              _buildQuestion('Agent_Rules_Q150'.tr),
              _buildAnswer('Agent_Rules_A150'.tr),
              SizedBox(height: 10),
              Center(
                child: Image.asset(
                  'path_app_agentRuleTreePath'.tr,
                  fit: BoxFit.scaleDown,
                ),
              ),
              _buildQuestion('Agent_Rules_Q160'.tr),
              _buildAnswer('Agent_Rules_A160'.tr),
              _isLoading
                  ? Center(child: CircularProgressIndicator())
                  : Table(
                      defaultVerticalAlignment:
                          TableCellVerticalAlignment.middle,
                      columnWidths: {0: FractionColumnWidth(.1)},
                      border: _tableBorder,
                      children: [
                        TableRow(
                          children: [
                            ..._teamSalesTitle.map(
                              (title) => _buildTableTitle(title),
                            ),
                          ],
                        ),
                        ..._unlimitList.map(
                          (list) => TableRow(
                            children: [
                              //Level
                              _buildTableData(list.level),
                              //團隊業績下限
                              _buildTableData(
                                numberFormatter(
                                  list.minAmount,
                                  format: '#,##0',
                                ),
                              ),
                              //團隊業績上限
                              _buildTableData(
                                list.maxAmount == '0'
                                    ? 'Common_None'.tr
                                    : numberFormatter(list.maxAmount),
                              ),
                              //返傭比例
                              _buildTableData(
                                  '${'Agent_Table_Hint_MaxHint'.tr} ${list.rebateRate}%'),
                              //注釋
                              _buildTableData(
                                  '${'Agent_Table_Hint_Per10000'.tr} ${list.bounsAmount}'),
                            ],
                          ),
                        ),
                      ],
                    ),
              _buildQuestion('Agent_Rules_Q170'.tr),
              _buildAnswer('Agent_Rules_A170'.tr),
              SizedBox(height: 10),
              Center(
                child: Image.asset(
                  'path_app_agentRuleSamplePath'.tr,
                  fit: BoxFit.scaleDown,
                ),
              ),
              _buildQuestion('Agent_Rules_Q180'.tr.replaceNamedArgs(
                {'v_reward': TemplateController.to.textUnlimited},
              )),
              _buildAnswer('Agent_Rules_A180'.tr),
              _buildQuestion('Agent_Rules_Q190'.tr.replaceNamedArgs(
                {'v_reward': TemplateController.to.textUnlimited},
              )),
              _buildAnswer('Agent_Rules_A190'.tr),
              _buildAnswer('''

${'Agent_Rules_A191'.tr}
1. ${'Agent_Rules_A291'.tr}
2. ${'Agent_Rules_A292'.tr}
3. ${'Agent_Rules_A293'.tr}
4. ${'Agent_Rules_A294'.tr}
5. ${'Agent_Rules_A295'.tr}
6. ${'Agent_Rules_A296'.tr}
'''),
              _buildQuestion('Agent_Rules_Q210'.tr),
              _buildAnswer('Agent_Rules_A210'.tr),
              _buildQuestion('Agent_Rules_Q200'.tr),
              _buildAnswer('Agent_Rules_A200'.tr),
              Table(
                defaultVerticalAlignment: TableCellVerticalAlignment.middle,
                columnWidths: {2: FractionColumnWidth(.5)},
                border: _tableBorder,
                children: [
                  TableRow(
                    children: [
                      ..._ratioTitle.map(
                        (title) => _buildTableTitle(title),
                      ),
                    ],
                  ),
                  /*
                  ..._ratioList.map(
                    (ratio) => TableRow(
                      children: [
                        _buildTableData(ratio['gameType']),
                        _buildTableData(ratio['ratio']),
                        _buildTableData(ratio['annotate']),
                      ],
                    ),
                  ),
                  */
                  ..._unlimitVendorRate.map(
                    (vendorRate) {
                      num rate = num.tryParse(vendorRate.unlimitRate);
                      return TableRow(
                        children: [
                          _buildTableData(vendorRate.name),
                          _buildTableData(vendorRate.unlimitRate + 'X'),
                          _buildTableData(rate > 0
                              ? 'Agent_Rules_Note_Sales'.tr.replaceNamedArgs({
                                  "rate": vendorRate.unlimitRate,
                                  "sales": (10000 * rate).toString()
                                })
                              : 'Agent_Rules_Note_No_Sales'.tr),
                        ],
                      );
                    },
                  ),
                ],
              ),
              _buildAnswer('Agent_Rules_PS'.tr),
              _buildAnswer(
                'Agent_Rules_PS2'.tr.replaceNamedArgs(
                    {'v_reward': TemplateController.to.textUnlimited}),
              ),
              _buildAnswer(
                'Agent_Rules_PS3'.tr.replaceNamedArgs(
                    {'v_reward': TemplateController.to.textUnlimited}),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
