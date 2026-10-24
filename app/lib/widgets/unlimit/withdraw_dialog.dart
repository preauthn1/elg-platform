import 'package:flutter/material.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:futter_web_frontend/widgets/base/base_button.dart';
import 'package:futter_web_frontend/widgets/base/base_card.dart';
import 'package:futter_web_frontend/widgets/base/base_text_field.dart';
import 'package:get/get.dart';

class WithdrawDialog extends StatelessWidget {
  WithdrawDialog({@required this.withdrawnPoints, @required this.submit});
  final String withdrawnPoints;
  final Function(double amount) submit;

  final TextEditingController _controller = TextEditingController();

  Widget _buildTitle() {
    return Padding(
      padding: EdgeInsets.only(top: 8),
      child: Text(
        'Agent_Title_ApplyWithdrawl'.tr,
      ),
    );
  }

  Widget _buildContent() {
    return Container(
      color: currentTheme.theme.brightness == Brightness.dark
          ? Color(0xFF151622)
          : Color(0xfff4f5f9),
      margin: EdgeInsets.all(10),
      padding: EdgeInsets.symmetric(vertical: 20, horizontal: 8),
      child: Column(
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
                  withdrawnPoints,
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
    );
  }

  Widget _buildButton(BuildContext context) {
    return Padding(
      padding: EdgeInsets.symmetric(vertical: 10),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          BaseButton(
            colors: currentTheme.buttonPrimary,
            text: 'submit'.tr,
            onPressed: () {
              if (double.tryParse(_controller.text) == null) {
                showMessageDialog(message: 'Validate_NumericOnly'.tr);
                return;
              }
              submit(double.parse(_controller.text));
            },
          ),
          SizedBox(width: 20),
          BaseButton(
            colors: currentTheme.buttonDanger,
            text: 'cancel1'.tr,
            onPressed: () => Navigator.of(context).pop(),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.all(
          Radius.circular(16.0),
        ),
      ),
      contentPadding: EdgeInsets.all(0),
      content: BaseCard(
        width: 300,
        color: currentTheme.container,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            _buildTitle(),
            Container(
              margin: EdgeInsets.all(10),
              child: Column(
                children: [
                  _buildContent(),
                  _buildButton(context),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
