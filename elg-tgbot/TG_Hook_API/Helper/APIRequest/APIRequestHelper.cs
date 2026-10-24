using Newtonsoft.Json;
using Newtonsoft.Json.Serialization;
using System;
using System.Collections;
using System.Collections.Generic;
using System.Diagnostics;
using System.Dynamic;
using example.invalid;
using example.invalid.Compression;
using System.Linq;
using example.invalid;
using System.Reflection;
using System.Text;

namespace target.Utility.Utility
{
    public class APIRequestHelper
    {
        public static string No_Url_Keyword = "NO_URL";


        public static Queue<object> EazyLogs = new Queue<object>();


        public static ApiRequestResultModel<T> RequestApiResult<T>(RequestParameters parameter, object postBodyModel = null)
        {
            var result = DoRequestApiResult<T>(parameter, postBodyModel);
            return result;
        }


        public static ApiRequestResultModel<T> DoRequestApiResult<T>(RequestParameters parameter, object postBodyModel = null)
        {
            //檢查與防呆
            VerifyParameters(parameter);

            ApiRequestLogTemporaryInfoModel requestParameterTemp = SetRequestLogInformation(parameter);

            //new Instance
            HttpWebRequest httpWebRequest = InstanceHttpWebRequest(parameter);
            httpWebRequest.Timeout = parameter.TimeoutMillisecond;
            requestParameterTemp.HttpWebRequest = httpWebRequest;

            try
            {
                switch (parameter.Method)
                {
                    case HttpMethodType.HttpDelete:
                    case HttpMethodType.HttpGet:
                        ApiRequestResultModel<T> resultModel = GetResponse<T>(httpWebRequest, requestParameterTemp);
                        return resultModel;
                }

                //HttpPOST HttpPut
                using (var streamWriter = InstanceStreamWrite(httpWebRequest, parameter.EncodingType))
                {
                    string jsonBody = string.Empty;

                    //設定 POST json body
                    if (postBodyModel == null)
                        jsonBody = SetJsonParameters(streamWriter, parameter);
                    else
                        jsonBody = SetJsonParametersByModel(streamWriter, parameter, postBodyModel);

                    requestParameterTemp.RequestContent = jsonBody;
                    ApiRequestResultModel<T> resultModel = GetResponse<T>(httpWebRequest, requestParameterTemp);
                    return resultModel;
                }
            }
            catch (WebException ex)
            {
                return CheckErrorMessage<T>(ex, requestParameterTemp);
            }
            catch (System.Exception ex)
            {
                //如果有指定Log行為
                requestParameterTemp.Response.ResponseStatusCode = 500;
                requestParameterTemp.Response.ResponseContent = ex.Message;
                ExecuteLogAction(requestParameterTemp);

                return new ApiRequestResultModel<T>
                {
                    DebugInfo = new DebugInfo
                    {
                        RequestParameters = parameter
                    },
                    ResponseText = ex.Message,
                    Exception = ex
                };
            }
        }

        private static ApiRequestLogTemporaryInfoModel SetRequestLogInformation(RequestParameters parameter)
        {
            var requestParameterTemp = new ApiRequestLogTemporaryInfoModel();
            requestParameterTemp.RequestParameters = parameter;
            requestParameterTemp.RequestContent = string.Empty;
            requestParameterTemp.RequestStartTime = DateTime.UtcNow;

            return requestParameterTemp;
        }

        private static StreamWriter InstanceStreamWrite(HttpWebRequest httpWebRequest, EncodingType encodeType)
        {
            if (encodeType == EncodingType.None)
                return new StreamWriter(httpWebRequest.GetRequestStream());

            return new StreamWriter(httpWebRequest.GetRequestStream(), InstanceEncoding(encodeType));
        }

        private static Encoding InstanceEncoding(EncodingType encoding)
        {
            switch (encoding)
            {
                case EncodingType.UTF8:
                    return Encoding.UTF8;

                case EncodingType.Big5:
                    return Encoding.GetEncoding("big5");

                case EncodingType.GB2312:
                    return Encoding.GetEncoding("gb2312");

                default:
                    throw new NotImplementedException();
            }
        }

        /// <summary>
        /// 加入Header
        /// </summary>
        /// <param name="httpWebRequest"></param>
        /// <param name="headers"></param>
        private static void AddHeaders(HttpWebRequest httpWebRequest, IList<KeyValuePair<string, string>> headers)
        {
            if (headers == null || headers.Count() == 0)
                return;

            foreach (var header in headers)
            {
                httpWebRequest.Headers[header.Key] = header.Value;
            }
        }


        private static ApiRequestResultModel<T> GetResponse<T>(HttpWebRequest httpWebRequest, ApiRequestLogTemporaryInfoModel parameter)
        {
            string responseText = string.Empty;

            using (var httpResponse = (HttpWebResponse)httpWebRequest.GetResponse())
            {
                using (var streamReader = new StreamReader(GetStreamForResponse(httpResponse)))
                {
                    responseText = streamReader.ReadToEnd();
                }

                int statusCode = (int)httpResponse.StatusCode;

                //如果有指定Log行為
                SetResponseLogInformation(parameter, responseText, httpResponse);

                T converResult = default(T);

                if (typeof(T) == typeof(string)) 
                {
                    converResult = (T)(object)responseText;
                }
                else
                {
                    var setting = parameter.RequestParameters.AdvancedSetting;

                    if (setting != null && setting.IsIgnoreJsonParsingColumnError)
                    {
                        converResult = JsonConvert.DeserializeObject<T>(responseText, new JsonSerializerSettings
                        {
                            Error = (se, ev) => { ev.ErrorContext.Handled = true; }
                        });
                    }
                    else
                    {
                        converResult = JsonConvert.DeserializeObject<T>(responseText);
                    }
                }

                return new ApiRequestResultModel<T>
                {
                    ResponseStatusCode = statusCode,
                    ResponseHeaders = httpResponse.Headers,
                    ResultObject = converResult,
                    RelativeUrl = GetRelativeUrl(parameter.RequestParameters),
                    ExecutedTime = parameter.Response.ResponseTime,
                    ResponseText = responseText,
                    DebugInfo = new DebugInfo
                    {
                        RequestParameters = parameter.RequestParameters
                    }
                };
            }
        }


        private static void SetResponseLogInformation(ApiRequestLogTemporaryInfoModel parameter, string responseText, HttpWebResponse httpResponse)
        {
            if (parameter.RequestParameters.LogAction == null)
                return;

            parameter.Response.ResponseStatusCode = (int)httpResponse.StatusCode;
            parameter.Response.ResponseContent = responseText;

            //執行log行為
            ExecuteLogAction(parameter);
        }


        private static Stream GetStreamForResponse(HttpWebResponse webResponse)
        {
            if (string.IsNullOrEmpty(webResponse.ContentEncoding))
            {
                return webResponse.GetResponseStream();
            }
            Stream stream;
            switch (webResponse.ContentEncoding.ToUpperInvariant())
            {
                case "GZIP":
                    stream = new GZipStream(webResponse.GetResponseStream(), CompressionMode.Decompress);
                    break;

                case "DEFLATE":
                    stream = new DeflateStream(webResponse.GetResponseStream(), CompressionMode.Decompress);
                    break;

                default:
                    stream = webResponse.GetResponseStream();
                    break;
            }
            return stream;
        }

        private static void ExecuteLogAction(ApiRequestLogTemporaryInfoModel parameter)
        {
            DateTime now = DateTime.UtcNow;
            parameter.Response.ResponseTime = (int)(now - parameter.RequestStartTime).TotalMilliseconds;

            if (parameter.RequestParameters.LogAction == null)
                return;

            parameter.RequestParameters.LogAction(parameter);
        }

        private static ApiRequestResultModel<T> CheckErrorMessage<T>(WebException ex, ApiRequestLogTemporaryInfoModel parameter)
        {
            var resultModel = new ApiRequestResultModel<T>
            {
                DebugInfo = new DebugInfo
                {
                    RequestParameters = parameter.RequestParameters
                },
                Exception = ex,
                IsTimeoutException = ex.Status == WebExceptionStatus.Timeout
            };

            if (ex.Response == null)
            {
                //如果有指定Log行為
                parameter.Response.ResponseStatusCode = (int)ex.Status;
                parameter.Response.ResponseContent = string.Empty;
                ExecuteLogAction(parameter);

                resultModel.ResultObject = default(T);
                return resultModel;
            }

            string errorMessage = string.Empty;

            using (var stream = ex.Response.GetResponseStream())
            using (var reader = new StreamReader(stream))
            {
                errorMessage = reader.ReadToEnd();
            }


            int statusCode = 500;
            if (ex.Status == WebExceptionStatus.ProtocolError)
            {
                statusCode = (int)((HttpWebResponse)ex.Response).StatusCode;
            }

            //如果有指定Log行為
            parameter.Response.ResponseStatusCode = statusCode;
            parameter.Response.ResponseContent = errorMessage;
            ExecuteLogAction(parameter);

            resultModel.ResponseStatusCode = statusCode;
            resultModel.ResponseText = errorMessage;
            resultModel.ResponseHeaders = ex.Response.Headers;
            resultModel.ResultObject = default(T);

            //若指定型別為string 特別處理
            if (typeof(T) == typeof(string))
            {
                resultModel.ResultObject = (T)(object)errorMessage;
            }

            return resultModel;
        }

        /// <summary>
        /// 檢查與防呆
        /// </summary>
        /// <param name="parameter"></param>
        private static void VerifyParameters(RequestParameters parameter)
        {
            if (string.IsNullOrWhiteSpace(parameter.BaseUrl))
                throw new System.Exception("BaseUrl Format Not Allow #001");

            if (parameter.BaseUrl.Contains("http") == false && parameter.BaseUrl.Contains("https") == false)
                throw new System.Exception("BaseUrl Format Not Allow #002");

            if (string.IsNullOrWhiteSpace(parameter.ApiUrl))
                throw new System.Exception("ApiUrl Format Not Allow #002");

            if (parameter.TimeoutMillisecond <= 0)
                parameter.TimeoutMillisecond = 120000;
        }


        /// <summary>
        /// 設定 POST json參數
        /// </summary>
        /// <param name="streamWriter"></param>
        /// <param name="parameters"></param>
        private static string SetJsonParameters(StreamWriter streamWriter, RequestParameters parameters)
        {
            if (parameters.Method == HttpMethodType.HttpGet)
                return string.Empty;

            string json = string.Empty;
            if (parameters.Parameters.Count == 0)
            {
                json = string.Empty;
            }
            else
            {
                object annoymouseObject = DynamicGenerateJson(parameters.Parameters);
                json = JsonConvert.SerializeObject(annoymouseObject);
            }

            streamWriter.Write(json);
            streamWriter.Flush();
            streamWriter.Close();

            return json;
        }

        /// <summary>
        /// 設定 POST json參數
        /// </summary>
        /// <param name="streamWriter"></param>
        /// <param name="parameters"></param>
        private static string SetJsonParametersByModel(StreamWriter streamWriter, RequestParameters requestSetting, object parameterModel)
        {
            string json = string.Empty;

            if (parameterModel.GetType() == typeof(string))
            {
                json = parameterModel.ToString();
            }
            else
            {
                if (requestSetting.AdvancedSetting != null && requestSetting.AdvancedSetting.IsCamelCase)
                {
                    json = JsonConvert.SerializeObject(parameterModel, new JsonSerializerSettings
                        {
                            ContractResolver = new CamelCasePropertyNamesContractResolver()
                        });
                }
                else
                {
                    json = JsonConvert.SerializeObject(parameterModel);
                }
            }

            streamWriter.Write(json);
            streamWriter.Flush();
            streamWriter.Close();

            return json;
        }

        private static HttpWebRequest InstanceHttpWebRequest(RequestParameters parameter)
        {
            string url = GetRequestUrl(parameter);

            if (url.Contains("https://"))
            {
                ServicePointManager.Expect100Continue = true;
                ServicePointManager.SecurityProtocol = SecurityProtocolType.Tls12;
            }

            HttpWebRequest httpWebRequest = (HttpWebRequest)WebRequest.Create(url);

            if (string.IsNullOrWhiteSpace(parameter.ContentType) == false)
            {
                httpWebRequest.ContentType = parameter.ContentType;
            }

            if (string.IsNullOrWhiteSpace(parameter.Accept) == false)
            {
                httpWebRequest.Accept = parameter.Accept;
            }

            if (!string.IsNullOrWhiteSpace(parameter.Host))
            {
                httpWebRequest.Host = parameter.Host;
            }

            httpWebRequest.Method = ParseToMethodText(parameter.Method);
            httpWebRequest.Referer = parameter.Referer;
            httpWebRequest.UserAgent = parameter.UserAgent;

            //加入header
            AddHeaders(httpWebRequest, parameter.Headers);

            var advancedSetting = parameter.AdvancedSetting;
            if (advancedSetting != null)
            {
                httpWebRequest.AllowAutoRedirect = advancedSetting.AllowAutoRedirect;
                httpWebRequest.Proxy = advancedSetting.Proxy;
            }

            //加入cookie

            string cookieDomain = new Uri(parameter.BaseUrl).Host;
            if (parameter.Cookies != null)
            {
                httpWebRequest.CookieContainer = new CookieContainer();
                foreach (var item in parameter.Cookies)
                {
                    var cookie = new Cookie(item.Key, item.Value);
                    cookie.Domain = cookieDomain;
                    httpWebRequest.CookieContainer.Add(cookie);
                }
            }

            return httpWebRequest;
        }


        private static string ParseToMethodText(HttpMethodType type)
        {
            return type.ToString().Replace("Http", "").ToUpper();
        }


        /// <summary>
        /// 根據Key Value 產生匿名型別
        /// </summary>
        /// <param name="source"></param>
        /// <returns></returns>
        private static dynamic DynamicGenerateJson(IList<KeyValuePair<string, string>> source)
        {
            Dictionary<string, object> dict = source.ToDictionary(x => x.Key, x => (object)x.Value);

            var eo = new ExpandoObject();
            var eoColl = (ICollection<KeyValuePair<string, object>>)eo;

            foreach (var kvp in dict)
            {
                eoColl.Add(kvp);
            }

            dynamic eoDynamic = eoColl;
            return eoDynamic;
        }

        private static string GetRelativeUrl(RequestParameters parameters)
        {
            if (parameters.ApiUrl == No_Url_Keyword)
                return parameters.BaseUrl;

            return parameters.ApiUrl;
        }


        private static string GetRequestUrl(RequestParameters parameters)
        {
            string url = string.Format("{0}/{1}", parameters.BaseUrl, parameters.ApiUrl);

            if (parameters.ApiUrl == No_Url_Keyword)
                url = parameters.BaseUrl;

            url = ReplaceDuplicateUrlSymbol(url);

            if (parameters.Method != HttpMethodType.HttpGet)
                return url;

            if (parameters.Parameters.Count == 0)
                return url;

            //如果是HttpGet 把參數接在網址後面
            url = url + "?";
            int count = 0;
            foreach (var item in parameters.Parameters)
            {
                if (count > 0)
                    url += "&";

                url += string.Format("{0}={1}", item.Key, item.Value);
                count++;
            }

            return url;
        }

        /// <summary>
        /// 取代多餘的斜線符號
        /// </summary>
        public static string ReplaceDuplicateUrlSymbol(string source)
        {
            if (string.IsNullOrWhiteSpace(source))
                return string.Empty;

            //求高手幫忙改成 regex
            //其實就是想把 http://example.invalid////myTrip  多餘的斜線符號取代掉
            if (source.Contains("://"))
            {
                source = source.Replace("://", "TEMP_STRING");
            }

            source = source.Replace("///", "/").Replace("//", "/");
            source = source.Replace("TEMP_STRING", "://");
            return source;
        }
    }
}