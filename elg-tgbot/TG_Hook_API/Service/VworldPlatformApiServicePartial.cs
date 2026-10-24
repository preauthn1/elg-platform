using Microsoft.AspNetCore.Http;
using Microsoft.AspNetCore.Mvc;
using Microsoft.Extensions.Logging;
using target.Utility.Utility;
using target.Utility.Utility.ThirdParty;
using System;
using System.Collections.Generic;
using example.invalid;
using System.Linq;
using System.Threading;
using System.Threading.Tasks;
using TG_Hook_API.Helper;
using TG_Hook_API.Models;
using target.Utility.Extensions;
using System.Text;
using System.Security.Cryptography;
using target.Utility;
using target.Utility.Helper.HttpContext;

namespace TG_Hook_API.Service
{

    public partial class VworldPlatformApiService
    {

        private string EncryptByAES(string jsonText)
        {
            SHA256CryptoServiceProvider sha256 = new SHA256CryptoServiceProvider();
            byte[] keyArray = UTF8Encoding.UTF8.GetBytes(MD5Hash(API_AES_KEY));
            byte[] iv = UTF8Encoding.UTF8.GetBytes(API_IV);
            byte[] toEncryptArray = UTF8Encoding.UTF8.GetBytes(jsonText);

            RijndaelManaged rDel = new RijndaelManaged();
            rDel.KeySize = 256;
            rDel.Key = keyArray;
            rDel.IV = iv;
            rDel.Mode = CipherMode.CBC;
            rDel.Padding = PaddingMode.PKCS7;

            ICryptoTransform cTransform = rDel.CreateEncryptor();
            byte[] byteArray = cTransform.TransformFinalBlock(toEncryptArray, 0, toEncryptArray.Length);

            return Convert.ToBase64String(byteArray);
        }


        public string MD5Hash(string source)
        {
            // Convert the input string to a byte array and compute the hash.
            byte[] data = null;
            data = Encoding.UTF8.GetBytes(source);
            return string.Join("", MD5.Create().ComputeHash(data).Select(s => s.ToString("x2")));
        }


        public string Base64Encode(string str)
        {
            byte[] bytes = System.Text.Encoding.GetEncoding("utf-8").GetBytes(str);
            string result = Convert.ToBase64String(bytes);
            return result;
        }


        private void CheckApiIsErrorResponse<T>(ApiRequestResultModel<VworldApiCommonResponse<T>> apiResult, bool isThrowException = true, params int[] ignoreErrorCodes)
        {
            string apiUrl = apiResult.DebugInfo.RequestParameters.ApiUrl ?? "";
            apiUrl = apiUrl.Replace("api/", "").Replace("tgBot", "").Replace("/", "");

            string errorMsg = string.Empty;

            if (apiResult.IsTimeoutException)
            {
                errorMsg = $"{apiUrl} Api失敗 , TimeoutException";
            }

            if (apiResult.Exception != null)
            {
                errorMsg = $"{apiUrl} Api失敗 , {apiResult.Exception.Message} {apiResult.ResponseText}";
            }

            if (apiResult.ResultObject == null)
            {
                errorMsg = $"{apiUrl} Api失敗 , ResultObject null。{apiResult.ResponseText}";
            }

            if (apiResult.ResultObject != null && apiResult.ResultObject.Code != 1000)
            {
                if (ignoreErrorCodes != null && ignoreErrorCodes.Count() > 0)
                {
                    if (ignoreErrorCodes.Contains(apiResult.ResultObject.Code))
                    {
                        return;
                    }
                }

                string extraInfo = string.Empty;

                if(SettingHelper.IsDev)
                {
                    extraInfo = $" (code: {apiResult.ResultObject.Code} , `{apiUrl}` )";
                }

                errorMsg = $"{i18n("@Error_Msg_OperateFail_PleaseRetry")}{Environment.NewLine}{apiResult.ResultObject.Message}" + extraInfo;
            }

            if (!string.IsNullOrWhiteSpace(errorMsg) && SettingHelper.IsDev)
            {
                SendErrorMessageAndThrow(errorMsg.Truncate(300) , isThrowException);
            }
        }

        private void CheckResultDataObjectNotNull<T>(ApiRequestResultModel<VworldApiCommonResponse<T>> apiResult, params int[] ignoreErrorCodes)
        {
            if (apiResult.ResultObject.Data == null)
            {
                SendErrorMessageAndThrow($"Data 物件為空。{apiResult.ResponseText}");
            }
        }


        private void SendErrorMessageAndThrow(string errorMsg , bool isThrowException = true)
        {
            if (string.IsNullOrWhiteSpace(errorMsg))
                return;

            BaseTelegramUtility.SendMessage(new TelegramMessageParameter
            {
                TargetChatID = targetChatID,
                Message = "❌ " + errorMsg
            });

            Thread.Sleep(500);

            if(isThrowException)
            {
                throw new Exception($"{targetChatID} 錯誤啦" + errorMsg);
            }
        }


        private RequestParameters InstanceApiParameter(string url, string category = "")
        {
            if (string.IsNullOrEmpty(category))
                category = url;

            var param = new RequestParameters
            {
                BaseUrl = API_URL,
                ApiUrl = "api/" + url,
                Method = HttpMethodType.HttpPost,
                LogAction = WriteLog,
                AdvancedSetting = new AdvancedSetting
                {
                    IsIgnoreJsonParsingColumnError = true,
                    Log_ApiCategory = category
                }
            };

            param.AddHeaderData("accept-language", LanguageCode);
            param.AddHeaderData("x-bot-tgID", targetChatID);

            string tgBotUUID = BotMappingSettingHelper.GetUUIDByBotID(targetBotID);
            param.AddHeaderData("x-bot-ID", tgBotUUID.ToLower());            
            return param;
        }


        private void WriteLog(ApiRequestLogTemporaryInfoModel LogAction)
        {
            if(LogAction.Response !=  null && !string.IsNullOrWhiteSpace(LogAction.Response.ResponseContent))
            {
                LogAction.Response.ResponseContent = LogAction.Response.ResponseContent.Truncate(500);
            }

            var myHeaders = LogAction.RequestParameters.Headers;

            Task.Factory.StartNew(() =>
            {
                VworldApiHistoryQueueHelper.AppendToQueue(targetChatID, new
                {
                    ChatID = targetChatID,
                    TGBotIdendity = targetBotID,
                    BaseUrl = LogAction.RequestParameters.BaseUrl,
                    ApiUrl = LogAction.RequestParameters.ApiUrl,
                    FullUrl = (LogAction.RequestParameters.BaseUrl + "/" + LogAction.RequestParameters.ApiUrl).ReplaceDuplicateUrlChar(),
                    RequestStartTime = LogAction.RequestStartTime.AddHours(8).ToString("yyyy-MM-dd HH:mm:ss"),
                    RequestContent = LogAction.RequestContent,
                    Headers = myHeaders,
                    ApiMessage = TryParseCommonApiMessage(LogAction.Response.ResponseContent),
                    Response = LogAction.Response,
                    ResponseTime = (LogAction.Response.ResponseTime),
                });
            });
        }

        private string TryParseCommonApiMessage(string rawResponseText)
        {
            try
            {
                return rawResponseText.JsonConvertToModel<VworldApiCommonResponse<object>>()?.Message;
            }
            catch
            {

            }

            return string.Empty;
        }





        private string i18n(string key)
        {
            return TG_Hook_API.i18n.Text(key , LanguageCode , null);
        }


    }

}

