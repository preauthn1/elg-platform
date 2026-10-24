using Lck.Utility.Extensions.Helper;
using Microsoft.AspNetCore.Mvc;
using Microsoft.Extensions.Logging;
using target.Utility;
using target.Utility.Helper.HttpContext;
using target.Utility.Utility.ThirdParty;
using System;
using System.Collections.Generic;
using example.invalid;
using System.Linq;
using System.Text;
using System.Threading;
using System.Threading.Tasks;
using TG_Hook_API.Models;
using TG_Hook_API.Service;

namespace WebApplication3_20220202_003.Controllers
{

    [ApiController]
    public partial class TGBotController : ControllerBase
    {

        private string DebugPrefix = ""; //勿刪


        /// <summary>
        /// botID對應到config配置 (請參考 BotMappingSettingHelper.cs)
        /// </summary>
        [Route("TGBot", Order = 998)]
        [Route("TGBot/v2/{botID}", Order = 997)]
        public object CatchAllRequest([FromRoute]string botID, [FromBody] object myModel, [FromForm] string content2)
        {
            string debug = string.Empty;

            var defaultResult = new
            {
                ID = 1,
                Name = debug
            };

            TgWebhookParsingResult parseResult = ParsingJsonToModel(myModel);

            if (IsTrashMessage(parseResult))
                return defaultResult;

            SendDebugMessage(parseResult);

            TgWebhookRequest model = parseResult.ParsingModel;

            parseResult.TempInfo_ChatID = GetChatID(model);
            parseResult.TempInfo_BotID = botID;

            //臨時debug用
            string inputText = parseResult?.ParsingModel?.message?.text;
            if (inputText == "/speed")
            {
                MyTelegramUtility.SendMessageToChannel(new TelegramMessageParameter
                {
                    TargetChatID = parseResult.TempInfo_ChatID,
                    Message = "hello , TG_Hook_API.Europe"
                });

                return defaultResult;
            }


            string cacheKey = CacheKey.CocurrentLocker(parseResult.TempInfo_ChatID);

            //傳訊息不得太快。會lock住直到釋放
            var cocurrentLocker = CacheHelper.GetCacheValue<string>(cacheKey);

            if (!string.IsNullOrWhiteSpace(cocurrentLocker))
            {
                return defaultResult;
            }

            CacheHelper.SetCacheValue(cacheKey, "MyLocker", new TimeSpan(0, 0, 15));

            bool isAsync = true; //建議true

            if (isAsync)
            {
                //用非同步是為了讓locker機制運作有效 (假如1秒內連發三則訊息...)
                Task.Factory.StartNew(() =>
                {
                    DoTgBotProcess(parseResult);

                    //釋放locker (故意延遲幾秒才刪除)
                    Thread.Sleep(500);
                    CacheHelper.RemoveCacheByKey(cacheKey);
                });
            }
            else
            {
                //同步
                DoTgBotProcess(parseResult);
            }

            return defaultResult;
        }


        //private void GetBrandConfigValue(string brandName)
        //{
        //    var value1 = BaseSettingHelper.GetSettingValue($"{brandName}:PlatformName");
        //    var value2 = BaseSettingHelper.GetSettingValue($"{brandName}:AgentID");
        //    return;
        //}


        private void DoTgBotProcess(TgWebhookParsingResult parseResult)
        {
            try
            {
                var myService = new VworldTgBotService(parseResult.TempInfo_BotID);
                myService.GenerateTgMessages(parseResult);
            }
            catch (Exception ex)
            {
                string myMsg = $"{DebugPrefix} , 錯誤啦 {ex.ToString()} ";

                MySlackUtility.SendMessageToChannelAsync(new SlackMessageParameter
                {
                    Channel = SlackChannelEnum.n_tgbot_notify,
                    Message = myMsg
                });
            }
        }



        private string GetChatID(TgWebhookRequest model)
        {
            string chatID = model.message?.chat?.id;

            if (string.IsNullOrWhiteSpace(chatID))
                return chatID;

            return model.callback_query?.message?.chat.id;
        }


        private void SendDebugMessage(TgWebhookParsingResult parseResult)
        {
            var model = parseResult.ParsingModel;

            string targetChatID = model.message?.chat?.id;

            string prefixText = model.callback_query != null ? "觸發 callback_query 了" : "";

            string triggerTime = DateTime.UtcNow.AddHours(8).ToString("yyyyMMddHHmmssfff");

            string msg = $"API：{prefixText} {triggerTime} {HttpContext.Request.Path}、{HttpContext.Request.Method}、chat:{targetChatID}、body：{parseResult.RawJson}";

            DebugPrefix = $" chatID: {targetChatID} , {parseResult?.ParsingModel?.message?.message_id} ";

            if(!SettingHelper.IsEnableSlackDebugLog)
            {
                return;
            }

            MySlackUtility.SendMessageToChannelAsync(new SlackMessageParameter
            {
                Channel = SlackChannelEnum.n_tgbot_notify,
                Message = msg
            });
        }


        /// <summary>
        /// 判斷是否為垃圾廣告訊息
        /// </summary>
        private bool IsTrashMessage(TgWebhookParsingResult parseResult)
        {
            string msg = parseResult.ParsingModel?.message?.text ?? "";

            if (string.IsNullOrWhiteSpace(msg))
                return false;

            if (msg.Contains("example.invalid"))
                return true;

            if (msg.Contains("https://"))
                return true;

            return false;
        }


        private TgWebhookParsingResult ParsingJsonToModel(object model)
        {
            string rawJson = string.Empty;

            try
            {
                rawJson = Newtonsoft.Json.JsonConvert.SerializeObject(model);
                var convert = Newtonsoft.Json.JsonConvert.DeserializeObject<TgWebhookRequest>(rawJson);

                return new TgWebhookParsingResult
                {
                    RawJson = rawJson,
                    ParsingModel = convert
                };
            }
            catch
            {


            }

            return new TgWebhookParsingResult
            {
                RawJson = rawJson,
                ParsingModel = null
            };
        }


    }
}
