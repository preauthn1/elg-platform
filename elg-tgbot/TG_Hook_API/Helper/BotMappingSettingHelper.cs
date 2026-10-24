using target.Utility;
using System;
using System.Collections.Generic;
using System.Linq;
using System.Threading.Tasks;

namespace TG_Hook_API.Helper
{
    public static class BotMappingSettingHelper
    {

        public static string GetTGTokenByBotID(string botID)
        {
            string settingValue = BaseSettingHelper.GetSettingValue($"{botID}:TGToken");

            if (!string.IsNullOrWhiteSpace(settingValue))
                return settingValue;

            //備援方案 從cache抓... (清cache就消失了) ... 先不寫了
            //ps. 透過API抓 網路延遲時間不理想...暫不考慮
            return settingValue;
        }

        public static string GetUUIDByBotID(string botID)
        {
            string settingValue = BaseSettingHelper.GetSettingValue($"{botID}:BotUUID");

            if (!string.IsNullOrWhiteSpace(settingValue))
                return settingValue;

            return settingValue;
        }





    }
}
