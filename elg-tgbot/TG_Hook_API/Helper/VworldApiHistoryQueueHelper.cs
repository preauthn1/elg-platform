using Lck.Utility.Extensions.Helper;
using System;
using System.Collections.Generic;
using System.Linq;
using System.Threading.Tasks;
using target.Utility.Extensions;
using TG_Hook_API.Models;

namespace TG_Hook_API.Helper
{
    public static class VworldApiHistoryQueueHelper
    {

        public const int MaxQueueSize = 100;


        public static void AppendToQueue(string chatID, object myObject)
        {
            string cacheKey = GenerateCacheKey(chatID);

            var queueList = CacheHelper.GetCacheValue<Queue<object>>(cacheKey) ?? new Queue<object>();

            queueList.Enqueue(myObject);

            if (queueList.Count() >= MaxQueueSize)
            {
                queueList.Dequeue();
            }

            if (queueList.Count() >= MaxQueueSize)
            {
                queueList.Dequeue();
            }

            CacheHelper.SetCacheValue(cacheKey, queueList , new TimeSpan(9999 , 0 , 0, 0));
        }


        public static List<object> GetAllHistory(string chatID)
        {
            string cacheKey = GenerateCacheKey(chatID);

            var queueList = CacheHelper.GetCacheValue<Queue<object>>(cacheKey) ?? new Queue<object>();

            // queueList reverse sort
            queueList = new Queue<object>(queueList.Reverse());

            return queueList.ToList();
        }


        public static void RemoveAllHistory(string chatID)
        {
            string cacheKey = GenerateCacheKey(chatID);

            CacheHelper.RemoveCacheByKey(cacheKey);
        }


        public static string GetLastStepHistory(string chatID)
        {
            string cacheKey = GenerateCacheKey(chatID);

            var queueList = CacheHelper.GetCacheValue<Queue<string>>(cacheKey) ?? new Queue<string>();

            if (queueList.IsEmptyOrNull())
                return null;

            return queueList.LastOrDefault();
        }


        private static string GenerateCacheKey(string chatID)
        {
            return CacheKey.VworldApiHistory(chatID);
        }


    }
}
