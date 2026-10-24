import 'package:flutter/material.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/utils.dart';

class VendorBalanceCard extends StatelessWidget {
  final String balance;
  VendorBalanceCard(this.balance);
  @override
  Widget build(BuildContext context) {
    return Container(
      height: 5,
      margin: currentTheme.lobbyImageMarginTop == 0
          ? EdgeInsets.symmetric(vertical: 15)
          : EdgeInsets.only(top: currentTheme.lobbyImageMarginTop, right: 3),
      padding: EdgeInsets.symmetric(horizontal: 10),
      decoration: currentTheme.appbarBalanceDecoration,
      child: Row(
        children: [
          Container(
            height: 17,
            child: Image.asset(
              currentTheme.getTemplateImage('balance.png'),
            ),
          ),
          SizedBox(width: 5),
          Container(
            child: FittedBox(
              fit: BoxFit.scaleDown,
              child: Text(
                balance != null ? numberFormatter(balance) : '0.00',
                style: TextStyle(
                  color: currentTheme.textAppbarBalance,
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
