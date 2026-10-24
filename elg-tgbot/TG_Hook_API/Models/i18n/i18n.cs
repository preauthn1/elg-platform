using Lck.Utility.Extensions.Helper;
using Microsoft.AspNetCore.Mvc;
using Microsoft.Extensions.Configuration;
using target.Utility;
using target.Utility.Extensions;
using target.Utility.Helper.HttpContext;
using target.Web.Modules.Filter;
using System;
using System.Collections.Generic;
using example.invalid;
using System.Linq;

namespace TG_Hook_API
{
    public class i18n
    {
        private static Dictionary<string, Dictionary<string, string>> LanguageText = new Dictionary<string, Dictionary<string, string>>();


        public static void ImportLanguageText(string langCode, Dictionary<string, string> langText)
        {
            LanguageText.Add(langCode, langText);
        }


        public static string Text(string key)
        {
            return Text(key, "", null);
        }

        public static string Text(string key, params object[] myParams)
        {
            return Text(key, "", null);
        }


        public static string Text(string key, string langCode, params object[] myParams)
        {
            if (string.IsNullOrWhiteSpace(key))
                return "";

            //沒傳@符號。則代表直接顯示純文字
            if (key.First() != '@')
                return key;

            key = key.Substring(1, key.Length - 1);

            if (string.IsNullOrWhiteSpace(langCode))
            {
                langCode = GetMyLanguageCodeFromHttpHeader();
            }

            var matchDic = LanguageText[langCode];

            List<string> paramsList = new List<string>();

            if (myParams.IsNotEmptyNull())
            {
                paramsList = myParams.Select(x => (x ?? "").ToString()).ToList();
            }

            try
            {
                if (matchDic.ContainsKey(key))
                {
                    string text = matchDic[key];
                    return string.Format(text, paramsList.ToArray());
                }
                else
                {
                    return $"Unknow_{langCode}_{key}";
                }
            }
            catch
            {

            }

            //出現此錯誤 可能原因為 該多語系要帶參數1、參數2  而你忘記傳了
            return $"ErrorKey_{langCode}_{key}";
        }


        private static string GetMyLanguageCodeFromHttpHeader()
        {
            try
            {
                return MyHttpContext.Current.Request.Headers["locale"];
            }
            catch
            {

            }

            return SettingHelper.DefaultLanguageCode;
        }


    }

}
