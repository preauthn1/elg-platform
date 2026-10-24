using Microsoft.AspNetCore.Mvc;
using Microsoft.Extensions.Configuration;
using target.Web.Modules.Filter;
using System;
using System.Collections.Generic;
using example.invalid;

namespace TG_Hook_API.Models
{
    public enum TgBotProcessEnum
    {
        Register_Stpe1001, //顯示授權電話
        Register_Stpe2001, //要求填寫代理分享碼
        Register_Success, 

        Login,
        HomePageWithoutLogin,
        HomePage,

        ErrorMessage,
    }

}
