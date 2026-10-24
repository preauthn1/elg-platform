using Microsoft.AspNetCore.Mvc;
using Microsoft.Extensions.Configuration;
using target.Web.Modules.Filter;
using System;
using System.Collections.Generic;
using example.invalid;

namespace TG_Hook_API.Models
{
    public class VworldApiCommonResponse<T>
    {

        public int Code { get; set; }

        public string Message { get; set; }

        public T Data { get; set; }

    }


    public class VworldApiPagination
    {
        public int per_page { get; set; }
        public int total { get; set; }
        public int pages { get; set; }
        public int previous { get; set; }
        public int current { get; set; }
        public int next { get; set; }
    }


    public class VworldBalanceApiResponse
    {
        public long member_id { get; set; }
        public bool is_multiple_wallet { get; set; }

        public List<VworldBalanceWalletItem> wallet { get; set; }

    }


    public class VworldGuestHomePageInfo
    {
        public string tg { get; set; }
        public string cs_tg { get; set; }
        public string agent_id { get; set; }
        public string invite_code { get; set; }
        public string platform_name { get; set; }

    }


    public class VworldHomePageInfo
    {
        public string tg { get; set; }
        public string cs_tg { get; set; }
        public string agent_id { get; set; }
        public string invite_code { get; set; }
        public string event_link { get; set; }
        public string cs_link { get; set; }
        public string withdrawal_link { get; set; }
        public string launch_link { get; set; }
        public string wallet_link { get; set; }

    }


    public class VworldApiResultBundle<T> where T : class
    {
        public VworldApiResultBundle()
        {

        }

        public VworldApiResultBundle(string rawResponse, T model)
        {
            this.ResponseText = rawResponse;
            this.Data = model;
        }

        public string ResponseText { get; set; }

        public int Code { get; set; }

        public bool IsSuccessCode { get; set; }

        public T Data { get; set; }
    }


    public class VworldApiLazyCommonModel
    {

        public bool IsSuccess { get; set; }
    }




    public class VworldAvailablePaymentTypeItem
    {
        public string order_type_id { get; set; }
        public string name { get; set; }
        public string cf_id { get; set; }
        public string cf_name { get; set; }
        public string image_path { get; set; }
        public string popup_text { get; set; }
        public string url { get; set; }
    }


    public class VworldPaymentTypeSettingInfo
    {
        public string name { get; set; }
        public string order_type_id { get; set; }
        public string cf_id { get; set; }
        public string acf_id { get; set; }
        public string cf_name { get; set; }
        public decimal min_amount { get; set; }
        public decimal max_amount { get; set; }
    }



    public class VworldAvailableWithdrawalTypeItem
    {
        public string withdrawal_type_id { get; set; }
        public string name { get; set; }
        public string bank_id { get; set; }
        public string bank_name { get; set; }
        public string bank_info { get; set; }
        public string bank_code { get; set; }
        public string bank_type { get; set; }
        public string tax_type { get; set; }
        public string tax { get; set; }
    }


    public class VworldWithdrawalFlowLimit
    {     
        public decimal total_flow_limit { get; set; }
        public decimal reach { get; set; }
        public decimal flow_limit { get; set; }
    }



    public class VworldDepositPaymentOrderRequestParameter
    {
        public string GCashAccount { get; set; }

        public string PaymentTypeID { get; set; }

        public decimal Amount { get; set; }
    }

    public class VworldDepositPaymentOrderResult
    {
        public int type { get; set; }

        public string url { get; set; }
    }


    public class VworldWithdrawalTypes
    {
        public string name { get; set; }
        public decimal min { get; set; }
        public decimal max { get; set; }
    }



    public class VworldWithdrawalSettingConfig
    {
        public string name { get; set; }

        public decimal min_amount { get; set; }

        public decimal max_amount { get; set; }

    }



    public class VworldGameListApiResult
    {
        public List<VworldGameListItems> game_list { get; set; }
    }


    public class VworldUrlGenerateResult
    {
        public string url { get; set; }
    }



    public class VworldGameLaunchApiResult
    {
        public int game_id { get; set; }

        public string Url { get; set; }
    }



    public class VworldPaymentRecordApiResult
    {
        public List<VworldPaymentRecordItems> Record { get; set; }


        public VworldApiPagination Pagination { get; set; }

    }



    public class VworldPaymentRecordItems
    {
        public string id { get; set; }
        public string type { get; set; }
        public string order_type { get; set; }
        public decimal amount { get; set; }
        public decimal point { get; set; }
        public string status { get; set; }
        public string status_name { get; set; }
        public string created_at { get; set; }
        public string updated_at { get; set; }
    }



    public class VworldGameListItems
    {
        public string name { get; set; }

        public int game_id { get; set; }

        public string url { get; set; }
    }



    public class VworldSendWithdrawalOrderParameter
    {
        public int BankID { get; set; }

        public decimal Amount { get; set; }

    }

    public class VworldSendWithdrawalOrder
    {

        public bool IsSuccess { get; set; }
    }



    public class VworldBalanceWalletItem
    {
        public long ID { get; set; }

        public string Name { get; set; }

        public decimal Balance { get; set; }

    }

    public class VworldWalletBalanceModel
    {
        public int Debug_MemberID { get; set; }
        public bool IsMultipleWallet { get; set; }
        public decimal MainWallet { get; set; }
        public decimal GameWallet { get; set; }
        public decimal RollingChipWallet { get; set; }
    }
    public class VworldGetHotVendorListResult
    {
        public List<VworldGetHotVendorListItem> list { get; set; }
    }

    public class VworldGetHotVendorListItem
    {
        public int vendor_id { get; set; }
        public string vendor_image { get; set; }
        public string vendor_name { get; set; }
        public string launch_url { get; set; }
    }


}
