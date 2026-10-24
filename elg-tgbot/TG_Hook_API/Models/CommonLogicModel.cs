using Microsoft.AspNetCore.Mvc;
using Microsoft.Extensions.Configuration;
using target.Web.Modules.Filter;
using System;
using System.Collections.Generic;
using example.invalid;

namespace TG_Hook_API.Models
{
    public class TgBotActionInputValidateDefine
    {
        public string CurrentAction { get; set; }

        /// <summary>
        /// 如果有填寫此。會在驗證通過後。填寫redisCache緩存、再進入到下一步驟
        /// </summary>
        public string CacheKeyPrefix { get; set; }


        public List<ValidateTypeEnum> ValidateRules { get; set; }

    }


    public enum ValidateTypeEnum
    {
        ValidateAmountFormat,

        ValidateMinMaxNumberValue,

        ValidateAccountFormat,

        ValidateLength3TextFormat, //至少3個字

        ValidatePhoneFormat,

        ValidatePasswordFormat,
    }




}
