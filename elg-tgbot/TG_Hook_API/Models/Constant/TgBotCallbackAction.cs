namespace target.Utility.Utility.ThirdParty
{
    public class TgBotCallbackAction
    {
        public const string None = "None";
        public const string NotImplement = "NotImplement";
        public const string RegisterOrLogin = "RegisterOrLogin";
        public const string Register_WatingReply_RequestPhoneNumber = "Register_WatingReply_RequestPhoneNumber";
        public const string Register_WatingReply_AgentCode = "Register_WatingReply_AgentCode";
        public const string Register_SubmitCreateAccount = "Register_SubmitCreateAccount";
        public const string Register_SubmitCreateAccountByDefaultAgentCode = "Register_SubmitCreateAccountByDefaultAgentCode";


        public const string Deposit_ShowAvailablePaymentTypes = "Deposit_ShowAvailablePaymentTypes";
        //public const string Deposit_WatingReply_PaymentCashAccount = "Deposit_WatingReply_PaymentCashAccount";
        public const string Deposit_WatingReply_DepositAmount = "Deposit_WatingReply_DepositAmount";
        public const string Deposit_SubmitOrderAndGetLinkButton = "Deposit_SubmitOrderAndGetLinkButton";


        public const string Withdrwal_Extra_WaitingReply_SetPaymentPassword = [REDACTED]";
        public const string Withdrwal_Extra_SubmitUpdatePaymentPassword = [REDACTED]";

        public const string Withdrwal_Process = "Withdrwal_Process";

        public const string Withdrwal_ShowAvailableWithdrawalType = "Withdrwal_ShowAvailableWithdrawalType";
        public const string Withdrwal_WatingReply_PaymentPassword = [REDACTED]";
        public const string Withdrwal_WatingReply_PaymentCashAccount = "Withdrwal_WatingReply_PaymentCashAccount";
        public const string Withdrwal_WatingReply_WithdrawalAmount = "Withdrwal_WatingReply_WithdrawalAmount";
        public const string Withdrwal_SendOrder_SuccessMessage = "Withdrwal_SendOrder_SuccessMessage";


        public const string Games_ShowAvailableGameCategory = "Games_ShowAvailableGameCategory";
        public const string Games_ShowGameListBySpecificCategory = "Games_ShowGameListBySpecificCategory";
        public const string Games_LaunchTargetGame = "Games_LaunchTargetGame";


        public const string Records_ShowDaysRangeFilter = "Records_ShowDaysRangeFilter";
        public const string Records_GetRecordByDaysFilter = "Records_GetRecordByDaysFilter";

        public const string CancelAction = "CancelAction";
        public const string GetCsLink = "GetCsLink";
        public const string LogoutAndBack = "LogoutAndBack";
        public const string HomePageWithoutLogin = "HomePageWithoutLogin";
        public const string HomePage = "HomePage";
        public const string SelectLanguage = "SelectLanguage";
        public const string SelectLanguage_SetTargetLanguageCode = "SelectLanguage_SetTargetLanguageCode";
        public const string ErrorMessage = "ErrorMessage";
    }

    public class TgBotCallbackExtraParameter
    {
        public const string LanguageCode = "languageCode";
        public const string PaymentType = "paymentType";
        //public const string WithdrawalType = "withdrawalType";
        public const string BankID = "BankID";
        public const string GameID = "gameID";
        public const string Category = "category";
        public const string DaysRange = "daysRange";
    }


}
