using System;
using System.Collections.Generic;
using System.Linq;
using System.Text;
using System.Threading.Tasks;
using System.Configuration;
using System.ComponentModel;
using TG_Hook_API.Helper;
using TG_Hook_API.Models;
using target.Utility.Extensions;

namespace target.Utility.Utility.ThirdParty
{
    /// <summary>
    /// 基底的 BaseSlackUtility 基本上不會再改到
    /// 有需要根據公司邏輯 特別客製的，請修正 MySlackUtility
    /// </summary>
    internal class BaseTelegramUtility
    {

        private string _token;
        private string _prefixText;
        

        public BaseTelegramUtility(string token , string prefix = "")
        {
            _token = [REDACTED];
            _prefixText = prefix;
        }

        
        public void SendMessage(TelegramMessageParameter parameter)
        {
            bool isSendPhoto = !string.IsNullOrWhiteSpace(parameter.ImageUrl);
            bool isSendAnimation = !string.IsNullOrWhiteSpace(parameter.AnimationUrl);

            if ( !(isSendPhoto || isSendAnimation) && string.IsNullOrWhiteSpace(parameter.Message))
                return;

            if (string.IsNullOrWhiteSpace(parameter.TargetChatID))
            {
                parameter.TargetChatID = "1242563509";
            }
            if (!parameter.Message.IsEmptyOrNull())
            {
                parameter.Message =parameter.Message.Replace("#", "＃");
            }
            

            string chatID = parameter.TargetChatID;

            string msg = parameter.Message;

            parameter.AdvanceSetting = parameter.AdvanceSetting ?? new TelegramMessageAdvanceSetting();

            string apiUrl = "sendMessage";

            if (isSendPhoto)
            {
                apiUrl = "sendPhoto";
            }
            else if (isSendAnimation)
            {
                apiUrl = "sendAnimation";
            }
            else if(!string.IsNullOrWhiteSpace(parameter.AdvanceSetting.UpdateMessageID))
            {
                apiUrl = "editMessageText";
            }

            var para = new RequestParameters
            {
                BaseUrl = "https://example.invalid/",
                ApiUrl = $"bot{_token}/{apiUrl}",
                Method = HttpMethodType.HttpPost,
                TimeoutMillisecond = 10000,
            };

            var inline_keyboard = new List<List<ButtonList>>();

            if (parameter.ButtonList != null && parameter.ButtonList.Count() > 0)
            {
                inline_keyboard.Add(parameter.ButtonList);
            }
            else if(parameter.MultiRowButtonList != null && parameter.MultiRowButtonList.Count() > 0)
            {
                inline_keyboard = parameter.MultiRowButtonList;
            }

            object body = null;
           
            if (parameter.MessageType == MessageType.RequestPhoneNumber)
            {
                List<object> keyboards = new List<object>();

                body = new
                {
                    chat_id = chatID,
                    text = parameter.Message,
                    reply_markup = new
                    {
                        keyboard = inline_keyboard,
                        one_time_keyboard = true,
                        remove_keyboard = true,  
                    }
                };
            }
            else if (isSendPhoto)
            {
                List<object> keyboards = new List<object>();
                
                body = new
                {
                    photo = parameter.ImageUrl,
                    caption = parameter.Message,
                    chat_id = chatID,
                    reply_markup = new
                    {
                        inline_keyboard = inline_keyboard,
                    }
                };

                if (parameter.Message.IsNullOrWhiteSpace()) {
                    body = new
                    {
                        photo = parameter.ImageUrl,
                        chat_id = chatID,
                        reply_markup = new
                        {
                            inline_keyboard = inline_keyboard,
                        }
                    };
                }
            }
            else if (isSendAnimation)
            {
                List<object> keyboards = new List<object>();

                body = new
                {
                    animation = parameter.AnimationUrl,
                    caption = parameter.Message,
                    chat_id = chatID,
                    reply_markup = new
                    {
                        inline_keyboard = inline_keyboard,
                    }
                };

                if (parameter.Message.IsNullOrWhiteSpace()) {
                    body = new
                    {
                        animation = parameter.AnimationUrl,
                        chat_id = chatID,
                        reply_markup = new
                        {
                            inline_keyboard = inline_keyboard,
                        }
                    };
                }
            }
            else if (parameter.MessageType == MessageType.KeyboardButtons)
            {
                body = new
                {
                    chat_id = chatID,
                    text = parameter.Message,
                    reply_markup = new
                    {
                        keyboard = inline_keyboard,
                    }
                };
            }
            else
            {
                body = new
                {
                    chat_id = chatID,
                    message_id = parameter.AdvanceSetting.UpdateMessageID,
                    text = parameter.Message,
                    reply_markup = new
                    {
                        inline_keyboard = inline_keyboard,
                        remove_keyboard = parameter.AdvanceSetting.IsRemoveKeyboard,
                    }
                };
            }

            //var body = new
            //{
            //    chat_id = "1242563509",
            //    text = msg,
            //    parse_mode = "html"
            //};

            para.AdvancedSetting = para.AdvancedSetting ?? new AdvancedSetting();
            para.AdvancedSetting.Log_ApiCategory = chatID;
            para.AdvancedSetting.Log_SubCategory = ((int)parameter.MessageCategoryTag).ToString();
            para.AdvancedSetting.Log_DebugInfo1 = parameter.Message;
            para.LogAction = WriteLog;

            var response = APIRequestHelper.RequestApiResult<TelegramApiCommonResponse>(para , body);
            return;
        }


        private void WriteLog(ApiRequestLogTemporaryInfoModel LogAction)
        {
            if (LogAction.Response != null && !string.IsNullOrWhiteSpace(LogAction.Response.ResponseContent))
            {
                LogAction.Response.ResponseContent = LogAction.Response.ResponseContent.Truncate(5000);
            }

            string chatID = LogAction.RequestParameters.AdvancedSetting.Log_ApiCategory;

            var tryParse = LogAction.Response.ResponseContent.TryJsonConvertToModel<TelegramApiCommonResponse>();

            Task.Factory.StartNew(() =>
            {
                TgMessageApiHistoryQueueHelper.AppendToQueue(chatID, new TgMessageHistoryLogModel
                {
                    CreateTime = DateTime.UtcNow.AddHours(8).ToString("yyyy-MM-dd HH:mm:ss"),
                    MessageID = tryParse?.result?.message_id ?? 0,
                    MessageCategoryType = Int32.Parse(LogAction.RequestParameters.AdvancedSetting.Log_SubCategory),
                    Text = LogAction.RequestParameters.AdvancedSetting.Log_DebugInfo1,
                    ResponseText = LogAction.Response.ResponseContent,
                    ResponseTime = LogAction.Response.ResponseTime,
                    RequestContent = LogAction.RequestContent,
                });
            });            
        }

        public void DeleteMessage(string chatID , string messageID)
        {
            var para = new RequestParameters
            {
                BaseUrl = "https://example.invalid/",
                ApiUrl = $"bot{_token}/deleteMessage",
                Method = HttpMethodType.HttpPost,
                TimeoutMillisecond = 10000,
            };

            var body = new
            {
                chat_id = chatID,
                message_id = messageID,
            };

            var response = APIRequestHelper.RequestApiResult<string>(para, body);
            return;
        }

        public void EditMessage(string chatID , string messageID, string text) 
        {
            var para = new RequestParameters
            {
                BaseUrl = "https://example.invalid/",
                ApiUrl = $"bot{_token}/editMessageText",
                Method = HttpMethodType.HttpPost,
                TimeoutMillisecond = 10000,
            };

            var body = new
            {
                chat_id = chatID,
                message_id = messageID,
                text = text,
            };

            var response = APIRequestHelper.RequestApiResult<string>(para, body);
            return;
        }


        //private void WriteMessageLog(string chatID , MessageCategoryTagEnum messageCategoryType , string messageText, TelegramApiCommonResponse model , long executedTime)
        //{
        //    if (model == null)
        //        return;

        //    if (model.result == null)
        //        return;

        //    TgMessageApiHistoryQueueHelper.AppendToQueue(chatID, new TgMessageHistoryLogModel
        //    {
        //        MessageID = model.result.message_id,
        //        MessageCategoryType = (int)messageCategoryType,
        //        Text = messageText,
        //        ResponseText = model.ConvertToJsonString(),
        //        ResponseTime = executedTime
        //    });
        //}


        public static T GetAttributeFromEnum<T>(System.Enum enumVal) where T : System.Attribute
        {
            var type = enumVal.GetType();
            var memInfo = type.GetMember(enumVal.ToString());
            var attributes = memInfo[0].GetCustomAttributes(typeof(T), false);
            return (attributes.Length > 0) ? (T)attributes[0] : null;
        }



    }
}
