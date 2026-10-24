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
    /// Debug
    /// </summary>
    public class DebugNotifyFilter : System.Attribute, IResultFilter
    {
        public void OnResultExecuted(ResultExecutedContext context)
        {

        }


        public void OnResultExecuting(ResultExecutingContext context)
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
        }

    }
}
