using Microsoft.AspNetCore.Http;
using Microsoft.AspNetCore.Mvc;
using Microsoft.Extensions.Logging;
using target.Utility.Utility;
using target.Utility.Utility.ThirdParty;
using System;
using System.Collections.Generic;
using example.invalid;
using System.Linq;
using System.Threading;
using System.Threading.Tasks;
using TG_Hook_API.Helper;
using TG_Hook_API.Models;
using target.Utility.Extensions;
using System.Text;
using System.Security.Cryptography;
using target.Utility;

namespace TG_Hook_API.Service
{

    public partial class VworldPlatformApiService
    {
        private BaseTelegramUtility BaseTelegramUtility { get; set; }

        public VworldPlatformApiService(string chatID , string languageCode , string botID)
        {
            this.targetChatID = chatID;
            this.LanguageCode = languageCode;
            this.targetBotID = botID;

            string tgBotToken = [REDACTED];
            BaseTelegramUtility = new BaseTelegramUtility(tgBotToken);
        }

        private static string API_URL = SettingHelper.Vworld_API_URL;
        private static string API_MD5_KEY = SettingHelper.Vworld_API_MD5_KEY;
        private static string API_AES_KEY = SettingHelper.Vworld_API_AES_KEY;
        private static string API_IV = SettingHelper.Vworld_API_IV;

        private const int SUCCESS_CODE = 1000;


        public string LanguageCode = "";
        private string targetChatID = "";
        private string targetUsetName = "";
        private string targetBotID = "";


        public (string RawResponse, bool IsAccountExist) IsAccountExist()
        {
            RequestParameters param = InstanceApiParameter("tgBot/isAccountExist");

            string myParams = EncryptByAES("");

            string md5Key = MD5Hash(myParams + API_MD5_KEY);

            var body = new
            {
                @params = myParams,
                key = md5Key
            };

            var apiResult = APIRequestHelper.RequestApiResult<VworldApiCommonResponse<object>>(param, body);
            CheckApiIsErrorResponse(apiResult, true, 1005);

            bool isExist = apiResult.ResultObject.Code == SUCCESS_CODE;
            return (apiResult.ResponseText, isExist);
        }



        public VworldApiResultBundle<VworldWalletBalanceModel> GetBalance()
        {
            var param = InstanceApiParameter("tgBot/balance");

            string myParams = EncryptByAES("");

            string md5Key = MD5Hash(myParams + API_MD5_KEY);

            var body = new
            {
                @params = myParams,
                key = md5Key
            };

            var apiResult = APIRequestHelper.RequestApiResult<VworldApiCommonResponse<VworldBalanceApiResponse>>(param, body);
            CheckApiIsErrorResponse(apiResult , true);
            CheckResultDataObjectNotNull(apiResult);

            // var myModel = apiResult.ResultObject.Data.wallet.FirstOrDefault();
            // myModel.Debug_MemberID = apiResult.ResultObject.Data.member_id;

            var model = new VworldWalletBalanceModel();
            model.IsMultipleWallet = apiResult.ResultObject.Data.is_multiple_wallet;
            
            // loop the apiResult.ResultObject.Data.wallet use foreach
            foreach (var item in apiResult.ResultObject.Data.wallet)
            {
                // if item.ID equal MemberWalletTypeEnum.MainWallet
                if (item.ID == (int)MemberWalletTypeEnum.MainWallet)
                {
                    model.MainWallet = item.Balance;
                }
                // if item.ID equal MemberWalletTypeEnum.GameWallet
                else if (item.ID == (int)MemberWalletTypeEnum.GameWallet)
                {
                    model.GameWallet = item.Balance;
                }
                // if item.ID equal MemberWalletTypeEnum.RollingChipWallet
                else if (item.ID == (int)MemberWalletTypeEnum.RollingChipWallet)
                {
                    model.RollingChipWallet = item.Balance;
                }
            }

            return InstanceApiResultBundle(apiResult.ResponseText, model);
        }



        public VworldApiResultBundle<VworldApiLazyCommonModel> Register(string phone, string agentCode)
        {
            var param = InstanceApiParameter("tgBot/register");
            
            var rawBody = new
            {
                mobile = phone,
                code = agentCode
            };

            string myParams = EncryptByAES(rawBody.ConvertToJsonString());

            string md5Key = MD5Hash(myParams + API_MD5_KEY);

            var body = new
            {
                @params = myParams,
                key = md5Key
            };

            var apiResult = APIRequestHelper.RequestApiResult<VworldApiCommonResponse<VworldHomePageInfo>>(param, body);
            bool isThrowException = false; //指定不要拋exception
            if (apiResult.ResultObject.Code == 10901) {
                isThrowException = true;
            }
            CheckApiIsErrorResponse(apiResult, isThrowException);
            return new VworldApiResultBundle<VworldApiLazyCommonModel>
            {
                Code = apiResult.ResultObject.Code,
                ResponseText = apiResult.ResponseText,
                Data = new VworldApiLazyCommonModel
                {
                    IsSuccess = apiResult.ResultObject.Code == SUCCESS_CODE
                }
            };
        }



        public VworldApiResultBundle<VworldApiLazyCommonModel> Login()
        {
            var param = InstanceApiParameter("tgBot/login");

            string myParams = EncryptByAES("");

            string md5Key = MD5Hash(myParams + API_MD5_KEY);

            var body = new
            {
                @params = myParams,
                key = md5Key
            };

            var apiResult = APIRequestHelper.RequestApiResult<VworldApiCommonResponse<object>>(param, body);
            CheckApiIsErrorResponse(apiResult, true);

            var successCheck = new VworldApiLazyCommonModel
            {
                IsSuccess = apiResult.ResultObject.Code == SUCCESS_CODE
            };

            return InstanceApiResultBundle(apiResult.ResponseText, successCheck);
        }



        public VworldApiResultBundle<VworldGuestHomePageInfo> HomePageInfoWithoutLogin()
        {
            var param = InstanceApiParameter("tgBot/homePageWithoutLogin");

            string myParams = EncryptByAES("");

            string md5Key = MD5Hash(myParams + API_MD5_KEY);

            var body = new
            {
                @params = myParams,
                key = md5Key
            };

            var apiResult = APIRequestHelper.RequestApiResult<VworldApiCommonResponse<VworldGuestHomePageInfo>>(param, body);
            CheckApiIsErrorResponse(apiResult, true); 
            CheckResultDataObjectNotNull(apiResult);

            return InstanceApiResultBundle(apiResult.ResponseText, apiResult.ResultObject);
        }



        public VworldApiResultBundle<VworldHomePageInfo> HomePageInfo()
        {
            var param = InstanceApiParameter("tgBot/homePage");

            string myParams = EncryptByAES("");

            string md5Key = MD5Hash(myParams + API_MD5_KEY);

            var body = new
            {
                @params = myParams,
                key = md5Key
            };

            var apiResult = APIRequestHelper.RequestApiResult<VworldApiCommonResponse<VworldHomePageInfo>>(param, body);

            var ignoreErrorCode = new List<int>
            {
                9998 //token錯誤
            };

            CheckApiIsErrorResponse(apiResult, false , ignoreErrorCode.ToArray());
            return InstanceApiResultBundle(apiResult.ResponseText, apiResult.ResultObject);
        }


      
        public VworldApiResultBundle<VworldApiLazyCommonModel> CheckPaymentPasswordIsSet()
        {
            var param = InstanceApiParameter("tgBot/checkPaymentPassword");

            string myParams = EncryptByAES("");

            string md5Key = MD5Hash(myParams + API_MD5_KEY);

            var body = new
            {
                @params = myParams,
                key = md5Key
            };

            var apiResult = APIRequestHelper.RequestApiResult<VworldApiCommonResponse<VworldApiLazyCommonModel>>(param, body);
            var codes = new List<int>
            {
                9014
            };

            CheckApiIsErrorResponse(apiResult, false , 9014);
            return new VworldApiResultBundle<VworldApiLazyCommonModel>
            {
                ResponseText = apiResult.ResponseText,
                Data = new VworldApiLazyCommonModel
                {
                    IsSuccess = apiResult.ResultObject.Code == SUCCESS_CODE
                }
            };
        }

        public VworldApiResultBundle<VworldApiLazyCommonModel> UpdatePaymentPassword(string newPassword)
        {
            var param = InstanceApiParameter("tgBot/setPaymentPassword");

            var rawBody = new
            {
                password = [REDACTED]
            };

            string myParams = EncryptByAES(rawBody.ConvertToJsonString());

            string md5Key = MD5Hash(myParams + API_MD5_KEY);

            var body = new
            {
                @params = myParams,
                key = md5Key
            };

            var apiResult = APIRequestHelper.RequestApiResult<VworldApiCommonResponse<VworldApiLazyCommonModel>>(param, body);
            CheckApiIsErrorResponse(apiResult, false);

            return new VworldApiResultBundle<VworldApiLazyCommonModel>
            {
                ResponseText = apiResult.ResponseText,
                Data = new VworldApiLazyCommonModel
                {
                    IsSuccess = apiResult.ResultObject?.Code == SUCCESS_CODE
                }
            };
        }


        public VworldApiResultBundle<VworldApiLazyCommonModel> ConfirmPaymentPasswordCorrect(string password)
        {
            var param = InstanceApiParameter("tgBot/confirmPaymentPassword");

            var rawBody = new
            {
                password = [REDACTED]
            };

            string myParams = EncryptByAES(rawBody.ConvertToJsonString());

            string md5Key = MD5Hash(myParams + API_MD5_KEY);

            var body = new
            {
                @params = myParams,
                key = md5Key
            };

            var apiResult = APIRequestHelper.RequestApiResult<VworldApiCommonResponse<VworldApiLazyCommonModel>>(param, body);
            CheckApiIsErrorResponse(apiResult, false);

            return new VworldApiResultBundle<VworldApiLazyCommonModel>
            {
                ResponseText = apiResult.ResponseText,
                Data = new VworldApiLazyCommonModel
                {
                    IsSuccess = apiResult.ResultObject.Code == SUCCESS_CODE
                }
            };
        }

        public VworldApiResultBundle<List<VworldAvailablePaymentTypeItem>> GetAvailableDepositPaymentTypes()
        {
            var param = InstanceApiParameter("tgBot/deposit");

            string myParams = EncryptByAES("");

            string md5Key = MD5Hash(myParams + API_MD5_KEY);

            var body = new
            {
                @params = myParams,
                key = md5Key
            };

            var apiResult = APIRequestHelper.RequestApiResult<VworldApiCommonResponse<List<VworldAvailablePaymentTypeItem>>>(param, body);
            CheckApiIsErrorResponse(apiResult, true);

            return InstanceApiResultBundle(apiResult.ResponseText, apiResult.ResultObject);
        }


        public VworldApiResultBundle<VworldPaymentTypeSettingInfo> GetTargetPaymentTypeSetting(int paymentType)
        {
            var param = InstanceApiParameter("tgBot/depositDetail");

            var rawBody = new
            {
                depositDetail = paymentType
            };

            string myParams = EncryptByAES(rawBody.ConvertToJsonString());

            string md5Key = MD5Hash(myParams + API_MD5_KEY);

            var body = new
            {
                @params = myParams,
                key = md5Key
            };

            var apiResult = APIRequestHelper.RequestApiResult<VworldApiCommonResponse<VworldPaymentTypeSettingInfo>>(param, body);
            CheckApiIsErrorResponse(apiResult, true);
            CheckResultDataObjectNotNull(apiResult);

            return InstanceApiResultBundle(apiResult.ResponseText, apiResult.ResultObject);
        }



        public VworldApiResultBundle<VworldDepositPaymentOrderResult> SendDepositOrder(VworldDepositPaymentOrderRequestParameter model)
        {
            var param = InstanceApiParameter("tgBot/depositOrder");

            var rawBody = new
            {
                order_type_id = model.PaymentTypeID,
                amount = model.Amount
            };

            string myParams = EncryptByAES(rawBody.ConvertToJsonString());

            string md5Key = MD5Hash(myParams + API_MD5_KEY);

            var body = new
            {
                @params = myParams,
                key = md5Key
            };

            var apiResult = APIRequestHelper.RequestApiResult<VworldApiCommonResponse<VworldDepositPaymentOrderResult>>(param, body);
            CheckApiIsErrorResponse(apiResult, false);

            return InstanceApiResultBundle(apiResult.ResponseText, apiResult.ResultObject);
        }


        public VworldApiResultBundle<List<VworldAvailableWithdrawalTypeItem>> GetAvailableWithdrawalTypes()
        {
            var param = InstanceApiParameter("tgBot/withdrawal");

            string myParams = EncryptByAES("");

            string md5Key = MD5Hash(myParams + API_MD5_KEY);

            var body = new
            {
                @params = myParams,
                key = md5Key
            };

            var apiResult = APIRequestHelper.RequestApiResult<VworldApiCommonResponse<List<VworldAvailableWithdrawalTypeItem>>>(param, body);
            CheckApiIsErrorResponse(apiResult, true);
            CheckResultDataObjectNotNull(apiResult);

            return InstanceApiResultBundle(apiResult.ResponseText, apiResult.ResultObject);
        }

        public VworldApiResultBundle<VworldWithdrawalFlowLimit> GetWithdrawalFlowLimit()
        {
            var param = InstanceApiParameter("tgBot/flowLimit");

            string myParams = EncryptByAES("");

            string md5Key = MD5Hash(myParams + API_MD5_KEY);

            var body = new
            {
                @params = myParams,
                key = md5Key
            };

            var apiResult = APIRequestHelper.RequestApiResult<VworldApiCommonResponse<VworldWithdrawalFlowLimit>>(param, body);
            CheckApiIsErrorResponse(apiResult, true);
            CheckResultDataObjectNotNull(apiResult);

            return InstanceApiResultBundle(apiResult.ResponseText, apiResult.ResultObject);
        }


        public VworldApiResultBundle<VworldWithdrawalSettingConfig> GetWithdrawalConfigSetting(int bankID)
        {
            var param = InstanceApiParameter("tgBot/withdrawalDetail");

            var rawBody = new
            {
                bank_id = bankID
            };

            string myParams = EncryptByAES("");

            string md5Key = MD5Hash(myParams + API_MD5_KEY);

            var body = new
            {
                @params = myParams,
                key = md5Key
            };

            var apiResult = APIRequestHelper.RequestApiResult<VworldApiCommonResponse<VworldWithdrawalSettingConfig>>(param, body);
            CheckApiIsErrorResponse(apiResult, true);
            CheckResultDataObjectNotNull(apiResult);

            return InstanceApiResultBundle(apiResult.ResponseText, apiResult.ResultObject);
        }


        public VworldApiResultBundle<VworldGameListApiResult> GetGamesByCategoryType(int categoryID)
        {
            string url = "recommendGame";

            if(categoryID == (int)GameCategoryEnum.Recently)
            {
                url = "recentGame";
            }

            var param = InstanceApiParameter("tgBot/" + url);

            string myParams = EncryptByAES("");

            string md5Key = MD5Hash(myParams + API_MD5_KEY);

            var body = new
            {
                @params = myParams,
                key = md5Key
            };

            var apiResult = APIRequestHelper.RequestApiResult<VworldApiCommonResponse<VworldGameListApiResult>>(param, body);
            CheckApiIsErrorResponse(apiResult, true);
            CheckResultDataObjectNotNull(apiResult);

            var gameLsit = apiResult.ResultObject?.Data?.game_list;

            if (gameLsit.IsNotEmptyNull())
            {
                foreach(var item in apiResult.ResultObject?.Data?.game_list)
                {
                    if(string.IsNullOrWhiteSpace(item.name))
                    {
                        item.name = item.game_id.ToString();
                    }
                }
            }

            return InstanceApiResultBundle(apiResult.ResponseText, apiResult.ResultObject);
        }

        public VworldApiResultBundle<VworldUrlGenerateResult> GetLobbyUrl()
        {
            var param = InstanceApiParameter("tgBot/lobby");

            string myParams = EncryptByAES("");

            string md5Key = MD5Hash(myParams + API_MD5_KEY);

            var body = new
            {
                @params = myParams,
                key = md5Key
            };

            var apiResult = APIRequestHelper.RequestApiResult<VworldApiCommonResponse<VworldUrlGenerateResult>>(param, body);
            CheckApiIsErrorResponse(apiResult, true);
            CheckResultDataObjectNotNull(apiResult);

            return InstanceApiResultBundle(apiResult.ResponseText, apiResult.ResultObject);
        }


        public VworldApiResultBundle<VworldGameLaunchApiResult> GetGameLaunchUrl(int gameID)
        {
            var param = InstanceApiParameter("tgBot/game");

            var rawBody = new
            {
                game_id = gameID
            };

            string myParams = EncryptByAES(rawBody.ConvertToJsonString());

            string md5Key = MD5Hash(myParams + API_MD5_KEY);

            var body = new
            {
                @params = myParams,
                key = md5Key
            };

            var apiResult = APIRequestHelper.RequestApiResult<VworldApiCommonResponse<VworldGameLaunchApiResult>>(param, body);
            CheckApiIsErrorResponse(apiResult, true);
            CheckResultDataObjectNotNull(apiResult);

            return InstanceApiResultBundle(apiResult.ResponseText, apiResult.ResultObject);
        }


        public VworldApiResultBundle<VworldPaymentRecordApiResult> GetPaymentRecordByDaysRange(int daysRange)
        {
            var param = InstanceApiParameter("tgBot/paymentLog");

            var rawBody = new
            {
                dateRange = daysRange
            };

            string myParams = EncryptByAES(rawBody.ConvertToJsonString());

            string md5Key = MD5Hash(myParams + API_MD5_KEY);

            var body = new
            {
                @params = myParams,
                key = md5Key
            };

            var apiResult = APIRequestHelper.RequestApiResult<VworldApiCommonResponse<VworldPaymentRecordApiResult>>(param, body);
            CheckApiIsErrorResponse(apiResult, true);
            CheckResultDataObjectNotNull(apiResult);

            return InstanceApiResultBundle(apiResult.ResponseText, apiResult.ResultObject);
        }


        public VworldApiResultBundle<VworldSendWithdrawalOrder> SendWithdrawalOrder(VworldSendWithdrawalOrderParameter model)
        {
            var param = InstanceApiParameter("tgBot/setWithdrawal");

            var rawBody = new
            {
                bank_id = model.BankID,
                amount = model.Amount
            };

            string myParams = EncryptByAES(rawBody.ConvertToJsonString());

            string md5Key = MD5Hash(myParams + API_MD5_KEY);

            var body = new
            {
                @params = myParams,
                key = md5Key
            };

            var apiResult = APIRequestHelper.RequestApiResult<VworldApiCommonResponse<VworldSendWithdrawalOrder>>(param, body);
            CheckApiIsErrorResponse(apiResult, false);

            var result = InstanceApiResultBundle(apiResult.ResponseText, apiResult.ResultObject);
            result.Data = new VworldSendWithdrawalOrder
            {
                IsSuccess = apiResult.ResultObject?.Code == SUCCESS_CODE
            };

            return result;
        }


        private VworldApiResultBundle<T> InstanceApiResultBundle<T>(string responseText, T model) where T : class
        {
            return new VworldApiResultBundle<T>
            {
                Code = SUCCESS_CODE,
                IsSuccessCode = true,
                ResponseText = responseText,
                Data = model
            };
        }


        private VworldApiResultBundle<T> InstanceApiResultBundle<T>(string responseText, VworldApiCommonResponse<T> model) where T : class
        {
            return new VworldApiResultBundle<T>
            {
                Code = model.Code,
                IsSuccessCode = model.Code == SUCCESS_CODE,
                ResponseText = responseText,
                Data = model.Data
            };
        }

        public VworldApiResultBundle<VworldGetHotVendorListResult> GetHotVendorList()
        {
            var param = InstanceApiParameter("tgBot/getHotVendorList");

            var rawBody = new
            {
            };

            string myParams = EncryptByAES(rawBody.ConvertToJsonString());

            string md5Key = MD5Hash(myParams + API_MD5_KEY);

            var body = new
            {
                @params = myParams,
                key = md5Key
            };

            var apiResult = APIRequestHelper.RequestApiResult<VworldApiCommonResponse<VworldGetHotVendorListResult>>(param, body);
            CheckApiIsErrorResponse(apiResult, true);
            CheckResultDataObjectNotNull(apiResult);

            return InstanceApiResultBundle(apiResult.ResponseText, apiResult.ResultObject);
            /*
            // Mocked data
            var vendorList = new List<VworldGetHotVendorListItem>
            {
                new VworldGetHotVendorListItem { 
                    vendor_id = 50, vendor_name = "Pegasus", vendor_image = "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/vworld/game/icon/ROLLING/target.png", launch_url = "https://example.invalid" 
                },
                new VworldGetHotVendorListItem { 
                    vendor_id = 22, vendor_name = "VPower", vendor_image = "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/vworld/game/icon/RUNLIGHT/Vpower-runlight.png", launch_url = "https://example.invalid" 
                },
                new VworldGetHotVendorListItem { 
                    vendor_id = 89, vendor_name = "Clotplay", vendor_image = "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/vworld/game/icon/SLOT/CLOT%20PLAY.png", launch_url = "https://example.invalid" 
                },
            };

            // Create the result bundle
            var result = new VworldApiResultBundle<VworldGetHotVendorListResult>
            {
                Code = SUCCESS_CODE,
                IsSuccessCode = true,
                ResponseText = "Success",
                Data = new VworldGetHotVendorListResult
                {
                    list = vendorList
                }
            };

            return result;
            */
        }


    }




}

