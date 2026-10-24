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
using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.FileProviders;

namespace target.Utility
{
    public static class BaseSettingHelper
    {
        private static IConfigurationRoot ConfigurationRoot;


        static BaseSettingHelper()
        {
            ReloadAppSettingProvider();
        }

        public static void ReloadAppSettingProvider()
        {
            ConfigurationRoot = InstanceConfigProvider();
        }

        public static string GetSettingValue(string keyName)
        {
            //object結構的話 需代入類似這樣的路徑字串 ConnectionStrings:MyDB
            var settingValue = ConfigurationRoot.GetSection(keyName).Value;
            return settingValue;
        }


        public static T GetSettingValueOrDefaultValue<T>(string keyName , T defaultValue)
        {
            //object結構的話 需代入類似這樣的路徑字串 ConnectionStrings:MyDB
            var settingValue = ConfigurationRoot.GetSection(keyName).Value;

            return ConvertSettingValueToValueType(settingValue , defaultValue);
        }


        public static T ConvertSettingValueToValueType<T>(string settingValue, T defaultValue)
        {
            if (string.IsNullOrWhiteSpace(settingValue))
                return defaultValue;

            return (T)Convert.ChangeType(settingValue, typeof(T)); ;
        }



        private static IConfigurationRoot InstanceConfigProvider()
        {
            var builder = new ConfigurationBuilder()
                  .SetFileProvider(new PhysicalFileProvider(Directory.GetCurrentDirectory()))
                   .AddJsonFile("appsettings.json")
                   .AddJsonFile(GetJsonSettingFileNameByEnv(), optional: true);

            var config = builder.Build();
            return config;
        }

        public static string GetJsonSettingFileNameByEnv()
        {
            string envName = RuntimeHelper.EnvironmentName;
            return $"appsettings.{envName}.json";
        }

    }

}
