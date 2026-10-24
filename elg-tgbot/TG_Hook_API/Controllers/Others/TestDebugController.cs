using Lck.Utility.Extensions.Helper;
using Microsoft.AspNetCore.Mvc;
using Microsoft.Extensions.Logging;
using target.Utility;
using target.Utility.Utility;
using target.Utility.Utility.ThirdParty;
using System;
using System.Collections.Generic;
using example.invalid;
using System.Linq;
using System.Text;
using System.Threading.Tasks;
using TG_Hook_API.Helper;
using TG_Hook_API.Models;
using TG_Hook_API.Service;

namespace WebApplication3_20220202_003.Controllers
{
    public partial class TestDebugController : ControllerBase
    {
        [Route("testCase/A001")]
        [HttpGet]
        public object Test001()
        {
            //var myService = new VworldPlatformApiService();
            //myService.Login();

            string token = [REDACTED]";
            var para = new RequestParameters
            {
                BaseUrl = "https://example.invalid/",
                ApiUrl = $"bot{token}/sendMessage",
                Method = HttpMethodType.HttpPost,
                TimeoutMillisecond = 10000,
            };

            string msg = @"<table>
  <tr>
    <th>Company</th>
    <th>Contact</th>
    <th>Country</th>
  </tr>
  <tr>
    <td>Alfreds Futterkiste</td>
    <td>Maria Anders</td>
    <td>Germany</td>
  </tr>
  <tr>
    <td>Centro comercial Moctezuma</td>
    <td>Francisco Chang</td>
    <td>Mexico</td>
  </tr>
</table>";

            var body = new
            {
                chat_id = "1242563509",
                text = msg,
                parse_mode = "html"
            };

            var response = APIRequestHelper.RequestApiResult<string>(para, body);
            return "OK";
        }


        [Route("testCase/A002")]
        [HttpGet]
        public object Test002()
        {
            string qqqq = "";

            var TGHelper = new BaseTelegramUtility("[TG_BOT_TOKEN_REDACTED]", "");


            TGHelper.SendMessage(new TelegramMessageParameter
            {
                MessageType = MessageType.ImageWithText,
                TargetChatID = "1242563509",
                Message = "TTTTTTTTTTTT",
                ImageUrl = "https://example.invalid/public/0de959b6-3841-4dba-835c-322455f9f27e.png",
                MultiRowButtonList = new List<List<ButtonList>>
                {
                    new List<ButtonList>
                    {
                        new ButtonList
                        {
                            text = "YYYYYYYY",
                            web_app = new
                            {
                                url = "https://example.invalid/login"
                            }
                        }
                    }
                }
            });

            return "OK";
        }


        [Route("testCase/RedisTime")]
        [HttpGet]
        public object TestRedisTime()
        {
            DateTime begin = DateTime.UtcNow;

            string cacheKey = "QQQQ_YYYYY";
            var qqq1 = CacheHelper.SetCacheValue(cacheKey, "myValue" , new TimeSpan(0 ,1 ,0));
            var qqq2 = CacheHelper.GetCacheValue<string>(cacheKey);

            DateTime endTime = DateTime.UtcNow;

            return new
            {
                totalTimes = (endTime - begin).TotalMilliseconds,
                cacheValue = qqq2
            };
        }

        [Route("testCase/TGSendMessageTime")]
        [HttpGet]
        public object TGSendMessageTime()
        {
            DateTime begin = DateTime.UtcNow;

            MyTelegramUtility.SendMessageToChannel(new TelegramMessageParameter
            {
                Message = "TGSendMessageTime",
                TargetChatID = "1242563509"
            });

            DateTime endTime = DateTime.UtcNow;

            return new
            {
                totalTimes = (endTime - begin).TotalMilliseconds,
            };
        }


        [Route("testCase/VworldAPI_ResponseTime")]
        [HttpGet]
        public object Test_VworldAPI_ResponseTime()
        {
            DateTime begin = DateTime.UtcNow;

            var myService = new VworldPlatformApiService("1242563509", SystemConstant.LanguageCode_ZH , "111111");
            var qqq = myService.IsAccountExist();

            DateTime endTime = DateTime.UtcNow;

            return new
            {
                doamin = SettingHelper.Vworld_API_URL,
                totalTimes = (endTime - begin).TotalMilliseconds,
            };
        }



        [Route("testCase/Apilogs")]
        [HttpGet]
        public object Test_Apilogs(string chatID = "1242563509")
        {
            return new
            {
                logs = VworldApiHistoryQueueHelper.GetAllHistory(chatID),
            };
        }



        [Route("testCase/TGlogs")]
        [HttpGet]
        public object Test_TGlogs(string chatID = "1242563509")
        {
            return new
            {
                logs = TgMessageApiHistoryQueueHelper.GetAllHistory(chatID),
            };
        }


        [Route("testCase/removeCacheAll")]
        [HttpGet]
        public object removeCacheAll(string keyword = "")
        {
            if(string.IsNullOrWhiteSpace(keyword))
            {
                CacheHelper.RemoveCacheAll();
            }
            else
            {
                CacheHelper.RemoveCacheByKeyword(keyword);
            }

            return new
            {
                msg = "RemoveCacheAll"
            };
        }


        [Route("testCase/RedisCache")]
        [HttpGet]
        public object TestRedisCache()
        {
            string key = "TestCache";
            var uuid = Guid.NewGuid().ToString();

            var queue = new Queue<string>();
            queue.Enqueue("AAA" + uuid);
            queue.Enqueue("BBB" + uuid);

            CacheHelper.SetCacheValue(key, queue, new TimeSpan(0, 5, 0));

            var myValue = CacheHelper.GetCacheValue<Queue<string>>(key);

            return new
            {
                CacheValue = myValue,
            };
        }



    }
}
