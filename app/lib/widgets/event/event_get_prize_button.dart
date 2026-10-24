import 'package:flutter/material.dart';
import 'package:futter_web_frontend/models/event.dart';
import 'package:futter_web_frontend/pages/event_lucky_draw_issues_page.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/http_service.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:futter_web_frontend/widgets/base/base_button.dart';
import 'package:get/get.dart';

class EventGetPrizeButton extends StatelessWidget {
  final Event event;
  final Function() onRedeem;
  EventGetPrizeButton(this.event, {this.onRedeem});

  Future<void> getPrize() async {
    var result = await fetchEventPrize(event.id);
    await showMessageDialog(
      title: 'itemname'.tr,
      message: result['prize']['name'],
    );
    if (onRedeem != null) onRedeem();
  }

  Widget _buildUnclickableButton(String text) {
    return BaseButton.widget(
      colors: currentTheme.buttonDisable,
      child: Text(
        text,
        textAlign: TextAlign.center,
        style: TextStyle(color: currentTheme.textMuted),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    if (event.type == '4' || event.type == '5') {
      //我的抽獎券
      return BaseButton(
        colors: currentTheme.buttonWarning,
        text: 'Events_LuckyDraw_Label_MyRaffleTicket'.tr,
        onPressed: () => push(EventLuckyDrawIssuesPage()),
      );
    } else {
      //立即兌換
      if (event.showExchange != '0') {
        return BaseButton(
          colors: currentTheme.buttonWarning,
          text: 'redeemNow'.tr,
          onPressed: () => getPrize(),
        );
      } else {
        if (event.prizeIsRepeatedly == '1') {
          //進度未達成
          return _buildUnclickableButton('unaccomplished'.tr);
        } else {
          //已兌換
          return _buildUnclickableButton('redeemed'.tr);
        }
      }
    }
  }
}
