using Lck.Utility.Extensions.Helper;
using Microsoft.AspNetCore.Http;
using Microsoft.AspNetCore.Mvc;
using Microsoft.Extensions.Logging;
using target.Utility;
using target.Utility.Helper.HttpContext;
using target.Utility.Utility.ThirdParty;
using System;
using System.Collections.Generic;
using System.Collections.Specialized;
using example.invalid;
using System.Linq;
using System.Threading;
using System.Threading.Tasks;
using TG_Hook_API.Helper;
using TG_Hook_API.Models;

namespace TG_Hook_API.Service
{

    public partial class TgBotCache
    {

        public string TargetChatID { get; set; }

        private const int DefaultExpiredHours = SystemConstant.Cache_StepDataCache_ExpiredHours;


        public TgBotCache(string chatID)
        {
            this.TargetChatID = chatID;
        }

        public void SetCache_LanguageCode(string langCode)
        {
            string cacheKey = CacheKey.SelectLanguage(TargetChatID);
            CacheHelper.SetCacheValue(cacheKey, langCode, new TimeSpan(9999, 0, 0, 0));
        }

        public void SetCache_LoginStatus()
        {
            string cacheKey = CacheKey.SetToLoginKeepStatus(TargetChatID);
            CacheHelper.SetCacheValue(cacheKey, "1", new TimeSpan(4, 0, 0));
        }

        public void SetCache_DefaultAgentCode(string agentCode)
        {
            string cacheKey = CacheKey.DefaultAgentCode(TargetChatID);
            CacheHelper.SetCacheValue(cacheKey, agentCode, new TimeSpan(DefaultExpiredHours, 0, 0));
        }

        public void SetCache_SetAgentCodeByInviteLink(string agentCode)
        {
            string cacheKey = CacheKey.AgentCodeByInviteLink(TargetChatID);
            CacheHelper.SetCacheValue(cacheKey, agentCode, new TimeSpan(DefaultExpiredHours * 5, 0, 0));
        }


        public void SetCache_SelectPaymentType(int paymentType)
        {
            string cacheKey = CacheKey.Deposit_SelectPaymentType(TargetChatID);
            CacheHelper.SetCacheValue(cacheKey, paymentType, new TimeSpan(DefaultExpiredHours, 0, 0));
        }

        public void SetCache_Withdrawal_SelectWithdrawalBankID(int bankID)
        {
            string cacheKey = CacheKey.Withdrawal_SelectWithdrawalBankID(TargetChatID);
            CacheHelper.SetCacheValue(cacheKey, bankID, new TimeSpan(DefaultExpiredHours, 0, 0));
        }

        public void RemoveCache_SetToLoginKeepStatus()
        {
            string cacheKey = CacheKey.SetToLoginKeepStatus(TargetChatID);
            CacheHelper.RemoveCacheByKey(cacheKey);
        }

        public void RemoveCache_Register_PhoneNumber()
        {
            string cacheKey = CacheKey.Register_PhoneNumber(TargetChatID);
            CacheHelper.RemoveCacheByKey(cacheKey);
        }

        public string LoginKeepStatus
        {
            get
            {
                string cacheKey = CacheKey.SetToLoginKeepStatus(TargetChatID);
                return GetCacheStringValueByKey(cacheKey);
            }
        }

        public string DefaultAgentCode
        {
            get 
            {
                string cacheKey = CacheKey.DefaultAgentCode(TargetChatID);
                return GetCacheStringValueByKey(cacheKey);
            }
        }

        public string Register_PhoneNumber
        {
            get
            {
                string cacheKey = CacheKey.Register_PhoneNumber(TargetChatID);
                return GetCacheStringValueByKey(cacheKey);
            }
        }


        public string Register_AgentCodeByInviteLink
        {
            get
            {
                string cacheKey = CacheKey.AgentCodeByInviteLink(TargetChatID);
                return GetCacheStringValueByKey(cacheKey);
            }
        }

        public string Deposit_LastSelectPaymentType
        {
            get
            {
                string cacheKey = CacheKey.Deposit_SelectPaymentType(TargetChatID);
                return GetCacheStringValueByKey(cacheKey);
            }
        }

        public string Deposit_GCashAccount
        {
            get
            {
                string cacheKey = CacheKey.Deposit_GCashAccount(TargetChatID);
                return GetCacheStringValueByKey(cacheKey);
            }
        }

        public string Withdrawal_SelectWithdrawalBankID
        {
            get
            {
                string cacheKey = CacheKey.Withdrawal_SelectWithdrawalBankID(TargetChatID);
                return GetCacheStringValueByKey(cacheKey);
            }
        }

        public string Withdrawal_GCashAccount
        {
            get
            {
                string cacheKey = CacheKey.Withdrawal_GCashAccount(TargetChatID);
                return GetCacheStringValueByKey(cacheKey);
            }
        }


        public string GetCacheStringValueByKey(string cacheKey)
        {
            return CacheHelper.GetCacheValue<string>(cacheKey);
        }


    }


}
