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
    public class MySlackUtility
    {

        /// <summary>
        /// 取得config設定的slack token
        /// </summary>
        /// <returns></returns>
        private static string GetConfigTokenSetting()
        {
            string configSetting = GetConfigValueByKey("SlackToken");

            if (!string.IsNullOrWhiteSpace(configSetting))
                return configSetting;

            //如果擔心某些系統忘了設config 強制指定預設值
            return "xox-[REDACTED]";
        }



        public static void SendMessageToChannelAsync(SlackMessageParameter parameter)
        {
            //非同步
            Task.Factory.StartNew(() =>
                {
                    SendMessage(parameter);
                });
        }

        public static void SendMessageToChannelNotAsync(SlackMessageParameter parameter)
        {
            //同步
            SendMessage(parameter);
        }


        private static void SendMessage(SlackMessageParameter parameter)
        {
            string token = [REDACTED];            
            string prefixText = GetPrefixText();

            var newService = new BaseSlackUtility(token, prefixText);
            newService.SendMessage(parameter);
        }


        private static string GetPrefixText()
        {
            string siteName = GetConfigValueByKey("WebSiteName");

            //可根據專案客製需求，不同環境要帶不同的前綴文字 提供識別
            if (IsDev())
                return $"【Debug環境】{siteName} ";

            return $" `{siteName}` ";
        }


        private static string GetConfigValueByKey(string keyName)
        {
            return "";
        }


        private static bool IsDev()
        {
#if DEBUG
            return true;
#else
            return false;
#endif
        }



    }
}
