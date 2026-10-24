import 'package:flutter/material.dart';
import 'package:futter_web_frontend/controller/member_controller.dart';
import 'package:futter_web_frontend/controller/payment_type_controller.dart';
import 'package:futter_web_frontend/controller/template_controller.dart';
import 'package:futter_web_frontend/pages/record_menu_page.dart';
import 'package:futter_web_frontend/pages/setting_page.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/http_service.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:futter_web_frontend/widgets/base/appbar_title_text.dart';
import 'package:futter_web_frontend/widgets/base/base_card.dart';
import 'package:futter_web_frontend/widgets/base/base_circle_button.dart';
import 'package:futter_web_frontend/widgets/base/base_list_icon_button.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';

import 'package:futter_web_frontend/widgets/lobby/lobby_setting_icon_button.dart';
import 'package:futter_web_frontend/widgets/wallet/flow_limit_text.dart';
import 'package:futter_web_frontend/widgets/wallet/payment_type_grid.dart';
import 'package:get/get.dart';

class WalletPage extends StatefulWidget {
  @override
  _WalletPageState createState() => _WalletPageState();
}

class _WalletPageState extends State<WalletPage> {
  bool _isRefetchMember = false;
  MemberController _memberController = MemberController.to;
  @override
  void initState() {
    super.initState();
    init();
    PaymentTypeController.to.fetchAndSetPaymentType();
  }

  Future<void> init() async {
    setState(() {
      _isRefetchMember = true;
    });
    String balance = await fetchTransferFromLastLog();
    MemberController.to.updateBalance(balance);
    setState(() {
      _isRefetchMember = false;
    });
  }

  void _checkingTheBalance() {
    showMessageDialog(
      message: 'checkingTheBalance'.tr,
      onPressed: () async {
        setState(() {
          _isRefetchMember = true;
        });
        _refreshWalletBalance();
      },
    );
  }

  Future<void> _refreshWalletBalance() async {
    setState(() {
      _isRefetchMember = true;
    });
    await fetchSingleWalletBalance('');
    setState(() {
      _isRefetchMember = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    return BaseScaffold(
      appbarBackgroundIndex: 1,
      bodyBackgroundIndex: 1,
      appbarTitle: AppbarTitleText('wallet'.tr),
      actions: [
        //Setting
        LobbySettingIconButton(
          onPressed: () => push(SettingPage()),
        ),
      ],
      child: SingleChildScrollView(
        child: Column(
          children: [
            Stack(
              children: [
                BaseCard(
                  padding: EdgeInsets.symmetric(vertical: 20),
                  width: double.infinity,
                  color: currentTheme.container,
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Obx(
                        () => Text(
                          TemplateController.to.textWalletPoint,
                          style: TextStyle(fontSize: 16),
                        ),
                      ),
                      SizedBox(height: 15),
                      //balance
                      SizedBox(
                        child: _isRefetchMember
                            ? Center(child: CircularProgressIndicator())
                            : Obx(
                                () => Column(
                                  crossAxisAlignment: CrossAxisAlignment.center,
                                  children: [
                                    Text(
                                      _memberController.member.value.balance !=
                                              null
                                          ? numberFormatter(_memberController
                                              .member.value.balance)
                                          : '0.00',
                                      style: TextStyle(
                                        color: currentTheme.textHead,
                                        fontSize: 40,
                                      ),
                                    ),
                                    FlowLimitText(),
                                  ],
                                ),
                              ),
                      ),
                      //紀錄列表
                      BaseListIconButton(
                        text: 'recordList'.tr,
                        icon: ListIcon.icon(Icons.cached),
                        onPressed: () => push(RecordMenuPage()),
                      ),
                    ],
                  ),
                ),
                //Refresh
                Positioned(
                  top: 20,
                  right: 30,
                  height: 35,
                  width: 35,
                  child: BaseCircleButton(
                    child: Image.asset(
                      currentTheme.getTemplateImage('refresh.png'),
                    ),
                    onPressed: _checkingTheBalance,
                  ),
                )
              ],
            ),
            SizedBox(height: 20),
            PaymentTypeGrid(),
            SizedBox(height: 20),
          ],
        ),
      ),
    );
  }
}
