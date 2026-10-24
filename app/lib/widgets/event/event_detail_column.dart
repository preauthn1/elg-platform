import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:futter_web_frontend/controller/template_controller.dart';
import 'package:futter_web_frontend/models/event.dart';
import 'package:futter_web_frontend/models/vendor.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/http_service.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:get/get.dart';

// ignore: must_be_immutable
class EventDetailColumn extends StatelessWidget {
  final Event event;
  final EventDetail detail;
  EventDetailColumn(this.event, this.detail);

  void _toVendorGame() async {
    showLoadingDialog();
    Vendor vendor;
    try {
      vendor = await fetchVendor(detail.vendorId);
      pop();
      pushToVendorGamePage(vendor);
    } catch (error) {
      pop();
    }
  }

//進度條
  Widget _buildProgress() {
    int progress = detail.percentageProgress;
    return Stack(
      alignment: Alignment.center,
      children: [
        ClipRRect(
          borderRadius: BorderRadius.all(Radius.circular(15)),
          child: Container(
            decoration: BoxDecoration(
              gradient: LinearGradient(
                colors: currentTheme.eventProgressBg,
                begin: Alignment.topCenter,
                end: Alignment.bottomCenter,
              ),
            ),
            height: 18,
            child: Row(
              children: [
                Expanded(
                  flex: progress,
                  child: Container(
                    decoration: BoxDecoration(
                      gradient: LinearGradient(
                        colors: currentTheme.eventProgress,
                        begin: Alignment.topCenter,
                        end: Alignment.bottomCenter,
                      ),
                    ),
                  ),
                ),
                Expanded(
                  flex: 100 - progress,
                  child: Container(),
                ),
              ],
            ),
          ),
        ),
        Text(
          progress >= 100 ? 'achieved'.tr : '$progress%',
          style: TextStyle(
            color: progress > 50 ? currentTheme.textBody2Color : Colors.black,
          ),
        ),
      ],
    );
  }

  //指定遊戲商
  Widget _buildVendor(BuildContext context) {
    return RichText(
      text: TextSpan(
        children: [
          TextSpan(
            text: '${'designatedGamer'.tr}: ',
            style: TextStyle(color: currentTheme.textBody2Color),
          ),
          TextSpan(
            text: detail.vendorName,
            style: TextStyle(color: currentTheme.textDanger),
          ),
          WidgetSpan(child: SizedBox(width: 10)),
          TextSpan(
            text: 'viewGames'.tr,
            style: TextStyle(color: currentTheme.textLink),
            recognizer: TapGestureRecognizer()..onTap = _toVendorGame,
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SizedBox(height: 10),
        //進度條
        _buildProgress(),
        SizedBox(height: 5),
        //指定遊戲商
        if (detail.vendorName != 'null' &&
            (event.type == '1' || event.type == '2' || event.type == '3'))
          _buildVendor(context),
        if (detail.gameTypeName != 'null')
          //遊戲類型
          RichText(
            text: TextSpan(
              children: [
                TextSpan(
                  text: '${'Common_GameType_Label'.tr} : ',
                  style: TextStyle(color: currentTheme.textBody2Color),
                ),
                //指定動作
                TextSpan(
                  text: detail.gameTypeName,
                  style: TextStyle(color: currentTheme.textDanger),
                ),
              ],
            ),
          ),
        //目前進度
        RichText(
          text: TextSpan(
            children: [
              TextSpan(
                text:
                    '${'currentProgress'.tr} : ${detail.nowPoint} ${TemplateController.to.textWalletPoint}/ ${detail.point} ${TemplateController.to.textWalletPoint}',
                style: TextStyle(color: currentTheme.textBody2Color),
              ),

              //指定動作
              if (event.type == '1' || event.type == '2' || event.type == '3')
                TextSpan(
                  text: '(${detail.actionName})',
                  style: TextStyle(color: currentTheme.textDanger),
                ),
            ],
          ),
        ),
        //達成期限
        Text(
            '${'timeLimit'.tr} : ${dateFormatter(detail.startTime)} ~ ${dateFormatter(detail.endTime)}'),
      ],
    );
  }
}
