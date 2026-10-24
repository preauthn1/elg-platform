using System;
using System.Collections.Generic;
using System.Collections.Specialized;
using System.Linq;
using System.Text;
using System.Threading.Tasks;

namespace target.Utility.Utility
{
    public class ApiRequestResultModel<T>
    {       
        public T ResultObject { get; set; }

        public Exception Exception { get; set; }

        public bool IsTimeoutException { get; set; }

        public string ResponseText { get; set; }

        public int ResponseStatusCode { get; set; }

        public DebugInfo DebugInfo { get; set; }


        public NameValueCollection ResponseHeaders { get; set; }

        public string RelativeUrl { get; set; }

        public long ExecutedTime { get; set; }


        public string ErrorLogMessage
        {
            get
            {
                return Exception?.Message + "_" + ResponseText;
            }
        }
    }



    public class DebugInfo
    {
        public RequestParameters RequestParameters { get; set; }
    }


    public class ApiResultBriefModel<T>
    {
        public T ResultObject { get; set; }

        public string ResponseText { get; set; }
    }


    public class ApiResultBriefObjectModel
    {
        public object ResultObject { get; set; }

        public string ResponseText { get; set; }

        /// <summary>
        /// 請求識別碼
        /// </summary>
        public string RequestUUID { get; set; }
    }



}
