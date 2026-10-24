using Microsoft.AspNetCore.Mvc;
using Microsoft.Extensions.Configuration;
using target.Web.Modules.Filter;
using System;
using System.Collections.Generic;
using example.invalid;

namespace TG_Hook_API.Models
{
    public class SystemConstant
    {
        public const int Cache_StepHistory_ExpiredHours = 8;
        public const int Cache_StepDataCache_ExpiredHours = 9;
        public const string LanguageCode_ZH = "zh-tw";
        public const string LanguageCode_EN = "en-us";
    }

}
