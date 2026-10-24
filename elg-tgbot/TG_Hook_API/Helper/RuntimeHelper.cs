using System;
using System.Collections.Generic;
using System.Globalization;
using System.Linq;
using System.Text;
using System.Threading.Tasks;
using System.Collections.Specialized;
using target.Utility.Extensions;
using example.invalid;
using example.invalid;
using Microsoft.AspNetCore.Http;
using System.Reflection;
using System.Runtime.Versioning;
using System.Threading;
using Lck.Utility.Extensions.Helper;
using Newtonsoft.Json;

namespace target.Utility
{

    public static class RuntimeHelper
    {

        public static string StartupTime { get; set; }

        public static string RuntimeID { get; set; }

        public static string EnvironmentName { get; set; }

        public static string ContentRootPath { get; set; }


        static RuntimeHelper()
        {
            //用來識別 每台k8s機器獨立ID
            RuntimeID = DateTime.UtcNow.AddHours(8).ToString("MMddHHmmss") + "_" + Guid.NewGuid().ToString().Substring(0 , 6);
            StartupTime = DateTime.UtcNow.AddHours(8).ToString("MMddHHmmss");
        }

        /// <summary>
        /// **重要 請在startup的時候呼叫此
        /// </summary>
        public static void Initialize(string envName , string contentRootPath)
        {
            EnvironmentName = envName;
            ContentRootPath = contentRootPath;

            //測試用 看看有無差別
            System.Threading.ThreadPool.SetMaxThreads(600, 800);
            System.Threading.ThreadPool.SetMinThreads(200, 300);

            //更新config provider
            BaseSettingHelper.ReloadAppSettingProvider();

            try
            {
                //這是v13.0.1 版之前的一個漏洞處理
                JsonConvert.DefaultSettings = () => new JsonSerializerSettings { MaxDepth = 128 };
            }
            catch
            {

            }
        }



        public static string GetGitSHA1()
        {
            try
            {
                return Environment.GetEnvironmentVariable("Git_SHA1");
            }
            catch
            {

            }

            return "";
        }

        public static string Get_Gitlab_Pipeline_ID()
        {
            try
            {
                return Environment.GetEnvironmentVariable("Pipeline_ID");
            }
            catch
            {

            }

            return "";
        }


        /// <summary>
        /// 判斷當前環境是否為 .net core
        /// </summary>
        /// <returns></returns>
        public static bool IsNetCoreRuntimeFramework()
        {
            var ver = GetRuntimeFrameworkVersion();

            if (string.IsNullOrWhiteSpace(ver))
                return false;

            if (ver.Contains("NETCoreApp"))
                return true;

            return false;
        }


        /// <summary>
        /// 取得當前運行的frameword版本 (.net core、.net frameword)
        /// </summary>
        /// <returns></returns>
        public static string GetRuntimeFrameworkVersion()
        {
            var framework = Assembly
              .GetEntryAssembly()?
              .GetCustomAttribute<TargetFrameworkAttribute>()?
              .FrameworkName;

            return framework;
        }



        public static DateTime? GetBinFileLastModifiedTime()
        {

            try
            {
                var location = System.Reflection.Assembly.GetExecutingAssembly().Location;
                var time = File.GetLastWriteTime(location);
                return time;
            }
            catch
            {

            }

            return null;
        }


        public static string GetK8sInstanceName()
        {
            try
            {
                string k8sName = example.invalid.File.ReadAllText("/etc/hostname");
                return $" k8s: `{k8sName}` "; ;
            }
            catch
            {

            }

            return string.Empty;
        }


        /// <summary>
        /// production環境，在未帶入password的情況下，首頁可以顯示的資訊有限制
        /// </summary>
        public static object GetHideInformationForProduction()
        {
            return new
            {
                EnvironmentName = SettingHelper.Env,
                WebSiteName = SettingHelper.WebSiteName,
            };
        }



        /// <summary>
        /// 首頁可以顯示的資訊
        /// </summary>
        /// <returns></returns>
        public static object GetHomePageDebugInformation()
        {
            return new
            {
                EnvironmentName = SettingHelper.Env,
                WebSiteName = SettingHelper.WebSiteName,
                IsEnableCache = SettingHelper.IsEnableCache,
                IsRemoteCache = SettingHelper.IsRemoteCache,
                RedisCon = SettingHelper.ThirdParty.RedisConn,
                Redis002_Conn = SettingHelper.ThirdParty.Redis002_Conn,
                RedisTimeout = SettingHelper.ThirdParty.RedisTimeoutSeconds,
                ConfigName = BaseSettingHelper.GetJsonSettingFileNameByEnv(),
                SHA1 = GetGitSHA1(),
                Pipeline_ID = Get_Gitlab_Pipeline_ID(),
                LastModifiedTime = GetBinFileLastModifiedTimeStringFormat(),
                RuntimeID = RuntimeID
            };
        }


        public static string GetBinFileLastModifiedTimeStringFormat()
        {
            DateTime? date = GetBinFileLastModifiedTime();

            if (date == null)
                return string.Empty;

            return date.Value.ToString("yyyy-MM-dd HH:mm:ss");
        }

    }

}
