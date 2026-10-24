using Lck.Utility.Extensions.Helper;
using Microsoft.AspNetCore.Http;
using Microsoft.AspNetCore.Mvc;
using Microsoft.Extensions.Logging;
using target.Utility;
using target.Utility.Extensions;
using target.Utility.Helper.HttpContext;
using target.Utility.Utility.ThirdParty;
using System;
using System.Collections.Generic;
using System.Collections.Specialized;
using example.invalid;
using System.Linq;
using System.Threading;
using System.Threading.Tasks;
using TG_Hook_API.Helper;
using TG_Hook_API.Models;

namespace TG_Hook_API.Service
{

    public partial class VworldTgBotService
    {

        private TgBotGlobalTempVariable _global = new TgBotGlobalTempVariable();


        private VworldPlatformApiService VworldPlatformApiService = null;

        private BaseTelegramUtility BaseTelegramUtility { get; set; }




        public VworldTgBotService(string botID = "")
        {
            if (string.IsNullOrWhiteSpace(botID))
            {
                botID = "Casino365";
            }

            _global.BotID = botID;

            string tgBotToken = [REDACTED];
            BaseTelegramUtility = new BaseTelegramUtility(tgBotToken);

            //debug用 & 常用檔案
            ReadMe();
        }

        public VworldHomePageInfo GetHomePageInfo() {
            var homePageInfo = VworldPlatformApiService.HomePageInfo();

            //嘗試再重登一次
            if (homePageInfo.Code == 9998 || homePageInfo.Data == null)
            {
                Login();
                homePageInfo = VworldPlatformApiService.HomePageInfo();

                if (!homePageInfo.IsSuccessCode)
                {
                    SendErrorMessageAndDelay("@Error_Msg_OperateFail_PleaseRetry");
                }
            }
            return homePageInfo.Data;
        }
        
        public void CancelAction()
        {
             ClearLastTelegramMessageInlineButton();
        }

        public object GenerateTgMessages(TgWebhookParsingResult parseResult)
        {
            object defaultResultObject = new
            {
                code = 1000,
                message = "",
            };

            TgWebhookRequest model = parseResult.ParsingModel;

            _global.WeHookRquest = model;
            _global.TargetUsetName = model.message?.chat?.username;
            _global.InputText = _global.InputText ?? "";

            SetGlobalChatIdAndInstance(model.message?.chat.id);

            //判斷順序1. 觸發callback優先
            //判斷順序2. 若無callbakc(e.g. 打字回復) 則會判斷是否為waitingReply流程
            if (model.callback_query != null)
            {
                SetGlobalChatIdAndInstance(model.callback_query?.message?.chat?.id);
                _global.TargetUsetName = model.callback_query?.message?.chat?.username;

                //裡面包含解析參數並存入cache的行為
                TgCallbackParsing callbackParsing = ParsingCallbackActionAndSetCache(model.callback_query.data);

                _global.CallbackParsing = callbackParsing;

                GoStepByCallbackAction(callbackParsing.Action);
                return defaultResultObject;
            }

            //檢查是否為 1 on 1 的私人對話 (如果是把機器人拉到特定群組是不會回話的)
            bool isPrivateChat = model.message?.chat?.type == "private";

            if (!isPrivateChat)
                return defaultResultObject;

            if (_global.InputText == "/info" || _global.InputText == "/debug")
            {
                SendDebugInfoToChannel();
            }
            else if (_global.InputText == "/start" ||
                     _global.InputText.Contains("/start") ||
                     _global.InputText == "/menu")
            {
                SetAgenCodeByInviteLink();

                ShowMenuByAccountExistOrNot(true);
            }
            else if (_global.InputText == "/test001")
            {
                Test001();
            }
            else if (IsEquali18nText(_global.InputText, "@Label_Disagree"))
            {
                GoStep_HomePageWithoutLogin_AndResetHistory();
            }
            else if (IsMatchCallbackActionByText(_global.InputText).IsMatch)
            {
                var matchResult = IsMatchCallbackActionByText(_global.InputText);

                GoStepByCallbackAction(matchResult.MatchAction);
            }
            else if (IsWaitingReplyStep())
            {
                //檢查參數填寫、設定快取、前往下一步驟
                CheckInputInfoAndGoNextStep();
            }
            else
            {
                //其他。顯示主選單
                string setToLoginStatusCache = TgBotCache().LoginKeepStatus;

                bool isSetToLoginStatus = setToLoginStatusCache == "1";

                if (isSetToLoginStatus)
                {
                    ShowMenuByAccountExistOrNot();
                }
                else
                {
                    //未登入
                    GoStep_HomePageWithoutLogin_AndResetHistory();
                }
            }


            return defaultResultObject;
        }

        private void SetAgenCodeByInviteLink()
        {
            if (_global.InputText.Contains("/start"))
            {
                var startParameter = _global.InputText.Split(" ");

                if (startParameter.Count() >= 2 && startParameter.Count() < 8)
                {
                    string agentCode = startParameter[1].Trim();

                    if (agentCode.Length > 3)
                    {
                        TgBotCache().SetCache_SetAgentCodeByInviteLink(agentCode);
                    }
                }
            }
        }

        private void SendDebugInfoToChannel()
        {
            string setpInfo = $"{StepHistoryQueueHelper.GetLastStepsHistories(_global.TargetChatID)}";
            string msg = $"请将以下讯息复制给技术人员{Environment.NewLine}" +
                         $"{_global.TargetChatID} ｜ {_global.TargetUsetName}{Environment.NewLine}";

            try
            {
                var queryBalance = VworldPlatformApiService.GetBalance();
                msg += $"{queryBalance.Data.Debug_MemberID}" + Environment.NewLine;
            }
            catch
            {

            }

            msg += $"{GetCurrentLanguage()}" + Environment.NewLine +
                   $"{TgBotCache().Register_PhoneNumber}" + Environment.NewLine +
                   $"{setpInfo}" + Environment.NewLine;

            if (SettingHelper.IsDev)
            {
                string azureUrl = SettingHelper.DebugSiteUrl;

                msg += $"{azureUrl}/testCase/Apilogs?chatID=" + _global.TargetChatID + Environment.NewLine;
                msg += $"{azureUrl}/testCase/TGlogs?chatID=" + _global.TargetChatID + Environment.NewLine;
            }

            SendMessageAndDelay(msg, 0);
        }


        private void GoStepByCallbackAction(string actionName)
        {
            if (actionName == "test")
                return;
            MySlackUtility.SendMessageToChannelAsync( new SlackMessageParameter
            {
                Message = $"GoStepByCallbackAction {actionName}",
                Channel = SlackChannelEnum.n_tgbot_notify,
            });
            if (IsComeFromMenu(actionName))
            {
                //如果操作到某個步驟、中途又點了Menu。強制結束所有未完成步驟的cache
                StepHistoryQueueHelper.RemoveAllHistory(_global.TargetChatID);
            }

            if (string.IsNullOrWhiteSpace(_global.FirstTriggerActionName))
            {
                _global.FirstTriggerActionName = actionName;
            }

            switch (actionName)
            {
                case TgBotCallbackAction.HomePage:
                    GoStep_HomePage_LoginByApi_AndResetHistory();
                    break;

                case TgBotCallbackAction.HomePageWithoutLogin:
                    GoStep_HomePageWithoutLogin_AndResetHistory();
                    break;

                case TgBotCallbackAction.RegisterOrLogin:
                    GoStep_RegisterOrLogin();
                    break;

                case TgBotCallbackAction.Register_WatingReply_RequestPhoneNumber:
                    GoStep_Register_WatingReply_RequestPhoneNumber();
                    break;

                case TgBotCallbackAction.Register_WatingReply_AgentCode:
                    GoStep_Register_WatingReply_AgentCode();
                    break;

                case TgBotCallbackAction.Register_SubmitCreateAccount:
                    GoStep_Register_SubmitCreateAccount();
                    break;

                case TgBotCallbackAction.Register_SubmitCreateAccountByDefaultAgentCode:
                    GoStep_Register_SubmitCreateAccountByDefaultAgentCode();
                    break;

                case TgBotCallbackAction.Deposit_ShowAvailablePaymentTypes:
                    GoStep_Deposit_SelectPaymentType();
                    break;
                case TgBotCallbackAction.Withdrwal_Process:
                    GoStep_Withdrwal_Process();
                    break;

                //case TgBotCallbackAction.Deposit_WatingReply_PaymentCashAccount:
                //    GoStep_Deposit_WatingReply_PaymentCashAccount();
                //    break;

                case TgBotCallbackAction.Deposit_WatingReply_DepositAmount:
                    GoStep_Deposit_WatingReply_DepositAmount();
                    break;

                case TgBotCallbackAction.Deposit_SubmitOrderAndGetLinkButton:
                    GoStep_Deposit_SubmitOrderAndGetLinkButton();
                    break;

                case TgBotCallbackAction.Withdrwal_Extra_WaitingReply_SetPaymentPassword:
                    [REDACTED];
                    break;

                case TgBotCallbackAction.Withdrwal_Extra_SubmitUpdatePaymentPassword:
                    [REDACTED];
                    break;

                case TgBotCallbackAction.Withdrwal_ShowAvailableWithdrawalType:
                    GoStep_Withdrwal_ShowAvailableWithdrawalType();
                    break;

                case TgBotCallbackAction.Withdrwal_WatingReply_PaymentPassword:
                    [REDACTED];
                    break;

                case TgBotCallbackAction.Withdrwal_WatingReply_PaymentCashAccount:
                    GoStep_Withdrwal_WatingReply_PaymentCashAccount();
                    break;

                case TgBotCallbackAction.Withdrwal_WatingReply_WithdrawalAmount:
                    GoStep_Withdrwal_WatingReply_WithdrawalAmount();
                    break;

                case TgBotCallbackAction.Withdrwal_SendOrder_SuccessMessage:
                    GoStep_Withdrwal_SendOrder_SuccessMessage();
                    break;

                case TgBotCallbackAction.Games_ShowAvailableGameCategory:
                    GoStep_Games_GetAvailableGameCategory();
                    break;

                case TgBotCallbackAction.Games_ShowGameListBySpecificCategory:
                    GoStep_Games_ShowGameListBySpecificCategory();
                    break;

                case TgBotCallbackAction.Records_ShowDaysRangeFilter:
                    GoStep_Records_ShowDaysRangeFilter();
                    break;

                case TgBotCallbackAction.Records_GetRecordByDaysFilter:
                    GoStep_Records_GetRecordByDaysFilter();
                    break;

                case TgBotCallbackAction.Games_LaunchTargetGame:
                    GoStep_Games_LaunchTargetGame();
                    break;

                case TgBotCallbackAction.SelectLanguage:
                    GoStep_SelectLanguage();
                    break;

                case TgBotCallbackAction.LogoutAndBack:
                    GoStep_LogoutAndBack();
                    break;

                case TgBotCallbackAction.GetCsLink:
                    GoStep_GetCsLink();
                    break;

                case TgBotCallbackAction.SelectLanguage_SetTargetLanguageCode:
                    GoStep_SetLanguageAndReturn();
                    break;
                case TgBotCallbackAction.CancelAction:
                    CancelAction();
                    break;
                case TgBotCallbackAction.NotImplement:
                    SendMessageAndDelay($"你選擇的功能尚在開發中😭😭😭");
                    break;

                default:
                    SendMessageAndDelay($"你選擇的功能不在定義範圍 {actionName}");
                    break;

            }
        }

        private void GoStep_RegisterOrLogin()
        {
            bool isAccountExsitByApi = VworldPlatformApiService.IsAccountExist().IsAccountExist;

            if (isAccountExsitByApi)
            {
                GoStep_HomePage_LoginByApi_AndResetHistory(true);
            }
            else
            {
                GoStep_Register_WatingReply_RequestPhoneNumber();
            }
        }


        private void GoStep_Register_WatingReply_RequestPhoneNumber()
        {
            string action = TgBotCallbackAction.Register_WatingReply_RequestPhoneNumber;

            StepHistoryQueueHelper.AppendToQueue(_global.TargetChatID, action);

            //清除緩存的手機號碼
            TgBotCache().RemoveCache_Register_PhoneNumber();

            List<List<ButtonList>> multiRowButtons = new List<List<ButtonList>>();

            var phoneRequestButton = InstanceButtonWithi18nKey(i18n("@Msg_PleaseProvidePhoneNumberTOS", "", ""), "");
            phoneRequestButton.request_contact = true;

            multiRowButtons.Add(new List<ButtonList>
                        {
                            phoneRequestButton,
                        });

            multiRowButtons.Add(new List<ButtonList>
                        {
                            InstanceButtonWithi18nKey("@Label_Disagree", TgBotCallbackAction.HomePageWithoutLogin ),
                        });

            BaseTelegramUtility.SendMessage(new TelegramMessageParameter
            {
                Message = i18n($"@Msg_PleaseProvidePhoneNumber"),
                MessageType = MessageType.RequestPhoneNumber,
                TargetChatID = _global.TargetChatID,
                MultiRowButtonList = multiRowButtons,
            });
        }

        private void GoStep_Register_WatingReply_AgentCode()
        {
            var agentCodeByInviteLink = TgBotCache().Register_AgentCodeByInviteLink;

            //如果是透過代理要求碼近來 會自動帶入推薦碼
            if (!string.IsNullOrWhiteSpace(agentCodeByInviteLink))
            {
                string myMsg = i18n("@Msg_UseAgentCodeToRegister", agentCodeByInviteLink); ;
                BaseTelegramUtility.SendMessage(new TelegramMessageParameter
                {
                    Message = myMsg,
                    TargetChatID = _global.TargetChatID,
                });

                _global.InputText = agentCodeByInviteLink;

                //直接送出注冊請求
                GoStep_Register_SubmitCreateAccount();
            }
            else
            {
                StepHistoryQueueHelper.AppendToQueue(_global.TargetChatID, TgBotCallbackAction.Register_WatingReply_AgentCode);

                List<List<ButtonList>> multiRowButtons = new List<List<ButtonList>>();

                multiRowButtons.Add(new List<ButtonList>
                        {
                            InstanceButtonWithi18nKey("@Label_Skip" , TgBotCallbackAction.Register_SubmitCreateAccountByDefaultAgentCode, null),
                            InstanceButtonWithi18nKey("@Label_Return" , TgBotCallbackAction.HomePageWithoutLogin, null),
                        });

                BaseTelegramUtility.SendMessage(new TelegramMessageParameter
                {
                    Message = i18n("@Msg_RequestWriteAgentCodeV2", TgBotCache().DefaultAgentCode),
                    TargetChatID = _global.TargetChatID,
                    MultiRowButtonList = multiRowButtons
                });
            }
        }

        private void GoStep_Register_SubmitCreateAccount()
        {
            string tempPhoneNumber = TgBotCache().Register_PhoneNumber;

            CheckRedisCacheValueNotExpiredOrEmptyNull(tempPhoneNumber);

            if (string.IsNullOrWhiteSpace(tempPhoneNumber))
            {
                SendErrorMessageAndDelay("@Error_Msg_ProcessDataExpired");
                GoStep_HomePageWithoutLogin_AndResetHistory();
                return;
            }

            string inputAgentCode = _global.InputText;
            CallRegisterProcessAndApi(tempPhoneNumber, inputAgentCode);
        }


        private void GoStep_Register_SubmitCreateAccountByDefaultAgentCode()
        {
            if (!IsWaitingReplyStep())
            {
                SendErrorMessageAndDelay("@Error_Msg_OperateTimeout_PleaseRetry");
                GoStep_HomePageWithoutLogin_AndResetHistory();
                return;
            }

            var defaultAgentCode = TgBotCache().DefaultAgentCode;

            //若cache過期 則嘗試從API抓
            if (string.IsNullOrWhiteSpace(defaultAgentCode))
            {
                var queryMenu = VworldPlatformApiService.HomePageInfoWithoutLogin();
                defaultAgentCode = queryMenu?.Data.invite_code;
            }

            string myMsg = i18n("@Msg_UseAgentCodeToRegister", defaultAgentCode);
            BaseTelegramUtility.SendMessage(new TelegramMessageParameter
            {
                Message = myMsg,
                TargetChatID = _global.TargetChatID,
            });

            _global.InputText = defaultAgentCode;

            //直接送出注冊請求
            GoStep_Register_SubmitCreateAccount();
        }


        private void CallRegisterProcessAndApi(string phone, string agentCode)
        {
            var response = VworldPlatformApiService.Register(phone, agentCode);

            if (response.Code == 10901)
            {
                SendMessageAndDelay(i18n("@Msg_Fail_PhoneNumberAlreadyExist"));
                return;
            } 
            else if ( !response.Data.IsSuccess ) 
            {
                GoStep_LastStep_RetryAgain();
                return;
            }

            SendMessageAndDelay(i18n("@Msg_Success_RegisterSuccessful"));
            GoStep_HomePage_LoginByApi_AndResetHistory();
        }



        private void GoStep_SelectLanguage()
        {
            List<List<ButtonList>> multiRowButtons = new List<List<ButtonList>>();

            string actionName = TgBotCallbackAction.SelectLanguage_SetTargetLanguageCode;
            string callbackActionEN = $"action={actionName}&{TgBotCallbackExtraParameter.LanguageCode}={SystemConstant.LanguageCode_EN}";
            string callbackActionTW = $"action={actionName}&{TgBotCallbackExtraParameter.LanguageCode}={SystemConstant.LanguageCode_ZH}";

            multiRowButtons.Add(new List<ButtonList>
                        {
                            InstanceButtonWithi18nKey("English",  callbackActionEN),
                            InstanceButtonWithi18nKey("繁體中文", callbackActionTW),
                        });

            multiRowButtons.Add(new List<ButtonList>
                        {
                            InstanceButtonWithi18nKey("@Label_Return", TgBotCallbackAction.HomePageWithoutLogin ),
                        });

            BaseTelegramUtility.SendMessage(new TelegramMessageParameter
            {
                Message = i18n("@Msg_PleaseSelectLanguage"),
                TargetChatID = _global.TargetChatID,
                MultiRowButtonList = multiRowButtons,
            });
        }


        private void GoStep_GetCsLink()
        {
            var homePageInfo = GetHomePageInfo();

            string msg = i18n("@Msg_PleaseClickTheLink") + Environment.NewLine + homePageInfo.cs_link;

            BaseTelegramUtility.SendMessage(new TelegramMessageParameter
            {
                Message = msg,
                TargetChatID = _global.TargetChatID,
            });
        }



        private void GoStep_LogoutAndBack()
        {
            TgBotCache().RemoveCache_SetToLoginKeepStatus();

            Thread.Sleep(500);

            GoStep_HomePageWithoutLogin_AndResetHistory(500);
        }



        private void GoStep_Deposit_SelectPaymentType()
        {
            var action = TgBotCallbackAction.Deposit_ShowAvailablePaymentTypes;

            StepHistoryQueueHelper.AppendToQueue(_global.TargetChatID, action);

            var walletModel = VworldPlatformApiService.GetBalance().Data;

            var queryDepositTypes = VworldPlatformApiService.GetAvailableDepositPaymentTypes();

            List<List<ButtonList>> multiRowButtons = new List<List<ButtonList>>();

            var availableItems = queryDepositTypes.Data;

            if (availableItems.IsEmptyOrNull())
            {
                SendErrorMessageAndDelay("@Error_Msg_EmptyCollection");
                return;
            }

            foreach (var item in availableItems)
            {
                string callback = $"action={TgBotCallbackAction.Deposit_WatingReply_DepositAmount}&{TgBotCallbackExtraParameter.PaymentType}={item.order_type_id}";
                if(item.order_type_id == "32")
                {
                    var linkButton = InstanceButtonWithi18nKey(item.name, "", null);
                    linkButton.web_app = new { url = item.url };
                    multiRowButtons.Add(new List<ButtonList>
                        {
                            linkButton,
                        });
                }
                else
                {
                    multiRowButtons.Add(new List<ButtonList>
                        {
                            InstanceButtonWithi18nKey(item.name,  callback),
                        });
                }
            }
            multiRowButtons.Add(new List<ButtonList>
                        {
                            InstanceButtonWithi18nKey("@Label_Return", TgBotCallbackAction.HomePage),
                        });

            string msg = GetAllWalletBalanceText(walletModel)
            + Environment.NewLine + i18n("@Msg_PleaseSelectPaymentType");

            BaseTelegramUtility.SendMessage(new TelegramMessageParameter
            {
                Message = msg,
                TargetChatID = _global.TargetChatID,
                MultiRowButtonList = multiRowButtons,
            });
        }

        private void GoStep_Withdrwal_Process()
        {
            var homePageInfo = GetHomePageInfo();
            var walletModel = VworldPlatformApiService.GetBalance().Data;
            var queryFlowLimit = VworldPlatformApiService.GetWithdrawalFlowLimit().Data;
            var queryPaymentPasswordIsSet = VworldPlatformApiService.CheckPaymentPasswordIsSet().Data;
            List<List<ButtonList>> multiRowButtons = new List<List<ButtonList>>();

            string msg = GetAllWalletBalanceText(walletModel) + Environment.NewLine;
            
            
            // define variable linkSetPaymentPassword homePageInfo.event_link replace events to payment-password
            

            homePageInfo.event_link = homePageInfo.event_link ?? "";

            
            
            if(!queryPaymentPasswordIsSet.IsSuccess) 
            {
                msg += i18n("@Msg_PaymentPasswordNotSet");
                var linkSetPaymentPassword = [REDACTED]"withdrawal/bank-bind", "payment-password");
                var linkButtonSetPaymentPassword = [REDACTED]"@Label_SetPaymentPassword", "", null);
                linkButtonSetPaymentPassword.web_app = new { url = linkSetPaymentPassword };
                multiRowButtons.Add(new List<ButtonList>
                {
                    linkButtonSetPaymentPassword,
                    InstanceButtonWithi18nKey("@Label_Return", TgBotCallbackAction.CancelAction ),
                });
            } 
            else if (queryFlowLimit.flow_limit <= 0)
            {
                msg += i18n("@Msg_YouCanWithdrawal");
                var linkButtonWithdrawal = InstanceButtonWithi18nKey("@Label_Withdrawal", "", null);
                linkButtonWithdrawal.web_app = new { url = homePageInfo.withdrawal_link };
                multiRowButtons.Add(new List<ButtonList>
                {
                    linkButtonWithdrawal,
                    InstanceButtonWithi18nKey("@Label_Return", TgBotCallbackAction.CancelAction ),
                });
            }
            else
            {
                msg += i18n("@Msg_YourFlowLimit", queryFlowLimit.flow_limit) + Environment.NewLine;
                msg += i18n("@Msg_YouCanNotWithdrawal");
            }

            BaseTelegramUtility.SendMessage(new TelegramMessageParameter
            {
                Message = msg,
                TargetChatID = _global.TargetChatID,
                MultiRowButtonList = multiRowButtons,
                MessageType = MessageType.Normal,
                MessageCategoryTag = MessageCategoryTagEnum.Normal
            });
        }



        private void GoStep_Deposit_WatingReply_PaymentCashAccount()
        {
            //var action = TgBotCallbackAction.Deposit_WatingReply_PaymentCashAccount;

            //StepHistoryQueueHelper.AppendToQueue(_global.TargetChatID, action);

            //List<List<ButtonList>> multiRowButtons = new List<List<ButtonList>>();

            //multiRowButtons.Add(new List<ButtonList>
            //            {
            //                InstanceButtonWithi18nKey("@Label_Return", GetNextOrPreviousAction(action , -1)),
            //            });

            //BaseTelegramUtility.SendMessage(new TelegramMessageParameter
            //{
            //    Message = i18n("@Msg_PleaseWriteGCashAccount"),
            //    TargetChatID = _global.TargetChatID,
            //    MultiRowButtonList = multiRowButtons,
            //});
        }


        private void GoStep_Deposit_WatingReply_DepositAmount()
        {
            var action = TgBotCallbackAction.Deposit_WatingReply_DepositAmount;

            List<List<ButtonList>> multiRowButtons = new List<List<ButtonList>>();

            multiRowButtons.Add(new List<ButtonList>
                        {
                            InstanceButtonWithi18nKey("@Label_Return", GetNextOrPreviousAction(action , -1)),
                        });

            var lastSelectPaymentType = TgBotCache().Deposit_LastSelectPaymentType;

            if (string.IsNullOrWhiteSpace(lastSelectPaymentType))
            {
                SendErrorMessageAndDelay("@Error_Msg_ProcessDataExpired");
                return;
            }

            int paymentType = Int32.Parse(lastSelectPaymentType);

            var paymentTypeSetting = VworldPlatformApiService.GetTargetPaymentTypeSetting(paymentType).Data;

            decimal min = paymentTypeSetting.min_amount;
            decimal max = paymentTypeSetting.max_amount;

            StepHistoryQueueHelper.AppendToQueue(_global.TargetChatID, action);

            string msg = i18n("@Msg_PleaseWriteAmount") + Environment.NewLine + i18n("@Msg_MinAndMaxValue", min, max);

            BaseTelegramUtility.SendMessage(new TelegramMessageParameter
            {
                Message = msg,
                TargetChatID = _global.TargetChatID,
                MultiRowButtonList = multiRowButtons,
            });
        }

        private void GoStep_Deposit_SubmitOrderAndGetLinkButton()
        {
            var paymentType = TgBotCache().Deposit_LastSelectPaymentType;
            var account = "Default_Value"; //TgBotCache().Deposit_GCashAccount;

            CheckRedisCacheValueNotExpiredOrEmptyNull(paymentType);
            CheckRedisCacheValueNotExpiredOrEmptyNull(account);

            string text = _global.InputText;

            decimal amount = decimal.Parse(text);

            var paymentTypeSetting = VworldPlatformApiService.GetTargetPaymentTypeSetting( Int32.Parse(paymentType) ).Data;

            if(amount <= 0 || amount < paymentTypeSetting.min_amount || amount > paymentTypeSetting.max_amount)
            {
                SendErrorMessageAndDelay(i18n("@Validate_NumberValueRange", paymentTypeSetting.min_amount, paymentTypeSetting.max_amount));
                GoStep_LastStep_RetryAgain();
                return;
            }

            //呼叫vworldAPI 產生支付訂單
            var apiResult = VworldPlatformApiService.SendDepositOrder(new VworldDepositPaymentOrderRequestParameter
            {
                Amount = amount,
                GCashAccount = account,
                PaymentTypeID = paymentType,
            });

            var paymentUrl = apiResult.Data?.url;

            if (string.IsNullOrWhiteSpace(paymentUrl))
            {
                SendErrorMessageAndDelay("@Error_Msg_OperateFail_PleaseRetry");
                GoStep_LastStep_RetryAgain();
                return;
            }

            StepHistoryQueueHelper.RemoveAllHistory(_global.TargetChatID);

            DeleteAllTelegramMessageDesideSomeOfMessage();

            List<List<ButtonList>> multiRowButtons = new List<List<ButtonList>>();

            multiRowButtons.Add(new List<ButtonList>
                        {
                            InstanceButtonWithi18nKey("@Label_PaymentLink", "" , paymentUrl),
                        });

            BaseTelegramUtility.SendMessage(new TelegramMessageParameter
            {
                Message = i18n("@Msg_Success_PleaseRedirectToPaymentLink"),
                TargetChatID = _global.TargetChatID,
                MultiRowButtonList = multiRowButtons,
                MessageCategoryTag = MessageCategoryTagEnum.MessageAndLink
            });
        }

        private void GoStep_Withdrwal_Extra_WaitingReply_SetPaymentPassword()
        {
            var action = TgBotCallbackAction.Withdrwal_Extra_WaitingReply_SetPaymentPassword;

            StepHistoryQueueHelper.AppendToQueue(_global.TargetChatID, action);

            List<List<ButtonList>> multiRowButtons = new List<List<ButtonList>>();

            multiRowButtons.Add(new List<ButtonList>
                        {
                            InstanceButtonWithi18nKey("@Label_Return", TgBotCallbackAction.HomePage),
                        });

            string msg = i18n("@Msg_PleaseWriteFirstTimesPaymentPassword");

            BaseTelegramUtility.SendMessage(new TelegramMessageParameter
            {
                Message = msg,
                TargetChatID = _global.TargetChatID,
                MultiRowButtonList = multiRowButtons
            });
        }

        private void GoStep_Withdrwal_Extra_SubmitUpdatePaymentPassword()
        {
            string inputText = _global.InputText;

            var updateResult = VworldPlatformApiService.UpdatePaymentPassword(inputText).Data;

            if (updateResult.IsSuccess)
            {
                SendMessageAndDelay("👌" + i18n("@Msg_Success"), 250);
                StepHistoryQueueHelper.RemoveAllHistory(_global.TargetChatID);
                GoStep_Withdrwal_ShowAvailableWithdrawalType();
            }
            else
            {
                GoStep_LastStep_RetryAgain();
            }
        }


        private void GoStep_Withdrwal_ShowAvailableWithdrawalType()
        {
            var paymentPasswordSetOrNot = VworldPlatformApiService.CheckPaymentPasswordIsSet().Data;

            bool isPasswordSet = paymentPasswordSetOrNot.IsSuccess;

            if (isPasswordSet)
            {
                var walletModel = VworldPlatformApiService.GetBalance().Data;

                List<List<ButtonList>> multiRowButtons = new List<List<ButtonList>>();

                var availableWithdrawalTypes = VworldPlatformApiService.GetAvailableWithdrawalTypes().Data;

                var queryFlowLimit = VworldPlatformApiService.GetWithdrawalFlowLimit().Data;

                var withdrawalTypes = availableWithdrawalTypes;

                foreach (var item in withdrawalTypes)
                {
                    string myAction = $"action={TgBotCallbackAction.Withdrwal_WatingReply_PaymentPassword}&{TgBotCallbackExtraParameter.BankID}={item.bank_id}";

                    multiRowButtons.Add(new List<ButtonList>
                        {
                            InstanceButtonWithi18nKey(item.name, myAction),
                        });
                }

                multiRowButtons.Add(new List<ButtonList>
                        {
                            InstanceButtonWithi18nKey("@Label_Return", TgBotCallbackAction.HomePage),
                        });

                string msg = GetAllWalletBalanceText(walletModel)
                    + Environment.NewLine + i18n("@Msg_YourFlowLimit", queryFlowLimit.flow_limit) 
                    + Environment.NewLine + i18n("@Msg_PleaseSelectWithdrawalType");

                BaseTelegramUtility.SendMessage(new TelegramMessageParameter
                {
                    Message = msg,
                    TargetChatID = _global.TargetChatID,
                    MultiRowButtonList = multiRowButtons
                });
            }
            else
            {
                GoStep_Withdrwal_Extra_WaitingReply_SetPaymentPassword();
            }
        }


        private void GoStep_Withdrwal_WatingReply_PaymentPassword()
        {
            var action = TgBotCallbackAction.Withdrwal_WatingReply_PaymentPassword;

            StepHistoryQueueHelper.AppendToQueue(_global.TargetChatID, action);

            List<List<ButtonList>> multiRowButtons = new List<List<ButtonList>>();

            multiRowButtons.Add(new List<ButtonList>
                        {
                            InstanceButtonWithi18nKey("@Label_Return", TgBotCallbackAction.Withdrwal_ShowAvailableWithdrawalType),
                        });

            BaseTelegramUtility.SendMessage(new TelegramMessageParameter
            {
                Message = i18n("@Msg_PleaseWritePaymentPassword"),
                TargetChatID = _global.TargetChatID,
                MultiRowButtonList = multiRowButtons
            });
        }


        private void GoStep_Withdrwal_WatingReply_PaymentCashAccount()
        {
            string inputText = _global.InputText;

            var checkResult = VworldPlatformApiService.ConfirmPaymentPasswordCorrect(inputText).Data;

            if (checkResult.IsSuccess)
            {
                var action = TgBotCallbackAction.Withdrwal_WatingReply_PaymentCashAccount;

                StepHistoryQueueHelper.AppendToQueue(_global.TargetChatID, action);

                List<List<ButtonList>> multiRowButtons = new List<List<ButtonList>>();

                multiRowButtons.Add(new List<ButtonList>
                        {
                            InstanceButtonWithi18nKey("@Label_Return", TgBotCallbackAction.Withdrwal_ShowAvailableWithdrawalType),
                        });

                BaseTelegramUtility.SendMessage(new TelegramMessageParameter
                {
                    Message = i18n("@Msg_PleaseWriteGCashAccount"),
                    TargetChatID = _global.TargetChatID,
                    MultiRowButtonList = multiRowButtons
                });
            }
            else
            {
                GoStep_LastStep_RetryAgain();
            }
        }


        private void GoStep_Withdrwal_WatingReply_WithdrawalAmount()
        {
            string inputText = _global.InputText;

            bool isPasswordCorrect = false;

            if (_global.FirstTriggerActionName == TgBotCallbackAction.Withdrwal_SendOrder_SuccessMessage)
            {
                isPasswordCorrect = true;
            }
            else
            {
                var checkResult = VworldPlatformApiService.ConfirmPaymentPasswordCorrect(inputText).Data;
                isPasswordCorrect = checkResult.IsSuccess;
            }

            if (isPasswordCorrect)
            {
                var action = TgBotCallbackAction.Withdrwal_WatingReply_WithdrawalAmount;

                StepHistoryQueueHelper.AppendToQueue(_global.TargetChatID, action);

                List<List<ButtonList>> multiRowButtons = new List<List<ButtonList>>();

                multiRowButtons.Add(new List<ButtonList>
                        {
                            InstanceButtonWithi18nKey("@Label_Return", TgBotCallbackAction.Withdrwal_ShowAvailableWithdrawalType ),
                        });

                var lastSelectWithdrawalType = TgBotCache().Withdrawal_SelectWithdrawalBankID;

                CheckRedisCacheValueNotExpiredOrEmptyNull(lastSelectWithdrawalType);

                int withdrawalType = Int32.Parse(lastSelectWithdrawalType);

                var configSetting = VworldPlatformApiService.GetWithdrawalConfigSetting(withdrawalType).Data;

                decimal min = configSetting.min_amount;
                decimal max = configSetting.max_amount;

                string msg = i18n("@Msg_PleaseWriteAmount") + Environment.NewLine + i18n("@Msg_MinAndMaxValue", min, max);

                BaseTelegramUtility.SendMessage(new TelegramMessageParameter
                {
                    Message = msg,
                    TargetChatID = _global.TargetChatID,
                    MultiRowButtonList = multiRowButtons
                });
            }
            else
            {
                GoStep_LastStep_RetryAgain();
            }
        }


        private void GoStep_Withdrwal_SendOrder_SuccessMessage()
        {
            var tgCache = TgBotCache();

            string withdrawalBankID = tgCache.Withdrawal_SelectWithdrawalBankID;
            string GCashAccount = ""; //tgCache.Withdrawal_GCashAccount;
            string amount = _global.InputText;

            CheckRedisCacheValueNotExpiredOrEmptyNull(withdrawalBankID);
            //CheckRedisCacheValueNotExpiredOrEmptyNull(GCashAccount);

            var param = new VworldSendWithdrawalOrderParameter
            {
                BankID = Int32.Parse(withdrawalBankID),
                Amount = decimal.Parse(amount),
            };

            var orderResult = VworldPlatformApiService.SendWithdrawalOrder(param).Data;

            if (orderResult.IsSuccess)
            {
                StepHistoryQueueHelper.RemoveAllHistory(_global.TargetChatID);

                DeleteAllTelegramMessageDesideSomeOfMessage(isDeleteNewstMessageID: true);

                List<List<ButtonList>> multiRowButtons = new List<List<ButtonList>>();

                multiRowButtons.Add(new List<ButtonList>
                        {
                            InstanceButtonWithi18nKey("@Label_Return", TgBotCallbackAction.HomePage),
                        });

                BaseTelegramUtility.SendMessage(new TelegramMessageParameter
                {
                    Message = i18n("@Msg_Success_PleaseWaitingWithdrawal"),
                    TargetChatID = _global.TargetChatID,
                    MultiRowButtonList = multiRowButtons
                });
            }
            else
            {
                GoStep_LastStep_RetryAgain();
            }
        }



        private void GoStep_Games_GetAvailableGameCategory()
        {
            List<List<ButtonList>> multiRowButtons = new List<List<ButtonList>>();

            string callbackAction1 = $"action={TgBotCallbackAction.Games_ShowGameListBySpecificCategory}&{TgBotCallbackExtraParameter.Category}={(int)GameCategoryEnum.Recently}";
            string callbackAction2 = $"action={TgBotCallbackAction.Games_ShowGameListBySpecificCategory}&{TgBotCallbackExtraParameter.Category}={(int)GameCategoryEnum.HotGames}";

            multiRowButtons.Add(new List<ButtonList>
                        {
                            InstanceButtonWithi18nKey("@Label_GameCategory_Recent", callbackAction1, null),
                            InstanceButtonWithi18nKey("@Label_GameCategory_Hot", callbackAction2, null),
                        });

            string url = VworldPlatformApiService.GetLobbyUrl().Data?.url;

            multiRowButtons.Add(new List<ButtonList>
                        {
                            InstanceButtonWithi18nKey("@Label_GameCategory_GameHall", "" , url),
                        });

            multiRowButtons.Add(new List<ButtonList>
                        {
                            InstanceButtonWithi18nKey("@Label_Return", TgBotCallbackAction.HomePage, null),
                        });

            // var balance = VworldPlatformApiService.GetBalance().Data?.Balance;
            var walletModel = VworldPlatformApiService.GetBalance().Data;

            string msg = i18n("@Msg_PleaseSelectGameTypeOrGameLobby") + Environment.NewLine
            + GetAllWalletBalanceText(walletModel);

            BaseTelegramUtility.SendMessage(new TelegramMessageParameter
            {
                Message = msg,
                TargetChatID = _global.TargetChatID,
                MultiRowButtonList = multiRowButtons
            });
        }


        private void GoStep_Games_ShowGameListBySpecificCategory()
        {
            int cateogory = _global.CallbackParsing.Category;
            if ( (int) GameCategoryEnum.HotGames == cateogory) {
                var queryVendors = VworldPlatformApiService.GetHotVendorList().Data;
                // message mode
                var isMessageMode = false;
                if (isMessageMode) {
                    foreach (var vendor in queryVendors.list) {
                        List<List<ButtonList>> multiRowButtons = new List<List<ButtonList>>();
                        var linkButtonLaunch= InstanceButtonWithi18nKey(vendor.vendor_name, "", null);
                        linkButtonLaunch.web_app = new { url = vendor.launch_url };
                        multiRowButtons.Add(new List<ButtonList>
                        {
                            linkButtonLaunch
                        });
                        BaseTelegramUtility.SendMessage(new TelegramMessageParameter
                        {
                            ImageUrl = vendor.vendor_image,
                            // AnimationUrl = "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/tgbot/vworld_welcome.mp4",
                            // Message = vendor.vendor_name,
                            MultiRowButtonList = multiRowButtons,
                            TargetChatID = _global.TargetChatID,
                        });
                    }    
                } else {
                    List<List<ButtonList>> multiRowButtons = new List<List<ButtonList>>();
                    List<ButtonList> firstRow = new List<ButtonList>();
                    foreach (var vendor in queryVendors.list) {
                        var linkButtonLaunch= InstanceButtonWithi18nKey(vendor.vendor_name, "", null);
                        linkButtonLaunch.web_app = new { url = vendor.launch_url };
                        firstRow.Add(linkButtonLaunch);
                    }
                    multiRowButtons.Add(firstRow);
                    BaseTelegramUtility.SendMessage(new TelegramMessageParameter
                        {
                            Message = i18n("@Msg_PleaseSelectGameLobby"),
                            MultiRowButtonList = multiRowButtons,
                            TargetChatID = _global.TargetChatID,
                        });
                }
            } else {
                List<List<ButtonList>> multiRowButtons = new List<List<ButtonList>>();
                var queryGames = VworldPlatformApiService.GetGamesByCategoryType(cateogory).Data;
                foreach (var game in queryGames.game_list)
                {
                    string callbackAction = $"action={TgBotCallbackAction.Games_LaunchTargetGame}&{TgBotCallbackExtraParameter.GameID}={game.game_id}";

                    multiRowButtons.Add(new List<ButtonList>
                            {
                                InstanceButtonWithi18nKey(game.name, "" , game.url),
                            });
                }

                multiRowButtons.Add(new List<ButtonList>
                            {
                                InstanceButtonWithi18nKey("@Label_Return", TgBotCallbackAction.Games_ShowAvailableGameCategory),
                            });

                BaseTelegramUtility.SendMessage(new TelegramMessageParameter
                {
                    Message = i18n("@Msg_PleaseSelectGameToPlay"),
                    TargetChatID = _global.TargetChatID,
                    MultiRowButtonList = multiRowButtons
                });
            }
        }

        private void GoStep_Games_LaunchTargetGame()
        {
            List<List<ButtonList>> multiRowButtons = new List<List<ButtonList>>();

            int gameID = _global.CallbackParsing.GameID;

            var launchResult = VworldPlatformApiService.GetGameLaunchUrl(gameID).Data;

            if (string.IsNullOrWhiteSpace(launchResult.Url))
            {
                SendErrorMessageAndDelay("@Error_Msg_OperateFail_PleaseRetry");
                GoStep_LastStep_RetryAgain();
                return;
            }

            multiRowButtons.Add(new List<ButtonList>
                        {
                            InstanceButtonWithi18nKey("@Label_GameLink", "" , launchResult.Url),
                        });

            BaseTelegramUtility.SendMessage(new TelegramMessageParameter
            {
                Message = i18n("@Msg_PleaseClickTheLink"),
                TargetChatID = _global.TargetChatID,
                MultiRowButtonList = multiRowButtons
            });
        }


        private void GoStep_Records_ShowDaysRangeFilter()
        {
            List<List<ButtonList>> multiRowButtons = new List<List<ButtonList>>();

            string nextAction = TgBotCallbackAction.Records_GetRecordByDaysFilter;
            string actionToday0 = $"action={nextAction}&{TgBotCallbackExtraParameter.DaysRange}=0";
            string actionToday3 = $"action={nextAction}&{TgBotCallbackExtraParameter.DaysRange}=3";
            string actionToday7 = $"action={nextAction}&{TgBotCallbackExtraParameter.DaysRange}=7";
            string actionToday30 = $"action={nextAction}&{TgBotCallbackExtraParameter.DaysRange}=30";

            multiRowButtons.Add(new List<ButtonList>
                        {
                            InstanceButtonWithi18nKey("@Label_Today", actionToday0 ),
                            InstanceButtonWithi18nKey("@Label_3Days", actionToday3),
                        });
            multiRowButtons.Add(new List<ButtonList>
                        {
                            InstanceButtonWithi18nKey("@Label_7Days", actionToday7 ),
                            InstanceButtonWithi18nKey("@Label_30Days", actionToday30),
                        });

            multiRowButtons.Add(new List<ButtonList>
                        {
                            InstanceButtonWithi18nKey("@Label_Return", TgBotCallbackAction.HomePage),
                        });

            BaseTelegramUtility.SendMessage(new TelegramMessageParameter
            {
                Message = i18n("@Msg_PleaseChooseYourOperation"),
                TargetChatID = _global.TargetChatID,
                MultiRowButtonList = multiRowButtons
            });
        }


        private void GoStep_Records_GetRecordByDaysFilter()
        {
            var daysRange = _global.CallbackParsing.DaysRange;

            var queryItems = VworldPlatformApiService.GetPaymentRecordByDaysRange(daysRange).Data;

            string myMsg = "";

            if (queryItems.Record.IsNotEmptyNull())
            {
                myMsg += $"  {i18n("@Label_Date")}  ｜  {i18n("@Label_Amount")}  ｜  {i18n("@Label_Type")}  ｜  {i18n("@Label_Status")}  " + Environment.NewLine;

                foreach (var item in queryItems.Record)
                {
                    string amountText = string.Format("{0,10}", item.amount.ToString());
                    string orderTypeText = string.Format("{0,8}", item.order_type);
                    string statusText = string.Format("{0,8}", item.status_name);
                    myMsg += $"{item.created_at}｜{amountText}｜{orderTypeText}｜{statusText}" + Environment.NewLine;
                }
            }
            else
            {
                myMsg = i18n("@Error_Msg_NotFound");
            }

            BaseTelegramUtility.SendMessage(new TelegramMessageParameter
            {
                Message = myMsg,
                TargetChatID = _global.TargetChatID,
                MessageCategoryTag = MessageCategoryTagEnum.DoNotDelete,
                ButtonList = new List<ButtonList>
                {
                    InstanceButtonWithi18nKey(i18n("@Label_Return") , TgBotCallbackAction.Records_ShowDaysRangeFilter)
                }
            });
        }

        private void ShowMenuByAccountExistOrNot(bool isLoginAgain = false)
        {
            var accountCheck = VworldPlatformApiService.IsAccountExist();

            if (accountCheck.IsAccountExist)
            {
                GoStep_HomePage_LoginByApi_AndResetHistory(isLoginAgain);
            }
            else
            {
                GoStep_HomePageWithoutLogin_AndResetHistory();
            }
        }


        private void Test001()
        {
            var defaultTemplateMsg = new TelegramMessageParameter
            {
                Message = i18n("@Msg_DefaultMenuMessage") + Environment.NewLine + Environment.NewLine,
                TargetChatID = _global.TargetChatID,
                MessageType = MessageType.KeyboardButtons
            };

            BaseTelegramUtility.SendMessage(defaultTemplateMsg);


            //List<List<ButtonList>> multiRowButtons = new List<List<ButtonList>>();

            //multiRowButtons.Add(new List<ButtonList>
            //            {
            //                InstanceButtonWithi18nKey("@Label_Login",  TgBotCallbackAction.RegisterOrLogin),
            //                InstanceButtonWithi18nKey("@Label_CS", "" , "https://example.invalid/"),
            //            });

            //multiRowButtons.Add(new List<ButtonList>
            //            {
            //                InstanceButtonWithi18nKey("@Label_OfficialTG", "" , "https://example.invalid/"),
            //                InstanceButtonWithi18nKey("@Label_SelectLanguage", TgBotCallbackAction.SelectLanguage),
            //            });

            //var defaultTemplateMsg = new TelegramMessageParameter
            //{
            //    Message = i18n("@Msg_DefaultMenuMessage") + Environment.NewLine + Environment.NewLine,
            //    MultiRowButtonList = multiRowButtons,
            //    TargetChatID = _global.TargetChatID,
            //    MessageType = MessageType.KeyboardButtons
            //};

            //BaseTelegramUtility.SendMessage(defaultTemplateMsg);
        }


        private void Login()
        {
            var loginResult = VworldPlatformApiService.Login().Data;

            if (loginResult.IsSuccess)
            {
                TgBotCache().SetCache_LoginStatus();
            }
        }


        private void GoStep_HomePageWithoutLogin_AndResetHistory(int messageDelaySeconds = 0)
        {
            StepHistoryQueueHelper.RemoveAllHistory(_global.TargetChatID);

            List<List<ButtonList>> multiRowButtons = new List<List<ButtonList>>();

            var homePageNotLogin = VworldPlatformApiService.HomePageInfoWithoutLogin().Data;

            TgBotCache().SetCache_DefaultAgentCode(homePageNotLogin.invite_code);

            DeleteAllTelegramMessageDesideSomeOfMessage(true, true, 0);

            multiRowButtons.Add(new List<ButtonList>
                        {
                            InstanceButtonWithi18nKey("@Label_Login",  TgBotCallbackAction.RegisterOrLogin, null),
                            InstanceButtonWithi18nKey("@Label_CS", "" , homePageNotLogin.cs_tg),
                        });

            multiRowButtons.Add(new List<ButtonList>
                        {
                            InstanceButtonWithi18nKey("@Label_OfficialTG", "" , homePageNotLogin.tg),
                            InstanceButtonWithi18nKey("@Label_SelectLanguage", TgBotCallbackAction.SelectLanguage, null),
                        });

            //if(SettingHelper.WebSiteName.Contains("AzureDev"))
            //{
            //    var myButton = InstanceButtonWithi18nKey("以WebApp方式打開", "", homePageNotLogin.tg);
            //    myButton.url = "";
            //    myButton.web_app = new
            //    {
            //        url = "https://example.invalid/login"
            //    };

            //    multiRowButtons.Add(new List<ButtonList>
            //            {
            //                myButton
            //            });
            //}

            string botName = homePageNotLogin.platform_name ?? "Casino365";
            string animationUrl = "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/tgbot/20240920_restart.mp4";
            if (_global.InputText == "/restart") {
                animationUrl = "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/tgbot/20240920_restart.mp4";
            }
            var defaultTemplateMsg = new TelegramMessageParameter
            {
                //ImageUrl = "https://example.invalid/public/0de959b6-3841-4dba-835c-322455f9f27e.png",
                AnimationUrl = animationUrl,
                Message = i18n("@Msg_DefaultMenuMessage", botName) + Environment.NewLine + Environment.NewLine,
                MultiRowButtonList = multiRowButtons,
                TargetChatID = _global.TargetChatID,
                MessageCategoryTag = MessageCategoryTagEnum.HomeMenu,
                AdvanceSetting = new TelegramMessageAdvanceSetting
                {
                    IsRemoveKeyboard = true
                }
            };

            Thread.Sleep(messageDelaySeconds);

            BaseTelegramUtility.SendMessage(defaultTemplateMsg);

        }



        private void GoStep_HomePage_LoginByApi_AndResetHistory(bool isLoginAgain = true)
        {
            //TODO 總覺得呼叫這個可以優化....
            //TODO 總覺得呼叫這個可以優化....
            //TODO 總覺得呼叫這個可以優化....
            //TODO 總覺得呼叫這個可以優化....
            if (isLoginAgain)
            {
                Login();
            }

            StepHistoryQueueHelper.RemoveAllHistory(_global.TargetChatID);

            DeleteAllTelegramMessageDesideSomeOfMessage(true);

            var homePageInfo = VworldPlatformApiService.HomePageInfo();

            //嘗試再重登一次
            if (homePageInfo.Code == 9998 || homePageInfo.Data == null)
            {
                Login();
                homePageInfo = VworldPlatformApiService.HomePageInfo();

                if (!homePageInfo.IsSuccessCode)
                {
                    SendErrorMessageAndDelay("@Error_Msg_OperateFail_PleaseRetry");
                }
            }

            var walletModel = VworldPlatformApiService.GetBalance().Data;

            List<List<ButtonList>> multiRowButtons = new List<List<ButtonList>>();

            //臨時防呆。之後可刪除
            if (string.IsNullOrWhiteSpace(homePageInfo.Data.withdrawal_link))
            {
                homePageInfo.Data.withdrawal_link = "https://example.invalid/";
            }

            var linkButtonWithdrawal = InstanceButtonWithi18nKey("@Label_Withdrawal", "", null);
            linkButtonWithdrawal.web_app = new { url = homePageInfo.Data.withdrawal_link };

            multiRowButtons.Add(new List<ButtonList>
                        {
                            InstanceButtonWithi18nKey("@Label_Deposit",  TgBotCallbackAction.Deposit_ShowAvailablePaymentTypes, null),
                            InstanceButtonWithi18nKey("@Label_Withdrawal",  TgBotCallbackAction.Withdrwal_Process, null),
                            // linkButtonWithdrawal,
                        });
            var linkWallet = InstanceButtonWithi18nKey("@Label_WalletManage", "", null);
            linkWallet.web_app = new { url = homePageInfo.Data.wallet_link };

            multiRowButtons.Add(new List<ButtonList>
                        {
                            linkWallet,
                            InstanceButtonWithi18nKey("@Label_SelectGames", TgBotCallbackAction.Games_ShowAvailableGameCategory, null),
                        });

            var linkEvent = InstanceButtonWithi18nKey("@Label_EventInfo", "", null);
            linkEvent.web_app = new { url = homePageInfo.Data.event_link };

            multiRowButtons.Add(new List<ButtonList>
                        {
                            InstanceButtonWithi18nKey("@Label_DepositRecords", TgBotCallbackAction.Records_ShowDaysRangeFilter, null),
                            linkEvent,
                            
                        });
            var linkCS = InstanceButtonWithi18nKey("@Label_CS", "", homePageInfo.Data.cs_link);
            var linkButtonLaunchSite = InstanceButtonWithi18nKey("@Label_LaunchSite", "", null);
            linkButtonLaunchSite.web_app = new { url = homePageInfo.Data.launch_link };

            multiRowButtons.Add(new List<ButtonList>
                        {
                            linkCS,
                            linkButtonLaunchSite,
                            InstanceButtonWithi18nKey("@Label_LogoutAndReturn", TgBotCallbackAction.LogoutAndBack, null),
                        });
            
            string msg = $"{i18n("@Msg_Welcome")}, {_global.TargetUsetName}{Environment.NewLine}";
            msg += GetAllWalletBalanceText(walletModel);
            if (_global.InputText == "/restart") {
                string animationUrl = "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/tgbot/20240920_restart.mp4";
                BaseTelegramUtility.SendMessage(new TelegramMessageParameter
                {
                    AnimationUrl = animationUrl,
                    Message = msg,
                    TargetChatID = _global.TargetChatID,
                    MultiRowButtonList = multiRowButtons,
                    MessageType = MessageType.KeyboardButtons,
                    MessageCategoryTag = MessageCategoryTagEnum.HomeMenu
                });
            } else {
                string animationUrl = "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/tgbot/vworld_start.mp4";
                string ImageUrl = "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/tgbot/VW_Welcome_Bonus_RM28.jpg";
                BaseTelegramUtility.SendMessage(new TelegramMessageParameter
                {
                    ImageUrl = ImageUrl,
                    Message = msg,
                    TargetChatID = _global.TargetChatID,
                    MultiRowButtonList = multiRowButtons,
                    MessageType = MessageType.KeyboardButtons,
                    MessageCategoryTag = MessageCategoryTagEnum.HomeMenu
                });
            }
        }

        private string GetAllWalletBalanceText(VworldWalletBalanceModel model) 
        {
            string msg = "";
            if (model.IsMultipleWallet) {
                msg += i18n("@Msg_MainWalletBalance", model.MainWallet) 
                + Environment.NewLine + i18n("@Msg_GameWalletBalance", model.GameWallet)
                + Environment.NewLine + i18n("@Msg_RollingChipWalletBalance", model.RollingChipWallet);
            } else {
                msg += i18n("@Msg_MainWalletBalance", model.MainWallet)
                + Environment.NewLine + i18n("@Msg_RollingChipWalletBalance", model.RollingChipWallet);
            }
            return msg;
        }
        private void GoStep_SetLanguageAndReturn()
        {
            if (_global.CallbackParsing.LanguageCode == SystemConstant.LanguageCode_ZH)
            {
                TgBotCache().SetCache_LanguageCode(SystemConstant.LanguageCode_ZH);
            }
            else
            {
                TgBotCache().SetCache_LanguageCode(SystemConstant.LanguageCode_EN);
            }

            SetRequestHeaderValue("locale", _global.CallbackParsing.LanguageCode);
            SetGlobalLanguageCode(_global.TargetChatID);

            GoStep_HomePageWithoutLogin_AndResetHistory();
        }


        /// <summary>
        /// 重複最後一次執行的指令
        /// </summary>
        private void GoStep_LastStep_RetryAgain()
        {
            string lastStep = StepHistoryQueueHelper.GetLastStepHistory(_global.TargetChatID);
            GoStepByCallbackAction(lastStep);
        }

        private void GoStep_ToNexStep(string stepAction)
        {
            string nextAction = GetNextOrPreviousAction(stepAction, 1);
            GoStepByCallbackAction(nextAction);
        }


        private void GoStep_ToPreviousStep(string stepAction)
        {
            string nextAction = GetNextOrPreviousAction(stepAction, -1);
            GoStepByCallbackAction(nextAction);
        }


        private void DeleteAllTelegramMessageDesideSomeOfMessage(bool isDeleteNewstMessageID = false, bool isDeleteHomeMenu = false, int delaySeconds = 1000)
        {
            var allHistory = TgMessageApiHistoryQueueHelper.GetAllHistory(_global.TargetChatID);

            if (allHistory.IsEmptyOrNull())
                return;

            int newstMessageID = allHistory.Max(x => x.MessageID);

            Task.Factory.StartNew(() =>
            {
                Thread.Sleep(delaySeconds);

                foreach (var item in allHistory.OrderByDescending(x => x.MessageID))
                {
                    //最新一筆不要清除
                    if (item.MessageID == newstMessageID && !isDeleteNewstMessageID)
                        continue;

                    if (item.MessageCategoryType == (int)MessageCategoryTagEnum.HomeMenu && !isDeleteHomeMenu)
                        continue;

                    //選單的可以不要清除
                    if (item.MessageCategoryType == (int)MessageCategoryTagEnum.DoNotDelete ||
                        item.MessageCategoryType == (int)MessageCategoryTagEnum.MessageAndLink)
                        continue;

                    BaseTelegramUtility.DeleteMessage(_global.TargetChatID, item.MessageID.ToString());
                }
            });
        }

        private void ClearLastTelegramMessageInlineButton() {
            var allHistory = TgMessageApiHistoryQueueHelper.GetAllHistory(_global.TargetChatID);

            var lastMessage = allHistory
                .OrderByDescending(x => x.MessageID)
                .FirstOrDefault();

            if (lastMessage != null)
            {
                BaseTelegramUtility.EditMessage(_global.TargetChatID, lastMessage.MessageID.ToString(), lastMessage.Text);
            }
        }

        /// <summary>
        /// 刪除最後一筆選單
        /// </summary>
        private void DeleteLastMenuMessage()
        {
            var allHistory = TgMessageApiHistoryQueueHelper.GetAllHistory(_global.TargetChatID);

            var lastMenu = allHistory
               .Where(x => x.MessageCategoryType == (int)MessageCategoryTagEnum.HomeMenu)
               .OrderByDescending(x => x.MessageID)
               .FirstOrDefault();

            if (lastMenu != null)
            {
                BaseTelegramUtility.DeleteMessage(_global.TargetChatID, lastMenu.MessageID.ToString());
            }
        }


        private bool CheckInputInfoAndGoNextStep()
        {
            var lastStep = StepHistoryQueueHelper.GetLastStepHistory(_global.TargetChatID);

            var validateResult = ValidateInputTextByActionType(lastStep);

            if (!validateResult.IsValidateOK)
                return false;

            var matchDefineConfig = TgBotCallbackActionDefineHelper.WatingReplyActionDefine.FirstOrDefault(x => x.CurrentAction == lastStep);

            string inputText = validateResult.InputText;

            //若有指定要寫入特定cache
            if (!string.IsNullOrWhiteSpace(matchDefineConfig.CacheKeyPrefix))
            {
                string cacheKey = matchDefineConfig.CacheKeyPrefix + _global.TargetChatID;
                CacheHelper.SetCacheValue(cacheKey, inputText, new TimeSpan(9999, 0, 0, 0));
            }

            GoStep_ToNexStep(lastStep);
            return true;
        }


        private (bool IsValidateOK, string InputText) ValidateInputTextByActionType(string action)
        {
            var model = _global.WeHookRquest;

            string inputText = _global.InputText;

            var matchDefineConfig = TgBotCallbackActionDefineHelper.WatingReplyActionDefine.FirstOrDefault(x => x.CurrentAction == action);

            if (matchDefineConfig == null)
            {
                SendErrorMessageAndDelay($"ValidateInputTextByActionType 無定義。{action}");
            }

            var validateRules = matchDefineConfig.ValidateRules;

            if (validateRules.IsEmptyOrNull())
                return (true, inputText);

            foreach (var valid in validateRules)
            {
                switch (valid)
                {
                    case ValidateTypeEnum.ValidateAccountFormat:
                        {
                            if (string.IsNullOrWhiteSpace(inputText) || inputText.Length <= 3)
                            {
                                SendErrorMessageAndDelay(i18n("@Validate_IncorrectFormat"));
                                GoStep_LastStep_RetryAgain();
                                return (false, inputText);
                            }

                            break;
                        }

                    case ValidateTypeEnum.ValidateLength3TextFormat:
                        {
                            if (string.IsNullOrWhiteSpace(inputText) || inputText.Length <= 3)
                            {
                                SendErrorMessageAndDelay(i18n("@Validate_IncorrectFormat"));
                                GoStep_LastStep_RetryAgain();
                                return (false, inputText);
                            }

                            break;
                        }

                    case ValidateTypeEnum.ValidatePhoneFormat:
                        {
                            inputText = string.Empty;

                            //手機欄位從比較特別的物件拿到
                            if (model.message != null && model.message.contact != null && model.message.contact.user_id == _global.TargetChatID)
                            {
                                inputText = model.message.contact.phone_number;
                            }

                            if (string.IsNullOrWhiteSpace(inputText) || inputText.Length <= 5)
                            {
                                SendErrorMessageAndDelay(i18n("@Validate_IncorrectFormat"));
                                GoStep_ToPreviousStep(action);
                                return (false, inputText);
                            }

                            break;
                        }

                    case ValidateTypeEnum.ValidatePasswordFormat:
                        {
                            if (string.IsNullOrWhiteSpace(inputText) || inputText.Length <= 4)
                            {
                                SendErrorMessageAndDelay(i18n("@Validate_IncorrectFormat"));
                                GoStep_LastStep_RetryAgain();
                                return (false, inputText);
                            }
                            break;
                        }

                    case ValidateTypeEnum.ValidateAmountFormat:
                        {
                            if (string.IsNullOrWhiteSpace(inputText) || !inputText.IsNumeric())
                            {
                                SendErrorMessageAndDelay(i18n("@Validate_IncorrectFormat"));
                                GoStep_LastStep_RetryAgain();
                                return (false, inputText);
                            }

                            decimal parseNumber = decimal.Parse(inputText);

                            if (parseNumber <= 0)
                            {
                                SendErrorMessageAndDelay(i18n("@Validate_IncorrectFormat"));
                                GoStep_LastStep_RetryAgain();
                                return (false, inputText);
                            }

                            break;
                        }

                    //case ValidateTypeEnum.ValidateMinMaxNumberValue:
                    //    {
                    //        decimal myValue = decimal.Parse(inputText);
                    //        decimal min = 100;
                    //        decimal max = 10000;

                    //        if (myValue < min || myValue > max)
                    //        {
                    //            SendErrorMessageAndDelay(i18n("@Validate_NumberValueRange", min, max));
                    //            GoStep_LastStep_RetryAgain();
                    //            return (false, inputText);
                    //        }
                    //        break;
                    //    }

                    default:
                        {
                            SendErrorMessageAndDelay($"{valid.ToString()} 未定義。(ValidateInputTextByActionType)");
                            break;
                        }
                }

            }

            return (true, inputText);
        }




        private string GetNextOrPreviousAction(string action, int offset = 1)
        {
            if (!TgBotCallbackActionDefineHelper.StepSequenceDefine.Any(x => x == action))
            {
                SendErrorMessageAndDelay($"GetNextStepByAction 找不到定義的步驟 {action}");
            }

            var matchIndex = TgBotCallbackActionDefineHelper.StepSequenceDefine.IndexOf(action);
            return TgBotCallbackActionDefineHelper.StepSequenceDefine[matchIndex + offset];
        }


        /// <summary>
        /// 判斷前一步是否為Menu
        /// </summary>
        private bool IsComeFromMenu(string action)
        {
            try
            {
                if (action == TgBotCallbackAction.HomePage ||
                   action == TgBotCallbackAction.HomePageWithoutLogin)
                {
                    return false;
                }

                if (!TgBotCallbackActionDefineHelper.StepSequenceDefine.Any(z => z == action))
                {
                    return false;
                }

                var matchIndex = TgBotCallbackActionDefineHelper.StepSequenceDefine.IndexOf(action);

                var previousStep = TgBotCallbackActionDefineHelper.StepSequenceDefine[matchIndex - 1];

                if (previousStep == TgBotCallbackAction.HomePage ||
                    previousStep == TgBotCallbackAction.HomePageWithoutLogin)
                {
                    return true;
                }
            }
            catch
            {

            }

            return false;
        }



        private void SetGlobalChatIdAndInstance(string chatID)
        {
            if (string.IsNullOrWhiteSpace(chatID))
                return;

            _global.TargetChatID = chatID;
            _global.InputText = (_global.WeHookRquest?.message?.text ?? "").Trim();

            SetGlobalLanguageCode(chatID);

            VworldPlatformApiService = new VworldPlatformApiService(chatID, this._global.LanguageCode, this._global.BotID);
        }



        private bool IsWaitingReplyStep()
        {
            var lastStep = StepHistoryQueueHelper.GetLastStepHistory(_global.TargetChatID);

            if (TgBotCallbackActionDefineHelper.WatingReplyActionDefine.Select(x => x.CurrentAction).Any(z => z == lastStep))
            {
                return true;
            }

            return false;
        }


        private ButtonList InstanceButtonWithi18nKey(string i18nKey, string callbackAction, string url = null)
        {
                return new ButtonList(i18n(i18nKey), callbackAction, url);
        }

    }


}
