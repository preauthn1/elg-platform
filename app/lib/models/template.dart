class CustomizedSetting {
  String csIconVisible;
  String csIconLinkUrl;
  String csIconPicUrl;
  String textWalletPoint;
  String hasPgsLobbyLayout;
  String gamePageLoadingImageUrl;
  String isAutoAgentCode;
  String hasDepositRecordPage;
  String hasEvent;
  String textEventReward;
  String textUnlimited;
  String textJackpot;
  String withdrawalButtonVisible;
  String depositButtonVisible;
  String flowLimitVisible;
  String flowLimitDisplayVisible;
  String textWithdrawal;
  String instantRechargeButtonVisible;
  String isUnlimitVisible;

  CustomizedSetting({
    this.csIconLinkUrl,
    this.csIconVisible,
    this.csIconPicUrl,
    this.textWalletPoint,
    this.hasPgsLobbyLayout,
    this.gamePageLoadingImageUrl,
    this.isAutoAgentCode,
    this.hasDepositRecordPage,
    this.hasEvent,
    this.textEventReward,
    this.textUnlimited,
    this.textJackpot,
    this.withdrawalButtonVisible,
    this.depositButtonVisible,
    this.flowLimitVisible,
    this.flowLimitDisplayVisible,
    this.textWithdrawal,
    this.instantRechargeButtonVisible,
    this.isUnlimitVisible,
  });
}

class Template {
  String csApiKey;
  String csApiUser;
  CustomizedSetting customizedSetting;
  String domain;
  String emailValidate;
  String frontendRegister;
  String hasAgentLayout;
  String hasApp;
  String hasChat;
  String hasMobile;
  String hasOnlinePayment;
  String hasPc;
  String hasShowMedia;
  String icon;
  String id;
  String logoMobile;
  String logoPc;
  String mobileValidate;
  String name;
  String title;
  String transactionAll;

  Template({
    this.csApiKey,
    this.csApiUser,
    this.customizedSetting,
    this.domain,
    this.emailValidate,
    this.frontendRegister,
    this.hasAgentLayout,
    this.hasApp,
    this.hasChat,
    this.hasMobile,
    this.hasOnlinePayment,
    this.hasPc,
    this.hasShowMedia,
    this.icon,
    this.id,
    this.logoMobile,
    this.logoPc,
    this.mobileValidate,
    this.name,
    this.title,
    this.transactionAll,
  });
}
