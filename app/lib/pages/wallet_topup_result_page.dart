import 'package:flutter/material.dart';
import 'package:flutter_svg/flutter_svg.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:futter_web_frontend/widgets/base/appbar_title_text.dart';
import 'package:futter_web_frontend/widgets/base/base_button.dart';
import 'package:futter_web_frontend/widgets/base/base_card.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';

import 'package:get/get.dart';

class WalletTopupResultPage extends StatelessWidget {
  final String amount;
  WalletTopupResultPage(this.amount);
  @override
  Widget build(BuildContext context) {
    return BaseScaffold(
      appbarBackgroundIndex: 1,
      bodyBackgroundIndex: 0,
      appbarTitle: AppbarTitleText('Top Up'.tr),
      leading: SizedBox(),
      child: SingleChildScrollView(
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 20.0),
          child: SingleChildScrollView(
            child: Column(
              children: [
                SizedBox(height: 64),
                SvgPicture.asset('assets/images/wallet/wallet_icon_24h.svg'),
                SizedBox(height: 32),
                Text('Wallet_Successfully'.tr),
                SizedBox(height: 32),
                BaseCard(
                  width: double.infinity,
                  color: currentTheme.container,
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      SizedBox(height: 31),
                      Text(
                        'Wallet_Topup'.tr,
                        style: TextStyle(
                          color: currentTheme.textBody2Color.withAlpha(153),
                        ),
                      ),
                      SizedBox(height: 12),
                      RichText(
                        textAlign: TextAlign.end,
                        text: TextSpan(
                          children: [
                            TextSpan(
                              text: 'amount'.tr,
                              style: TextStyle(
                                color: currentTheme.textBody2Color,
                              ),
                            ),
                            WidgetSpan(child: SizedBox(width: 5)),
                            TextSpan(
                              text: amount,
                              style: TextStyle(
                                color: currentTheme.textHead,
                                fontWeight: FontWeight.bold,
                                fontSize: 17,
                              ),
                            ),
                          ],
                        ),
                      ),
                      SizedBox(height: 35),
                    ],
                  ),
                ),
                SizedBox(height: 50),

                //提交
                BaseButton(
                  colors: currentTheme.buttonPrimary,
                  text: 'finish'.tr,
                  onPressed: () {
                    popToFirst();
                  },
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
