using Newtonsoft.Json;
using System;
using System.Collections.Generic;
using System.Linq;
using System.Text;
using System.Threading.Tasks;

namespace target.Utility.Utility.ThirdParty
{
    public class TelegramMessageParameter
    {
        public TelegramMessageParameter()
        {
            this.AdvanceSetting = new TelegramMessageAdvanceSetting();
        }

        public MessageType MessageType { get; set; }

        public MessageCategoryTagEnum MessageCategoryTag { get; set; }

        public string Message { get; set; }

        public string ImageUrl { get; set; }

        public string AnimationUrl { get; set; }
        /// <summary>
        /// 預設的chatID: 1242563509
        /// </summary>
        public string TargetChatID { get; set; }



        public TelegramMessageAdvanceSetting AdvanceSetting { get; set; }

        public List<ButtonList> ButtonList { get; set; }

        public List<List<ButtonList>> MultiRowButtonList { get; set; }

    }


    public class TelegramMessageAdvanceSetting
    {

        public string UpdateMessageID { get; set; }

        public bool IsRemoveKeyboard { get; set; }

    }





    public class TelegramApiCommonResponse
    {

        public bool ok { get; set; }

        public int error_code { get; set; }

        public string description { get; set; }

        public TelegramApiSuccessResponseDetail result { get; set; }

    }

    public class TelegramApiSuccessResponseDetail
    {
        public int message_id { get; set; }     
        public long date { get; set; }
        public string text { get; set; }
    }


    public enum MessageType
    {
        Normal = 0,
        ForceReply = 77,
        RemoveReplyKeyboard = 87,
        RequestPhoneNumber = 101,
        KeyboardButtons = 201,
        HomeMenu = 777,
        ImageWithText = 888,
    }

    public enum MessageCategoryTagEnum
    {
        Normal = 0,
        MessageAndLink = 50,
        DoNotDelete = 100,
        HomeMenu = 777,
    }


    public class ButtonList
    {

        public ButtonList()
        {

        }

        public ButtonList(string displayText , string callbackData , string myUrl = null)
        {
            this.text = displayText;

            if (!string.IsNullOrWhiteSpace(callbackData))
            {
                this.callback_data = callbackData;
            }

            if(!string.IsNullOrWhiteSpace(myUrl))
            {
                this.url = myUrl;
            }
        }

      

        public string text { get; set; }

        /// <summary>
        /// 請對應 CallbackAction
        /// </summary>
        [JsonProperty(NullValueHandling = NullValueHandling.Ignore)]
        public string callback_data { get; set; }

        [JsonProperty(NullValueHandling = NullValueHandling.Ignore)]
        public string url { get; set; }


        [JsonProperty(NullValueHandling = NullValueHandling.Ignore)]
        public object web_app { get; set; }


        [JsonProperty(NullValueHandling = NullValueHandling.Ignore)]
        public bool? request_contact { get; set; }






        //忘記這參數可以做啥了
        //[JsonProperty(NullValueHandling = NullValueHandling.Ignore)]
        //public string switch_inline_query_current_chat { get; set; }


    }


    public class CallbackAction
    {
        public const string MenuAction_Register = "MenuAction_Register";
        public const string MenuAction_Lobby = "MenuAction_Lobby";
        public const string MenuAction_GoToWebsite = "MenuAction_GoToWebsite";
    }



}
