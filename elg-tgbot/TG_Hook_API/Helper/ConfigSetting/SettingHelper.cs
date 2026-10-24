using System;
using System.Collections.Generic;
using System.Globalization;
using System.Linq;
using System.Text;
using System.Threading.Tasks;
using System.Collections.Specialized;
using example.invalid;
using example.invalid;
using Microsoft.AspNetCore.Http;
using System.Reflection;
using System.Runtime.Versioning;
using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.FileProviders;

namespace target.Utility
{
    public static class SettingHelper
    {

       

        public static bool IsProductionDbConnection
        {
            get
            {
                var conn = BaseSettingHelper.GetSettingValue($"ConnectionStrings:MasterDB") ?? "";
                return conn.Contains("172.18.");
            }
        }

               

        /// <summary>
        /// 會強轉小寫
        /// </summary>
        public static string Env
        {
            get
            {
                return (BaseSettingHelper.GetSettingValue("Env") ?? "").ToLower();
            }
        }


        
        public static bool IsDev
        {
            get
            {
                return Env.Contains("local") || Env.Contains("dev") || Env.Contains("develop");
            }
        }

        public static string DefaultLanguageCode
        {
            get
            {
                return BaseSettingHelper.GetSettingValueOrDefaultValue<string>("DefaultLanguageCode", "en-us");
            }
        }

        public static string DebugSiteUrl
        {
            get
            {
                return BaseSettingHelper.GetSettingValue("DebugSiteUrl");
            }
        }


        public static string WebSiteName
        {
            get
            {
                return BaseSettingHelper.GetSettingValue("WebSiteName");
            }
        }

        public static bool IsVendorAPI
        {
            get
            {
                if (string.IsNullOrWhiteSpace(WebSiteName))
                    return false;

                return WebSiteName.Contains(".Vendor");
            }
        }

        public static bool IsLocalhostWebSiteName
        {
            get
            {
                if (string.IsNullOrWhiteSpace(WebSiteName))
                    return false;

                return WebSiteName.Contains(".Local");
            }
        }


        /// <summary>
        /// 是否使用 redis/遠端 型的cache (而非memory cache)
        /// </summary>
        public static bool IsRemoteCache
        {
            get
            {
                return BaseSettingHelper.GetSettingValueOrDefaultValue<bool>("IsRemoteCache" , false);
            }
        }


        public static bool IsEnableSlackDebugLog
        {
            get
            {
                return BaseSettingHelper.GetSettingValueOrDefaultValue<bool>("IsEnableSlackDebugLog", true);
            }
        }


        public static bool IsEnableCache
        {
            get
            {
                return BaseSettingHelper.GetSettingValueOrDefaultValue<bool>("IsEnableCache", false);
            }
        }


        public static string ApiRequestLogTableName
        {
            get
            {
                return BaseSettingHelper.GetSettingValueOrDefaultValue<string>("ApiRequestLogTableName", "[dbo].[ApiRequestLog]");
            }
        }


        public static string ApiRequestErrorLogTableName
        {
            get
            {
                return BaseSettingHelper.GetSettingValueOrDefaultValue<string>("ApiRequestErrorLogTableName", "[dbo].[ApiRequestLogError]");
            }
        }


        /// <summary>
        /// 是否採用每個日期分成一張table的模式
        /// </summary>
        public static bool ApiRequestLogDailyTableEnable
        {
            get
            {
                return BaseSettingHelper.GetSettingValueOrDefaultValue<bool>("ApiRequestLogDailyTableEnable", false);
            }
        }


        /// <summary>
        /// 是否採用hostService 定時同步localFile至儲存空間
        /// </summary>
        public static bool ApiRequestLogBackgroundSyncEnable
        {
            get
            {
                return BaseSettingHelper.GetSettingValueOrDefaultValue<bool>("ApiRequestLogBackgroundSyncEnable", false);
            }
        }

        public static bool IsKestrelWebHost
        {
            get
            {
                return BaseSettingHelper.GetSettingValueOrDefaultValue<bool>("IsKestrelWebHost", false);
            }
        }


        /// <summary>
        /// 是否採用hostService 定時同步localFile至儲存空間
        /// </summary>
        public static bool GetConfig_ApiRequestLogDailyScheculeSyncEnable(IConfiguration config)
        {
            var settingValue = config.GetSection("ApiRequestLogDailyScheculeSyncEnable")?.Value;
            return BaseSettingHelper.ConvertSettingValueToValueType<bool>(settingValue, false);
        }


        public static string WebHostListenPort
        {
            get
            {
                return BaseSettingHelper.GetSettingValue("WebHostListenPort");
            }
        }


        public static string TgBotToken
        {
            get
            {
                return BaseSettingHelper.GetSettingValue("TgBotToken");
            }
        }


        public static string Vworld_API_URL
        {
            get
            {
                return BaseSettingHelper.GetSettingValue("Vworld_API_URL");
            }
        }

        public static string Vworld_API_MD5_KEY
        {
            get
            {
                return BaseSettingHelper.GetSettingValue("Vworld_API_MD5_KEY");
            }
        }


        public static string Vworld_API_AES_KEY
        {
            get
            {
                return BaseSettingHelper.GetSettingValue("Vworld_API_AES_KEY");
            }
        }

        public static string Vworld_API_IV
        {
            get
            {
                return BaseSettingHelper.GetSettingValue("Vworld_API_IV");
            }
        }

        public static SlackNotifySetting SlackNotify => new SlackNotifySetting();

        public static ThirdPartySolutionSetting ThirdParty => new ThirdPartySolutionSetting();

    }


    public class ThirdPartySolutionSetting
    {

        private static string Prefix = "ThirdParty:";




        public string RedisConn
        {
            get
            {
                return BaseSettingHelper.GetSettingValueOrDefaultValue<string>(Prefix + "RedisConn", "");
            }
        }

        /// <summary>
        /// 某些環境會共用redis，然後會用 DB編號區別開來
        /// </summary>
        public int RedisDBNumber
        {
            get
            {
                return BaseSettingHelper.GetSettingValueOrDefaultValue<int>(Prefix + "RedisDBNumber", 0);
            }
        }


        /// <summary>
        /// 連結逾時秒數
        /// </summary>
        public int RedisTimeoutSeconds
        {
            get
            {
                return BaseSettingHelper.GetSettingValueOrDefaultValue<int>(Prefix + "RedisTimeoutSeconds", 10);
            }
        }


        public string Redis002_Conn
        {
            get
            {
                return BaseSettingHelper.GetSettingValueOrDefaultValue<string>(Prefix + "Redis002_Conn", "");
            }
        }


        /// <summary>
        /// 某些環境會共用redis，然後會用 DB編號區別開來
        /// </summary>
        public int Redis002_DBNumber
        {
            get
            {
                return BaseSettingHelper.GetSettingValueOrDefaultValue<int>(Prefix + "Redis002_DBNumber", 0);
            }
        }


        public int Redis002_TimeoutSeconds
        {
            get
            {
                return BaseSettingHelper.GetSettingValueOrDefaultValue<int>(Prefix + "Redis002_TimeoutSeconds", 0);
            }
        }


    }




    public class SlackNotifySetting
    {
        private static string Prefix = "SlackNotify:";


        /// <summary>
        /// 當跳出一般C# exception的時候發通知
        /// </summary>
        public bool IsNotifyEnable_When_SystemException
        {
            get
            {
                return BaseSettingHelper.GetSettingValueOrDefaultValue<bool>(Prefix + "IsNotifyEnable_When_SystemException", true);
            }
        }


        public string NotifyChannel_When_SystemException
        {
            get
            {
                return BaseSettingHelper.GetSettingValueOrDefaultValue<string>(Prefix + "NotifyChannel_When_SystemException", "n_error_game_api");
            }
        }

    }





}
