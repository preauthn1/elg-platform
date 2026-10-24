using Lck.Utility.Extensions.Helper;
using System;
using System.Collections.Generic;
using System.Linq;
using System.Threading.Tasks;
using target.Utility.Extensions;
using TG_Hook_API.Models;

namespace TG_Hook_API.Helper
{
    public static class TgMessageApiHistoryQueueHelper
    {

        public static void AppendToQueue(string chatID, TgMessageHistoryLogModel myObject)
        {
            string cacheKey = GenerateCacheKey(chatID);

            var queueList = CacheHelper.GetCacheValue<Queue<TgMessageHistoryLogModel>>(cacheKey) ?? new Queue<TgMessageHistoryLogModel>();

            queueList.Enqueue(myObject);

            if (queueList.Count() >= 20)
            {
                queueList.Dequeue();
            }

            if (queueList.Count() >= 20)
            {
                queueList.Dequeue();
            }

            CacheHelper.SetCacheValue(cacheKey, queueList , new TimeSpan(9999 , 0 , 0, 0));
        }


        public static List<TgMessageHistoryLogModel> GetAllHistory(string chatID)
        {
            string cacheKey = GenerateCacheKey(chatID);

            var queueList = CacheHelper.GetCacheValue<Queue<TgMessageHistoryLogModel>>(cacheKey) ?? new Queue<TgMessageHistoryLogModel>();
            // queueList reverse sort
            queueList = new Queue<TgMessageHistoryLogModel>(queueList.Reverse());
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
            return CacheKey.TgMessageApiHistory(chatID);
        }


    }
}
