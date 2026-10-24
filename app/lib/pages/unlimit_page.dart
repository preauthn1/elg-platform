import 'package:flutter/material.dart';
import 'package:futter_web_frontend/controller/template_controller.dart';
import 'package:futter_web_frontend/utils/http_service.dart';
import 'package:futter_web_frontend/widgets/base/appbar_title_text.dart';
import 'package:futter_web_frontend/widgets/base/base_button.dart';
import 'package:futter_web_frontend/widgets/base/base_card.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';
import 'package:futter_web_frontend/widgets/base/description_button.dart';
import 'package:qr_flutter/qr_flutter.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:futter_web_frontend/pages/unlimit_qrcode_page.dart';
import 'package:futter_web_frontend/pages/unlimit_reward_page.dart';
import 'package:futter_web_frontend/pages/unlimit_rules_page.dart';
import 'package:futter_web_frontend/pages/unlimit_withdraw_page.dart';

import 'package:futter_web_frontend/widgets/base/copy_button.dart';
import 'package:futter_web_frontend/models/unlimit.dart';
import 'package:get/get.dart';

class UnlimitPage extends StatefulWidget {
  @override
  _UnlimitPageState createState() => _UnlimitPageState();

  ///顯示代理廣告
  static void showAgentAd() {
    Get.dialog(
      Dialog(
        insetPadding: const EdgeInsets.all(0),
        child: Stack(
          children: [
            Image.asset(
              'path_app_agentAdPopPath'.tr,
              fit: BoxFit.fill,
            ),
            Positioned(
              top: 5,
              right: 5,
              child: IconButton(
                icon: Icon(
                  Icons.close,
                  color: Colors.white,
                ),
                onPressed: Get.back,
              ),
            ),
            Positioned(
              right: 0,
              bottom: 0,
              child: buildRulesButton(),
            ),
          ],
        ),
      ),
    );
  }

  ///規則按鈕
  static Widget buildRulesButton() {
    return DescriptionButton(
      onPressed: () => push(UnlimitRulesPage()),
    );
  }
}

class _UnlimitPageState extends State<UnlimitPage> {
  bool _isLoading = false;
  Unlimit _unlimit;

  final List<String> _titleKeys = [
    'Agent_AgentParentName',
    'Agent_AgentName',
    'Agent_TeamMemberCount',
    'Agent_DirectPlayerCount',
    'Agent_TodayNewPlayerCount',
    'Agent_TodayOnlinePlayerCount',
  ];

  @override
  void initState() {
    super.initState();
    fetchData();
  }

  void fetchData() async {
    setState(() {
      _isLoading = true;
    });
    _unlimit = await fetchUnlimit();
    setState(() {
      _isLoading = false;
    });
  }

  ///推廣資訊
  Widget _buildPromoteInfo({String title, String message}) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(title),
        SizedBox(height: 5),
        BaseCard(
          width: double.infinity,
          colors: currentTheme.buttonList,
          borderColors: currentTheme.borderListButton,
          height: 40,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 8.0),
            child: Row(
              children: [
                Expanded(
                  flex: 5,
                  child: Text(
                    message,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
                Expanded(
                  flex: 1,
                  child: CopyButton(message),
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }

  Widget _buildTable(List<String> data) {
    return Container(
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(10),
        border: Border.all(
          color: currentTheme.border,
          width: 2,
        ),
      ),
      child: Table(
        border: TableBorder.symmetric(
          inside: BorderSide(
            style: BorderStyle.solid,
            color: currentTheme.border,
            width: 1.5,
          ),
        ),
        defaultVerticalAlignment: TableCellVerticalAlignment.middle,
        children: [
          TableRow(
            decoration: BoxDecoration(
              borderRadius: BorderRadius.only(
                topLeft: Radius.circular(8),
                topRight: Radius.circular(8),
              ),
              gradient: LinearGradient(
                begin: Alignment.topCenter,
                end: Alignment.bottomCenter,
                stops: currentTheme.colorStops,
                colors: currentTheme.buttonList,
              ),
            ),
            children: [
              ..._titleKeys.map(
                (title) {
                  return Container(
                    constraints: BoxConstraints(minHeight: 40),
                    padding: EdgeInsets.all(5),
                    child: Center(
                      child: Text(
                        title.tr,
                        textAlign: TextAlign.center,
                      ),
                    ),
                  );
                },
              ),
            ],
          ),
          TableRow(
            decoration: BoxDecoration(
              borderRadius: BorderRadius.only(
                bottomRight: Radius.circular(8),
                bottomLeft: Radius.circular(8),
              ),
              color: currentTheme.container,
            ),
            children: [
              ...data.map((text) {
                return Container(
                  constraints: BoxConstraints(minHeight: 40),
                  padding: EdgeInsets.all(5),
                  child: Center(
                    child: Text(text),
                  ),
                );
              })
            ],
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    // context.locale = currentLocale;

    return BaseScaffold(
      appbarBackgroundIndex: 0,
      bodyBackgroundIndex: 0,
      appbarTitle: Obx(
        () => AppbarTitleText('Agent_AgentMenu'.tr.replaceNamedArgs(
          {'v_reward': TemplateController.to.textUnlimited},
        )),
      ),
      child: _isLoading
          ? Center(child: CircularProgressIndicator())
          : SingleChildScrollView(
              child: Padding(
                padding: const EdgeInsets.all(8.0),
                child: Column(
                  children: [
                    //表格
                    _buildTable([
                      _unlimit.topUsername,
                      _unlimit.username,
                      _unlimit.teamCount,
                      _unlimit.directPlayerCount,
                      _unlimit.addedToday,
                      _unlimit.activeToday,
                    ]),
                    SizedBox(height: 20),
                    Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        //QRCode
                        Expanded(
                          flex: 1,
                          child: Column(
                            children: [
                              FittedBox(child: Text('Agent_MyQRCode'.tr)),
                              SizedBox(height: 10),
                              GestureDetector(
                                onTap: () => push(
                                  UnlimitQRCodePage(
                                    code: _unlimit.code,
                                    url: _unlimit.link,
                                  ),
                                ),
                                child: Container(
                                  color: Colors.white,
                                  child: QrImage(
                                    data: _unlimit.link,
                                    size: 100.0,
                                  ),
                                ),
                              ),
                              SizedBox(height: 10),
                              FittedBox(child: Text('Agent_ClickZoomIn'.tr)),
                            ],
                          ),
                        ),
                        SizedBox(width: 10),

                        //推廣資訊
                        Expanded(
                          flex: 2,
                          child: Column(
                            children: [
                              _buildPromoteInfo(
                                title: 'Agent_PromoteLink'.tr,
                                message: _unlimit.link,
                              ),
                              SizedBox(height: 20),
                              _buildPromoteInfo(
                                title: 'Agent_PromoteCode'.tr,
                                message: _unlimit.code,
                              ),
                              SizedBox(height: 20),
                              Row(
                                mainAxisAlignment: MainAxisAlignment.end,
                                children: [
                                  //廣告
                                  if (!isDsg)
                                    Expanded(
                                      child: GestureDetector(
                                        child: Image.asset(
                                            'path_app_agent_summary'.tr),
                                        onTap: UnlimitPage.showAgentAd,
                                      ),
                                    ),
                                  SizedBox(width: 10),
                                  //說明
                                  UnlimitPage.buildRulesButton(),
                                ],
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                    SizedBox(height: 20),

                    //我的推廣
                    BaseButton(
                      width: double.infinity,
                      colors: currentTheme.buttonWarning,
                      text: 'Agent_Label_MyPromote'.tr,
                      onPressed: () => push(UnlimitRewardPage()),
                    ),
                    SizedBox(height: 10),
                    //我的提現
                    BaseButton(
                      width: double.infinity,
                      colors: currentTheme.buttonPrimary,
                      text: 'Agent_Label_MyWithdrawal'.tr,
                      onPressed: () => push(UnlimitWithdrawPage()),
                    ),
                    SizedBox(height: 10),
                    //說明
                    if (!isDsg)
                      GestureDetector(
                        onTap: () => push(UnlimitRulesPage()),
                        child: Image.asset(
                            'path_app_agent_summary_bottom_banner'.tr),
                      ),
                  ],
                ),
              ),
            ),
    );
  }
}
