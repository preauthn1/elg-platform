using Microsoft.AspNetCore.Mvc;
using Microsoft.Extensions.Logging;
using target.Utility;
using target.Utility.Helper.HttpContext;
using target.Utility.Utility.ThirdParty;
using System;
using System.Collections.Generic;
using example.invalid;
using System.Linq;
using System.Text;
using System.Threading.Tasks;
using TG_Hook_API.Helper;

namespace WebApplication3_20220202_003.Controllers
{
    public partial class HomePageController : ControllerBase
    {

        [Route("")]
        [HttpGet]
        public object HomePage(string password = "")
        {
            var qqqq = MyHttpContext.Current;

            var debugInfo = "";
            object debugInfo2 = null;

            if (password == "3969c610c23c" || IPHelper.IsCompanyIP())
            {
                debugInfo = $"{SettingHelper.TgBotToken}｜{SettingHelper.Vworld_API_URL}";
                debugInfo2 = RuntimeHelper.GetHomePageDebugInformation();
            }

            return new
            {
                Msg = "HomePage",
                Env = SettingHelper.Env,
                Name = SettingHelper.WebSiteName,
                MyIP = IPHelper.GetIP(),
                debugInfo = debugInfo,
                debugInfo2 = debugInfo2,
            };
        }



    }
}
