using Lck.Utility.Extensions.Helper;
using target.Utility.Extensions;
using System.Collections.Generic;
using TG_Hook_API.Models;

namespace target.Utility.Utility.ThirdParty
{
    public class TgBotCallbackActionDefineHelper
    {
        public static List<TgBotActionInputValidateDefine> WatingReplyActionDefine = new List<TgBotActionInputValidateDefine>();
        public static List<string> StepSequenceDefine = new List<string>();


        static TgBotCallbackActionDefineHelper()
        {
            InitializeWaitingReplyRuleDefine();

            InitializeStepSequencePrviousAndNext();
        }       


        private static void InitializeWaitingReplyRuleDefine()
        {
            //只有WatingReply類的才需要定義此。如果callback_action類型的不需要
            //定義每種WatingReply流程 用戶於TG輸入文字後、是否有需要檢查的欄位

            //註冊
            WatingReplyActionDefine.Add(new TgBotActionInputValidateDefine
            {
                CurrentAction = TgBotCallbackAction.Register_WatingReply_RequestPhoneNumber,
                ValidateRules = ValidateTypeEnum.ValidatePhoneFormat.ToListCollection(),
                CacheKeyPrefix = CacheKey.Register_PhoneNumber(""),
            });

            WatingReplyActionDefine.Add(new TgBotActionInputValidateDefine
            {
                CurrentAction = TgBotCallbackAction.Register_WatingReply_AgentCode,
                ValidateRules = ValidateTypeEnum.ValidateLength3TextFormat.ToListCollection(),
            });


            //開分
            //WatingReplyActionDefine.Add(new TgBotActionInputValidateDefine
            //{
            //    CurrentAction = TgBotCallbackAction.Deposit_WatingReply_PaymentCashAccount,
            //    ValidateRules = ValidateTypeEnum.ValidateAccountFormat.ToListCollection(),
            //    CacheKeyPrefix = CacheKey.Deposit_GCashAccount(""),
            //});

            WatingReplyActionDefine.Add(new TgBotActionInputValidateDefine
            {
                CurrentAction = TgBotCallbackAction.Deposit_WatingReply_DepositAmount,
                ValidateRules = new List<ValidateTypeEnum>
                {
                    ValidateTypeEnum.ValidateAmountFormat,
                }
            });


            // 洗分
            WatingReplyActionDefine.Add(new TgBotActionInputValidateDefine
            {
                CurrentAction = TgBotCallbackAction.Withdrwal_WatingReply_PaymentPassword,
                ValidateRules = ValidateTypeEnum.ValidatePasswordFormat.ToListCollection(),
            });

            WatingReplyActionDefine.Add(new TgBotActionInputValidateDefine
            {
                CurrentAction = TgBotCallbackAction.Withdrwal_WatingReply_PaymentCashAccount,
                ValidateRules = ValidateTypeEnum.ValidateAccountFormat.ToListCollection(),
                CacheKeyPrefix = CacheKey.Withdrawal_GCashAccount(""),
            });

            WatingReplyActionDefine.Add(new TgBotActionInputValidateDefine
            {
                CurrentAction = TgBotCallbackAction.Withdrwal_WatingReply_WithdrawalAmount,
                ValidateRules = new List<ValidateTypeEnum>
                {
                    ValidateTypeEnum.ValidateAmountFormat,
                }
            });

            //洗分額外流程
            WatingReplyActionDefine.Add(new TgBotActionInputValidateDefine
            {
                CurrentAction = TgBotCallbackAction.Withdrwal_Extra_WaitingReply_SetPaymentPassword,
                ValidateRules = new List<ValidateTypeEnum>
                {
                    ValidateTypeEnum.ValidatePasswordFormat,
                }
            });
        }

        private static void InitializeStepSequencePrviousAndNext()
        {
            //定義各個流程的上一步與下一步 (當輸入完成後會自動前往下一步)
            StepSequenceDefine = new List<string>()
            {
                TgBotCallbackAction.HomePageWithoutLogin,
                TgBotCallbackAction.Register_WatingReply_RequestPhoneNumber,
                TgBotCallbackAction.Register_WatingReply_AgentCode,
                TgBotCallbackAction.Register_SubmitCreateAccount,


                TgBotCallbackAction.HomePage,
                TgBotCallbackAction.Deposit_ShowAvailablePaymentTypes,
                TgBotCallbackAction.Deposit_WatingReply_DepositAmount,
                TgBotCallbackAction.Deposit_SubmitOrderAndGetLinkButton,


                TgBotCallbackAction.HomePage,
                TgBotCallbackAction.Withdrwal_Extra_WaitingReply_SetPaymentPassword,
                TgBotCallbackAction.Withdrwal_Extra_SubmitUpdatePaymentPassword,


                TgBotCallbackAction.HomePage,
                TgBotCallbackAction.Withdrwal_ShowAvailableWithdrawalType,
                TgBotCallbackAction.Withdrwal_WatingReply_PaymentPassword,
                TgBotCallbackAction.Withdrwal_WatingReply_WithdrawalAmount,
                TgBotCallbackAction.Withdrwal_SendOrder_SuccessMessage,

              
            };


        }

    }



}
