using System;
using System.Collections.Generic;
using System.Linq;
using System.Text;
using System.Threading.Tasks;

namespace target.Utility.Utility.ThirdParty
{
    public class SlackMessageParameter
    {
        public SlackMessageParameter()
        {
            this.DisplayUserName = "PGS機器人";
        }

        public string Message { get; set; }


        public SlackChannelEnum Channel { get; set; }

        
        /// <summary>
        /// 預設會用Config指定的token 但若有多個slack_team的通知需求 可覆寫掉使用另一組
        /// </summary>
        public string ReplaceSlackToken { get; set; }
        

        public string DisplayUserName { get; set; }


        /// <summary>
        /// 單元測試的時候不要發通知
        /// </summary>
        public bool SkipNotifyWhenUnitTest { get; set; }

    }








}
