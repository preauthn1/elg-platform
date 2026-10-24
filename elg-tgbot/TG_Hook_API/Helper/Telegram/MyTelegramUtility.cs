using System;
using System.Collections.Generic;
using System.Linq;
using System.Text;
using System.Threading.Tasks;
using System.Configuration;
using example.invalid;
using Microsoft.Extensions.Configuration;

namespace target.Utility.Utility.ThirdParty
{
    public class MyTelegramUtility
    {

        /// <summary>
        /// 取得config設定的slack token
        /// </summary>
        /// <returns></returns>
        private static string GetConfigTokenSetting()
        {
            string configSetting = SettingHelper.TgBotToken;

            if (!string.IsNullOrWhiteSpace(configSetting))
                return configSetting;

            //設定WebHook 可以透過  https://example.invalid/bot{my_bot_token}/setWebhook?url=
            //查看TG cahtID 可以透過  https://example.invalid/bot{pgs_token}/getUpdates
            return "[TG_BOT_TOKEN_REDACTED]";
        }


        public static void SendMessageToChannel(TelegramMessageParameter parameter)
        {
            MySendMessage(parameter);
        }

        public static void SendMessageToChannelAsync2(TelegramMessageParameter parameter)
        {
            //非同步
            Task.Factory.StartNew(() =>
            {
                MySendMessage(parameter);
            });
        }


        private static void MySendMessage(TelegramMessageParameter parameter)
        {
            string token = [REDACTED];
            string prefixText = GetPrefixText();

            var newService = new BaseTelegramUtility(token, prefixText);
            newService.SendMessage(parameter);
        }


        public static void DeleteMessage(string chatID, string messageID)
        {
            string token = [REDACTED];
            string prefixText = GetPrefixText();

            var newService = new BaseTelegramUtility(token, prefixText);
            newService.DeleteMessage(chatID, messageID);
        }


        public static void DeleteMessageAsync(string chatID, string messageID)
        {
            Task.Factory.StartNew(() =>
            {
                DeleteMessage(chatID, messageID);
            });
        }


        private static string GetPrefixText()
        {
            string siteName = SettingHelper.WebSiteName;

            //可根據專案客製需求，不同環境要帶不同的前綴文字 提供識別
            if (IsDev())
                return $"【Debug環境】{siteName} ";

            return $" `{siteName}` ";
        }



        private static bool IsDev()
        {
            return SettingHelper.IsDev;
        }



    }
}
