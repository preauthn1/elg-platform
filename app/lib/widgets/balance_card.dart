import 'package:flutter/material.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'base/base_card.dart';

class BalanceCard extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return BaseCard(
      borderRadius: 15,
      height: 25,
      width: 180,
      color: Color(0xFF4A100D),
      child: Text(
        '', //   '${TemplateController.to.textWalletPoint} ${Provider.of<Member>(context).balance}',
        textAlign: TextAlign.center,
        style: TextStyle(
          fontSize: 13,
          color: currentTheme.textHead,
        ),
      ),
    );
  }
}
