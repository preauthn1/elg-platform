//using Microsoft.AspNetCore.Mvc;
//using Microsoft.Extensions.Logging;
//using target.Utility.Utility.ThirdParty;
//using System;
//using System.Collections.Generic;
//using example.invalid;
//using System.Linq;
//using System.Text;
//using System.Threading;
//using System.Threading.Tasks;
//using TG_Hook_API.Models;

//namespace WebApplication3_20220202_003.Controllers
//{
//    [ApiController]
//    public partial class TGWebHookV1Controller : ControllerBase
//    {
//        public static List<string> RegisterStep1_ChatIDs = new List<string>();

//        public TGWebHookV1Controller()
//        {

//        }


//        [Route("TGWebHook", Order = 999)]
//        public object CatchAllRequest([FromBody] object myModel, [FromForm] string content2)
//        {
//            try
//            {
//                var parseResult = ConvertToJson(myModel);

//                TgWebhookRequest model = parseResult.Convert;

//                string targetChatID = model.message?.chat?.id;

//                string prefixText = model.callback_query != null ? "觸發 callback_query 了" : "";

//                string msg = $"API：{prefixText} {HttpContext.Request.Path}、{HttpContext.Request.Method}、char:{targetChatID}、body：{parseResult.RawJson}";

//                MySlackUtility.SendMessageToChannelAsync(new SlackMessageParameter
//                {
//                    Channel = SlackChannelEnum.n_error_pgs_common,
//                    Message = msg
//                });

//                if (model.callback_query != null)
//                {
//                    targetChatID = model.callback_query.message.chat?.id;

//                    if (model.callback_query.data == CallbackAction.MenuAction_GoToWebsite)
//                    {
//                        MyTelegramUtility.SendMessageToChannel(new TelegramMessageParameter
//                        {
//                            Message = $"請點擊此連結前往vworld遊戲平台{Environment.NewLine}https://example.invalid/Lobby",
//                            TargetChatID = targetChatID,
//                        });
//                    }
//                    else if (model.callback_query.data == CallbackAction.MenuAction_Register)
//                    {
//                        RegisterRequestProcess(targetChatID);
//                    }
//                }
//                else if (model.message != null && model.message.contact != null)
//                {
//                    //註冊成功
//                    RegisterSuccessProcess(targetChatID, model);
//                }
//                else
//                {
//                    List<ButtonList> buttons = new List<ButtonList>
//                        {
//                            new ButtonList("vworld註冊/登入", CallbackAction.MenuAction_Register , "" , "qqqqqqqqq"),
//                            new ButtonList("👻前往vworld👻", CallbackAction.MenuAction_GoToWebsite , "https://example.invalid/Lobby" , ""),
//                            //new ButtonList("實驗按鈕1", "" , "" , "qqqqqqqqq"),
//                            //new ButtonList
//                            //{
//                            //    text = "實驗按鈕2",
//                            //    callback_data = "",
//                            //    switch_inline_query = "",
//                            //    switch_inline_query_current_chat = "qqqqq2",
//                            //    url = "",
//                            //}
//                        };

//                    var defaultTemplateMsg = new TelegramMessageParameter
//                    {
//                        Message = $"歡迎使用vworld機器人 請選擇你要進行的操作。{Environment.NewLine}{Environment.NewLine} 若有其他疑問請聯繫我方客服 @smsman_support ",
//                        ButtonList = buttons,
//                        TargetChatID = targetChatID,
//                    };

//                    if (model.message != null)
//                    {
//                        if (model.message.text == "/start")
//                        {
//                            RegisterStep1_ChatIDs.Remove(targetChatID);
//                            MyTelegramUtility.SendMessageToChannel(defaultTemplateMsg);
//                        }
//                        else if (model.message.text == "/force")
//                        {
//                            var myPara2 = new TelegramMessageParameter
//                            {
//                                Message = $" 請輸入您的帳號 ",
//                                TargetChatID = targetChatID,
//                                MessageType = MessageType.ForceReply
//                            };

//                            MyTelegramUtility.SendMessageToChannel(myPara2);
//                        }
//                        else if (model.message.text == "/phone")
//                        {
//                            RegisterRequestProcess(targetChatID);
//                        }
//                        else
//                        {
//                            if (RegisterStep1_ChatIDs.Contains(targetChatID))
//                            {
//                                //RegisterStep1_ChatIDs.Remove(targetChatID);
//                                //MyTelegramUtility.SendMessageToChannelAsync(new TelegramMessageParameter
//                                //{
//                                //    Message = $"✔️✔️✔️註冊完成✔️✔️✔️{Environment.NewLine}系統已使用 {model.message.text} 作為遊戲帳號。已為您完成註冊。{Environment.NewLine}請使用以下網址進入遊戲{Environment.NewLine}https://example.invalid/Lobby?chat={targetChatID}",
//                                //    TargetChatID = targetChatID,
//                                //});
//                            }
//                            else
//                            {
//                                MyTelegramUtility.SendMessageToChannel(defaultTemplateMsg);
//                            }
//                        }
//                    }
//                }
//            }
//            catch (Exception ex)
//            {
//                string myMsg = $"錯誤啦 {ex.ToString()} ";
//                MySlackUtility.SendMessageToChannelAsync(new SlackMessageParameter
//                {
//                    Channel = SlackChannelEnum.n_error_pgs_common,
//                    Message = myMsg
//                });
//            }

//            return new
//            {
//                ID = 1,
//                Name = "CatchAll"
//            };
//        }


//        private void RegisterRequestProcess(string chatID)
//        {
//            string templateText = $"📞📞 註冊流程須提供電話號碼 📞📞{Environment.NewLine}";
//            string repeatText = $"{templateText}{templateText}{templateText}";

//            var myPara2 = new TelegramMessageParameter
//            {
//                Message = repeatText + $"請確認下方區塊的訊息並點擊同意",
//                TargetChatID = chatID,
//                MessageType = MessageType.RequestPhoneNumber
//            };

//            MyTelegramUtility.SendMessageToChannel(myPara2);
//        }

//        private void RegisterSuccessProcess(string chatID, TgWebhookRequest model)
//        {
//            var sourceReplyMessage = model.message.reply_to_message;

//            if (sourceReplyMessage == null)
//            {
//                return;
//            }

//            MyTelegramUtility.DeleteMessage(chatID, sourceReplyMessage.message_id);


//            //刪除後再延遲一下
//            Thread.Sleep(500);
//            string phone = model.message.contact.phone_number;

//            string url = string.Format("https://example.invalid/Lobby?chat={0}&q={1}", chatID, phone);

//            List<ButtonList> buttons = new List<ButtonList>
//                        {
//                            new ButtonList("🌐🌐 立即前往遊玩 🌐🌐", CallbackAction.MenuAction_GoToWebsite , url , ""),
//                        };

//            string templateText = $"😀😀 註冊完成 😀😀{Environment.NewLine}";

//            string repeatText = $"{templateText}{templateText}{templateText}{Environment.NewLine}";

//            MyTelegramUtility.SendMessageToChannel(new TelegramMessageParameter
//            {
//                Message = repeatText + $"已使用您的號碼 {phone} 作為登入帳號進行註冊。{Environment.NewLine}請透過下方按鈕以進入遊戲",
//                ButtonList = buttons,
//                TargetChatID = chatID,
//            });
//        }


//        private string StreamToString(Stream stream)
//        {
//            try
//            {
//                stream.Position = 0;
//                using (StreamReader reader = new StreamReader(stream, Encoding.UTF8))
//                {
//                    return reader.ReadToEnd();
//                }
//            }
//            catch
//            {

//            }

//            return "";
//        }


//        private (string RawJson, TgWebhookRequest Convert) ConvertToJson(object model)
//        {
//            try
//            {
//                var json = Newtonsoft.Json.JsonConvert.SerializeObject(model);
//                var convert = Newtonsoft.Json.JsonConvert.DeserializeObject<TgWebhookRequest>(json);
//                return (json, convert);
//            }
//            catch
//            {


//            }

//            return (string.Empty, null);
//        }


//    }
//}
