using Lck.Utility.Extensions.Helper;
using System;
using System.Collections.Generic;
using System.Linq;
using System.Threading.Tasks;
using target.Utility.Extensions;
using TG_Hook_API.Models;

namespace TG_Hook_API.Helper
{
    public static class StepHistoryQueueHelper
    {

        public static void AppendToQueue(string chatUserID, string lastStep)
        {
            string cacheKey = CacheKey.TgBotStepHistory(chatUserID);

            var queueList = CacheHelper.GetCacheValue<Queue<string>>(cacheKey) ?? new Queue<string>();

            queueList.Enqueue(lastStep);

            if (queueList.Count() >= 20)
            {
                queueList.Dequeue();
            }

            int cacheTime = SystemConstant.Cache_StepHistory_ExpiredHours;
            CacheHelper.SetCacheValue(cacheKey, queueList, new TimeSpan(0, cacheTime, 0, 0));
        }


        public static void RemoveAllHistory(string chatUserID)
        {
            string cacheKey = CacheKey.TgBotStepHistory(chatUserID);

            CacheHelper.RemoveCacheByKey(cacheKey);
        }


        public static string GetLastStepHistory(string chatUserID)
        {
            string cacheKey = CacheKey.TgBotStepHistory(chatUserID);

            var queueList = CacheHelper.GetCacheValue<Queue<string>>(cacheKey) ?? new Queue<string>();

            if (queueList.IsEmptyOrNull())
                return null;

            return queueList.LastOrDefault();
        }

        public static string GetLastStepsHistories(string chatUserID , int fetchCount = 5)
        {
            string cacheKey = CacheKey.TgBotStepHistory(chatUserID);

            var queueList = (CacheHelper.GetCacheValue<Queue<string>>(cacheKey) ?? new Queue<string>()).ToList();

            if (queueList.IsEmptyOrNull())
                return "";

            int existArrayCount = queueList.Count();

            if(existArrayCount <= fetchCount)
            {
                fetchCount = existArrayCount;
            }

            string text = "";

            for(int i = 1; i <= (fetchCount); i++)
            {
                text += $"{queueList[existArrayCount - i]}｜";
            }

            return text;
        }


    }
}
