using Lck.Utility.Extensions.Helper;
using System;
using System.Collections.Generic;
using System.Linq;
using System.Text;
using System.Threading.Tasks;

namespace Lck.Utility.Extensions.Helper
{
    public class CacheKey
    {
      
        public static string Deposit_GCashAccount(string chatID)
        {
            return $"Deposit_GCashAccount{chatID}";
        }

        public static string Deposit_SelectPaymentType(string chatID)
        {
            return $"Deposit_SelectPaymentType{chatID}";
        }

        public static string Withdrawal_SelectWithdrawalBankID(string chatID)
        {
            return $"Withdrawal_SelectWithdrawalBankID{chatID}";
        }


        public static string Withdrawal_GCashAccount(string chatID)
        {
            return $"Withdrawal_GCashAccount{chatID}";
        }

        public static string SelectLanguage(string chatID)
        {
            return $"SelectLanguage_{chatID}";
        }

        public static string Register_PhoneNumber(string chatID)
        {
            return $"Register_PhoneNumber{chatID}";
        }

        public static string SetToLoginKeepStatus(string chatID)
        {
            return $"SetToLoginKeepStatus{chatID}";
        }

        public static string CocurrentLocker(string chatID)
        {
            return $"CocurrentLocker{chatID}";
        }

        public static string DefaultAgentCode(string chatID)
        {
            return $"DefaultAgentCode{chatID}";
        }

        public static string AgentCodeByInviteLink(string chatID)
        {
            return $"AgentCodeByInviteLink{chatID}";
        }

        public static string TgBotStepHistory(string chatID)
        {
            return $"TgBotStepHistory{chatID}";
        }

        public static string TgMessageApiHistory(string chatID)
        {
            return $"TgMessageApiHistory{chatID}";
        }

        public static string VworldApiHistory(string chatID)
        {
            return $"VworldApiHistory_{chatID}";
        }
    }


}
