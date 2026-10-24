import 'dart:math';
import 'package:futter_web_frontend/models/template.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/http_service.dart';
import 'package:get/get.dart';

class TemplateController extends GetxController {
  final template = Template().obs;
  static TemplateController get to => Get.find();
  String get textUnlimited => template.value.customizedSetting.textUnlimited;
  String get textWalletPoint =>
      template.value.customizedSetting.textWalletPoint;
  Future<void> fetchAndSetTemplate() async {
    final random = Random().nextInt(100000000);
    final responseData = await post(path: 'api/template', parameters: {
      'domain': currentEnv.domain,
      't': random,
    });
    var data = responseData['data'];
    var settingData = data['customized_setting'];
    template.update(
      (value) {
        value.csApiKey = [REDACTED]'CS_API_KEY'].toString();
        value.csApiUser = data['CS_API_USER'].toString();
        value.customizedSetting = CustomizedSetting(
          csIconLinkUrl: settingData['cs_icon_link_url'].toString(),
          csIconVisible: settingData['cs_icon_visible'].toString(),
          csIconPicUrl: settingData['cs_icon_pic_url'].toString(),
          hasPgsLobbyLayout: settingData['has_pgs_lobby_layout'].toString(),
          textWalletPoint: settingData['text_wallet_point'].toString(),
          gamePageLoadingImageUrl:
              settingData['game_page_loading_image_url'].toString(),
          hasDepositRecordPage:
              settingData['has_deposit_record_page'].toString(),
          hasEvent: settingData['has_event'].toString(),
          isAutoAgentCode: settingData['is_auto_agent_code'].toString(),
          textEventReward: settingData['text_event_reward'].toString(),
          textJackpot: settingData['text_jackpot'].toString(),
          textUnlimited: settingData['text_unlimited'].toString(),
          withdrawalButtonVisible:
              settingData['withdrawal_button_visible'].toString(),
          depositButtonVisible:
              settingData['deposit_button_visible'].toString(),
          flowLimitVisible: settingData['flow_limit_visible'].toString(),
          flowLimitDisplayVisible:
              settingData['flow_limit_display_visible'].toString(),
          textWithdrawal: settingData['text_withdrawal'].toString(),
          instantRechargeButtonVisible:
              settingData['instant_recharge_button_visible'].toString(),
          isUnlimitVisible: settingData['is_unlimit_visible'].toString(),
        );
        value.domain = data['domain'].toString();
        value.emailValidate = data['email_validate'].toString();
        value.frontendRegister = data['frontend_register'].toString();
        value.hasAgentLayout = data['has_agent_layout'].toString();
        value.hasApp = data['has_app'].toString();
        value.hasChat = data['has_chat'].toString();
        value.hasMobile = data['has_mobile'].toString();
        value.hasOnlinePayment = data['has_online_payment'].toString();
        value.hasPc = data['has_pc'].toString();
        value.hasShowMedia = data['has_show_media'].toString();
        value.icon = data['icon'].toString();
        value.id = data['id'].toString();
        value.logoMobile = data['logo_mobile'].toString();
        value.logoPc = data['logo_pc'].toString();
        value.mobileValidate = data['mobile_validate'].toString();
        value.name = data['name'].toString();
        value.title = data['title'].toString();
        value.transactionAll = data['transaction_all'].toString();
      },
    );
    refreshTemplate();
  }
}
