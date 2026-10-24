using Microsoft.AspNetCore.Mvc.Filters;
using Microsoft.AspNetCore.Http;
using Microsoft.AspNetCore.Mvc;
using example.invalid.Http;
using System.Collections.Generic;
using System.Linq;
using Microsoft.AspNetCore.Mvc.Controllers;
using target.Utility.Utility.ThirdParty;

namespace target.Web.Modules.Filter
{

    /// <summary>
    /// 部分API站台 要求任何請求都要帶某個特定header (證書)
    /// 因為 AuthorizationFilter 的順序更優先 所以寫成 AuthorizationFilter
    /// </summary>
    public class GlobalApiHeaderTemporaryInfoFilter : System.Attribute, IAuthorizationFilter
    {

        public void OnAuthorization(AuthorizationFilterContext context)
        {
            //var qqqq1 = context.HttpContext.Request;
            //var qqqq2 = context.HttpContext.Request.Path.ToString();

            //string debugMsg = $"收到API_{qqqq2}";

            //MySlackUtility.SendMessageToChannelAsync(new SlackMessageParameter
            //{
            //    Channel = SlackChannelEnum.n_error_pgs_common,
            //    Message = debugMsg
            //});

            //string qqqqq = "";





            //var actionDescriptor = context.ActionDescriptor as ControllerActionDescriptor;

            //string uniqueRouteName = $"{actionDescriptor.ControllerName}_{actionDescriptor.ActionName}";

            ////將此資訊存在httpHeader，寫log的時候，會用來判斷一些東西
            //if (!context.HttpContext.Request.Headers.ContainsKey(SystemConstant.HeaderKey_Temperary_ApiUniqueRouteName))
            //{
            //    context.HttpContext.Request.Headers.Add(SystemConstant.HeaderKey_Temperary_ApiUniqueRouteName, uniqueRouteName);
            //}
        }

    }


}
