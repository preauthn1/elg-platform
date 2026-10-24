using Lck.Utility.Extensions.Helper;
using Microsoft.AspNetCore.Http;
using Microsoft.AspNetCore.Mvc;
using Microsoft.Extensions.Logging;
using target.Utility;
using target.Utility.Helper.HttpContext;
using target.Utility.Utility.ThirdParty;
using System;
using System.Collections.Generic;
using System.Collections.Specialized;
using example.invalid;
using System.Linq;
using System.Text.RegularExpressions;
using System.Threading;
using System.Threading.Tasks;
using TG_Hook_API.Helper;
using TG_Hook_API.Models;

namespace TG_Hook_API.Service
{

    public partial class VworldTgBotService
    {
       
        private void ReadMe()
        {
            return;

            // 若要查看每個action執行的動作 請參考
            GoStepByCallbackAction("test");

            //定義每個步驟檢查項目
            var debug1 = TgBotCallbackActionDefineHelper.StepSequenceDefine;
        }



        private TgBotCache TgBotCache()
        {
            return new TgBotCache(_global.TargetChatID);
        }


        protected string i18n(string key, params object[] myParams)
        {
            string langCode = this._global.LanguageCode;
            return TG_Hook_API.i18n.Text(key , langCode, myParams);
        }


        private void CheckRedisCacheValueNotExpiredOrEmptyNull(string myCacheValue)
        {
            if (string.IsNullOrWhiteSpace(myCacheValue))
            {
                SendErrorMessageAndDelay("@Error_Msg_ProcessDataExpired");
                ShowMenuByAccountExistOrNot(isLoginAgain: false);
                throw new Exception(i18n("@Error_Msg_ProcessDataExpired"));
            }
        }


        private void SetGlobalLanguageCode(string myChatID)
        {
            if (string.IsNullOrWhiteSpace(myChatID))
                return;

            string myLanguageCode = GetCurrentLanguage();

            this._global.LanguageCode = myLanguageCode;
            SetRequestHeaderValue("locale" , myLanguageCode);
        }


        private string GetCurrentLanguage()
        {
            var cacheKey = CacheKey.SelectLanguage(_global.TargetChatID);
            string myLanguageCode = CacheHelper.GetCacheValue<string>(cacheKey);

            if (string.IsNullOrWhiteSpace(myLanguageCode))
            {
                //TG使用者語系
                var tgLanguageCode = _global.WeHookRquest?.message?.from?.language_code ?? "";

                if(string.IsNullOrWhiteSpace(tgLanguageCode))
                {
                    tgLanguageCode = _global.WeHookRquest?.callback_query?.from?.language_code ?? "";
                }

                if(!string.IsNullOrWhiteSpace(tgLanguageCode) && tgLanguageCode.Contains("zh-"))
                {
                    myLanguageCode = SystemConstant.LanguageCode_ZH;
                }
                else
                {
                    myLanguageCode = SettingHelper.DefaultLanguageCode;
                }
            }

            return myLanguageCode;
        }


        private void SetRequestHeaderValue(string key , string myLanguageCode)
        {
            try
            {
                if (MyHttpContext.Current == null)
                    return;

                var myValue = MyHttpContext.Current.Request.Headers[key];

                if(string.IsNullOrWhiteSpace(myValue))
                {
                    MyHttpContext.Current.Request.Headers.Add(key , myLanguageCode);
                }
                else
                {
                    MyHttpContext.Current.Request.Headers[key] = myLanguageCode;
                }
            }
            catch
            {

            }
        }



        private void SendMessageAndDelay(string msg, int delayMilliseconds = 1000)
        {
            var defaultTemplateMsg = new TelegramMessageParameter
            {
                Message = msg,
                TargetChatID = _global.TargetChatID,
            };

            BaseTelegramUtility.SendMessage(defaultTemplateMsg);
            Thread.Sleep(delayMilliseconds);
        }


        private void SendErrorMessageAndDelay(string msgOrKey, int delayMilliseconds = 1000)
        {
            var defaultTemplateMsg = new TelegramMessageParameter
            {
                Message = "❌ " + i18n(msgOrKey),
                TargetChatID = _global.TargetChatID,
            };

            BaseTelegramUtility.SendMessage(defaultTemplateMsg);
            Thread.Sleep(delayMilliseconds);
        }


        private TgCallbackParsing ParsingCallbackActionAndSetCache(string callbackValue)
        {
            if (string.IsNullOrWhiteSpace(callbackValue))
                return new TgCallbackParsing(callbackValue);

            if (!callbackValue.Contains("&"))
                return new TgCallbackParsing(callbackValue);

            NameValueCollection queryString = System.Web.HttpUtility.ParseQueryString(callbackValue);
            var parsing = new TgCallbackParsing
            {
                Action = queryString.Get("action"),
                LanguageCode = queryString.Get("languageCode"),
                KeyValues = queryString
            };

            var languageCode = queryString.Get(TgBotCallbackExtraParameter.LanguageCode);
            var paymentType = queryString.Get(TgBotCallbackExtraParameter.PaymentType);
            var bankID = queryString.Get(TgBotCallbackExtraParameter.BankID);
            var gameID = queryString.Get(TgBotCallbackExtraParameter.GameID);
            var category = queryString.Get(TgBotCallbackExtraParameter.Category);
            var daysRange = queryString.Get(TgBotCallbackExtraParameter.DaysRange);

            //只有一步驟的選項不需要存cache
            if (!string.IsNullOrWhiteSpace(languageCode))
            {
                parsing.LanguageCode = languageCode;
            }
            if (!string.IsNullOrWhiteSpace(daysRange))
            {
                parsing.DaysRange = Int32.Parse(daysRange);
            }
            if (!string.IsNullOrWhiteSpace(gameID))
            {
                parsing.GameID = Int32.Parse(gameID);
            }
            if (!string.IsNullOrWhiteSpace(category))
            {
                parsing.Category = Int32.Parse(category);
            }

            //步驟比較複雜(可能會填錯要求重填的)
            if (!string.IsNullOrWhiteSpace(paymentType))
            {
                parsing.PaymentType = Int32.Parse(paymentType);
                TgBotCache().SetCache_SelectPaymentType(parsing.PaymentType);
            }

            if (!string.IsNullOrWhiteSpace(bankID))
            {
                parsing.BankID = Int32.Parse(bankID);
                TgBotCache().SetCache_Withdrawal_SelectWithdrawalBankID(parsing.BankID);
            }

            return parsing;
        }


        private bool IsEquali18nText(string inputText, string targeti18nKeys)
        {
            var word1 = TG_Hook_API.i18n.Text(targeti18nKeys, SystemConstant.LanguageCode_EN);
            var word2 = TG_Hook_API.i18n.Text(targeti18nKeys, SystemConstant.LanguageCode_ZH);

            if (inputText == word1 || inputText == word2)
            {
                return true;
            }

            return false;
        }


        private (bool IsMatch, string MatchAction) IsMatchCallbackActionByText(string inputText)
        {
            //inputText = Regex.Replace(inputText, @"\p{Cs}", "");
            Dictionary<string, string> i18nMappingAction = new Dictionary<string, string>();
            i18nMappingAction.Add("@Label_Deposit", TgBotCallbackAction.Deposit_ShowAvailablePaymentTypes);
            i18nMappingAction.Add("@Label_Withdrawal", TgBotCallbackAction.Withdrwal_Process);
            i18nMappingAction.Add("@Label_SelectGames", TgBotCallbackAction.Games_ShowAvailableGameCategory);
            i18nMappingAction.Add("@Label_DepositRecords", TgBotCallbackAction.Records_ShowDaysRangeFilter);
            i18nMappingAction.Add("@Label_LogoutAndReturn", TgBotCallbackAction.LogoutAndBack);
            i18nMappingAction.Add("@Label_CS", TgBotCallbackAction.GetCsLink);
            MySlackUtility.SendMessageToChannelAsync(new SlackMessageParameter
            {
                Channel = SlackChannelEnum.n_tgbot_notify,
                Message = inputText + " as inputText "
            });
            
            foreach (string key in i18nMappingAction.Keys)
            {
                bool isMatch = IsEquali18nText(inputText, key);

                if (isMatch)
                {
                    return (true, i18nMappingAction[key]);
                }
            }

            return (false, "");
        }


    }
}
