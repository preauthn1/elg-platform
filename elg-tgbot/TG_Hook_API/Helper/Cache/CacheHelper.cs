using Lck.Utility.Extensions.Helper;
using System;
using System.Collections.Generic;
using System.Linq;
using System.Text;
using System.Threading.Tasks;
using target.Utility.Extensions;
using Lck.Utility.Extensions.Cache;
using target.Utility;
using example.invalid;

namespace Lck.Utility.Extensions.Helper
{


    public class CacheHelper
    {
        protected static TimeSpan _defaultCacheTime
        {
            get
            {
                return new TimeSpan(0, 2, 0);
            }
        }


        public static T GetCacheOrQuery<T>(string key, Func<T> func, int expiredMinute)
        {
            return GetCacheOrQuery(key, func, new TimeSpan(0, expiredMinute, 0));
        }


        public static T GetCacheOrQuery<T>(string key, Func<T> func, TimeSpan? expiredTime = null)
        {
            //若無啟用cache
            if (IsCacheActive() == false)
            {
                return func();
            }

            T cacheValue = GetCacheValue<T>(key);

            if (cacheValue != null)
                return cacheValue;

            T queryResult = func();

            //null的話 就不用cache存了 (請務必不能調整此邏輯)
            if (queryResult == null)
                return queryResult;

            //似乎是曾經遇過 reference value 被改變、mutable的問題 發生bug、才有這樣的寫法
            var cloneResult = queryResult.ConvertToJsonString().JsonConvertToModel<T>();

            SetCacheValue(key, cloneResult, expiredTime);
            return queryResult;
        }

        
        public static T SetCacheOnlyWhenQueryValueMatch<T>(string key, Func<T> func, T mustMatchValue, int expiredMinutes = 5)
        {
            //若無啟用cache
            if (IsCacheActive() == false)
            {
                return func();
            }

            T cacheValue = GetCacheValue<T>(key);

            if (cacheValue != null)
                return cacheValue;

            T queryResult = func();

            //null的話 就不用cache存了
            if (queryResult == null || !queryResult.Equals(mustMatchValue))
                return queryResult;

            var cloneResult = queryResult.ConvertToJsonString().JsonConvertToModel<T>();
            SetCacheValue(key, cloneResult, new TimeSpan(0, expiredMinutes, 0));

            return queryResult;
        }



        public static T GetCacheValue<T>(string key)
        {
            if (IsCacheActive() == false)
                return default(T);

            var cacheService = InstanceCacheService();

            T cacheValue = cacheService.Get<T>(key);
            var cloneResult = cacheValue.ConvertToJsonString().JsonConvertToModel<T>();
            return cloneResult;
        }


        /// <summary>
        /// expiredTime 帶入null，會有預設過期時間
        /// </summary>
        public static bool SetCacheValue(string key, object value, TimeSpan? expiredTime)
        {
            if (IsCacheActive() == false)
                return false;

            expiredTime = GetExpireTimeBySetting(expiredTime);

            var cacheService = InstanceCacheService();

            bool isOK = cacheService.Set(key, value, GetExpireTimeBySetting(expiredTime));
            return isOK;
        }



        public static List<string> GetCacheKeysByKeyword(string keyword)
        {
            if (IsCacheActive() == false)
                return new List<string>();

            var cacheService = InstanceCacheService();
            return cacheService.GetCacheKeysByKeyword(keyword); ;
        }



        public static void RemoveCacheAll()
        {
            if (IsCacheActive() == false)
                return;

            var cacheService = InstanceCacheService();

            cacheService.FlushAll();
        }


        public static void RemoveCacheByKey(string key)
        {
            if (IsCacheActive() == false)
                return;

            var cacheService = InstanceCacheService();

            cacheService.Flush(key);
        }


        public static void RemoveCacheByKeyword(string keyword)
        {
            if (IsCacheActive() == false)
                return;

            var queryAllKeys = GetCacheKeysByKeyword(keyword);

            var cacheService = InstanceCacheService();
            foreach (var key in queryAllKeys)
            {
                cacheService.Flush(key);
            }
        }

    

        private static bool IsCacheActive()
        {
            return true;
        }


        private static BaseCache InstanceCacheService()
        {
            //根據專案的config設置 決定要用哪一種
            bool isRemoteCache = SettingHelper.IsRemoteCache;
            return InstanceCacheService(isRemoteCache);
        }


        private static BaseCache InstanceCacheService(bool isRemoteCache)
        {
            if (!IsCacheActive())
                return null;

            return InstanceCacheByType(isRemoteCache);
        }


     
        public static BaseCache InstanceCacheByType(bool isRemoteCache)
        {
            return new RedisCache();
        }


        public static TimeSpan GetExpireTimeBySetting(TimeSpan? expiredTime)
        {
            if (expiredTime.HasValue == false)
                expiredTime = _defaultCacheTime;

            return expiredTime.Value;
        }


    }
}
