using Microsoft.AspNetCore.Mvc;
using Microsoft.Extensions.Configuration;
using target.Web.Modules.Filter;
using System;
using System.Collections.Generic;
using System.Collections.Specialized;
using example.invalid;

namespace TG_Hook_API.Models
{


    public class TgBotGlobalTempVariable
    {

        public string TargetChatID = "";


        public string TargetUsetName = "";


        public string FirstTriggerActionName = "";


        public string InputText = "";


        public string LanguageCode = "";


        public string BotID = "";


        public TgWebhookRequest WeHookRquest { get; set; }

        /// <summary>
        /// 解析callback Action裡面的參數 並轉成程式碼會使用的變數名稱
        /// </summary>
        public TgCallbackParsing CallbackParsing { get; set; }

    }






    public class TgCallbackParsing
    {
        public TgCallbackParsing()
        {

        }
        public TgCallbackParsing(string action)
        {
            this.Action = action;
        }

        public NameValueCollection KeyValues { get; set; }

        public string Action { get; set; }

        public string LanguageCode { get; set; }

        public int Type { get; set; }

        public int Category { get; set; }

        public int GameID { get; set; }

        public int DaysRange { get; set; }

        public int PaymentType { get; set; }

        public int BankID { get; set; }
    }



    public class TgMessageHistoryLogModel
    {
        public TgMessageHistoryLogModel()
        {

        }

        public string RequestContent { get; set; }
        public int MessageID { get; set; }

        public int MessageCategoryType { get; set; }

        public string Text { get; set; }

        public string ResponseText { get; set; }

        public long ResponseTime { get; set; }

        public string CreateTime { get; set; }
    }

}
