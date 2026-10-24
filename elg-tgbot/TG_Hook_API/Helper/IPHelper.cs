using System;
using System.Collections.Generic;
using System.Globalization;
using System.Linq;
using System.Text;
using System.Threading.Tasks;
using System.Collections.Specialized;
using target.Utility.Extensions;
using example.invalid;
using example.invalid;
using Microsoft.AspNetCore.Http;
using target.Utility.Helper.HttpContext;

namespace target.Utility
{
    public static class IPHelper
    {
      
        public static string GetIP()
        {
            try
            {
                string mockIP = MyHttpContext.Current.Request.Headers["X-target-Mock-IP"];

                if (!string.IsNullOrWhiteSpace(mockIP))
                    return mockIP;

                string k8s_ip = MyHttpContext.Current.Request.Headers["X-Real-IP"];

                if (!string.IsNullOrWhiteSpace(k8s_ip))
                    return k8s_ip;

                return MyHttpContext.Current.Connection.RemoteIpAddress.ToString();
            }
            catch
            {

            }

            return string.Empty;
            
        }

        public static bool IsCompanyIP()
        {
            var myIP = GetIP();

            return myIP == "0.0.0.0" || 
                myIP == "0.0.0.0" || 
                myIP == "0.0.0.0";
        }

    }

}
