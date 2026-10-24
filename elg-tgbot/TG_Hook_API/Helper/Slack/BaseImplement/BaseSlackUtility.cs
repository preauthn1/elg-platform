using System;
using System.Collections.Generic;
using System.Linq;
using System.Text;
using System.Threading.Tasks;
using System.Configuration;
using System.Web;

namespace target.Utility.Utility.ThirdParty
{
    /// <summary>
    /// 基底的 BaseSlackUtility 基本上不會再改到
    /// 有需要根據公司邏輯 特別客製的，請修正 MySlackUtility
    /// </summary>
    internal class BaseSlackUtility
    {

        private string _token;
        private string _prefixText;
        

        public BaseSlackUtility(string token)
        {
            _token = [REDACTED];
            _prefixText = string.Empty;
        }

        public BaseSlackUtility(string token , string prefixText)
        {
            _token = [REDACTED];
            _prefixText = prefixText;
        }
        
        
        public void SendMessage(SlackMessageParameter parameter)
        {           
            if (string.IsNullOrWhiteSpace(parameter.Message))
                return;
                        
            parameter.Message = parameter.Message.Replace("#", "＃");

            var para = new RequestParameters
            {
                BaseUrl = "https://example.invalid/api/",
                ApiUrl = "chat.postMessage",
                Method = HttpMethodType.HttpGet,
                TimeoutMillisecond = 5000,
            };
            
            //訊息加上客製前墜詞 (通常是用來識別環境)
            parameter.Message = _prefixText + parameter.Message;
            

            string myToken = [REDACTED];

            if (string.IsNullOrWhiteSpace(parameter.ReplaceSlackToken) == false)
                myToken = [REDACTED];

            para.AddParameter("token", myToken);
            para.AddParameter("channel", parameter.Channel.ToString());
            para.AddParameter("text", HttpUtility.UrlEncode(parameter.Message));
            para.AddParameter("username", parameter.DisplayUserName);
            para.AddParameter("as_user", false.ToString());
            
            var response = APIRequestHelper.RequestApiResult<string>(para);
            return;

        }
        

    }
}
