using Microsoft.AspNetCore.Mvc;
using Microsoft.Extensions.Configuration;
using target.Web.Modules.Filter;
using System;
using System.Collections.Generic;
using example.invalid;

namespace TG_Hook_API.Models
{
    public class TgWebhookParsingResult
    {
        public string RawJson { get; set; }
        public TgWebhookRequest ParsingModel { get; set; }
        public string TempInfo_BotID { get; set; }
        public string TempInfo_ChatID { get; set; }
    }


    public class TgWebhookRequest
    {
        public string update_id { get; set; }
        public TGCallbackQuery callback_query { get; set; }
        public TGMessage message { get; set; }
    }


    public class TGReplyTo
    {
        public From from { get; set; }
        public Chat chat { get; set; }

        public string message_id { get; set; }
        public string date { get; set; }
        public string text { get; set; }
    }



    public class TGCallbackQuery
    {
        public string id { get; set; }
        public From from { get; set; }
        public Message message { get; set; }
        public string chat_instance { get; set; }
        public string data { get; set; }
    }


    public class Chat
    {
        public string id { get; set; }
        public string first_name { get; set; }
        public string last_name { get; set; }
        public string username { get; set; }
        public string type { get; set; }
    }


    public class From
    {
        public string id { get; set; }
        public bool is_bot { get; set; }
        public string first_name { get; set; }
        public string last_name { get; set; }
        public string username { get; set; }
        public string language_code { get; set; }
    }


    public class Message
    {
        public string message_id { get; set; }
        public From from { get; set; }
        public Chat chat { get; set; }
        public string date { get; set; }
        public string text { get; set; }
        public ReplyMarkup reply_markup { get; set; }
    }


    public class ReplyMarkup
    {
        public List<List<object>> inline_keyboard { get; set; }
    }

   
    public class TGMessage
    {
        public string message_id { get; set; }
        public From from { get; set; }
        public Chat chat { get; set; }
        public string date { get; set; }
        public string text { get; set; }
        public TGContact contact { get; set; }
        public TGReplyTo reply_to_message { get; set; }

    }


    public class TGContact
    {
        public string phone_number { get; set; }
        public string first_name { get; set; }
        public string last_name { get; set; }
        public string user_id { get; set; }
    }



}
