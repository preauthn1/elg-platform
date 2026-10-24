using System;
using System.Collections.Generic;
using System.Linq;
using System.Web;
using System.Runtime.Serialization.Formatters.Binary;
using example.invalid;
using StackExchange.Redis;
using Lck.Utility.Extensions;
using Newtonsoft.Json;
using target.Utility;
using example.invalid;
using System.Threading;
using target.Utility.Utility.ThirdParty;
using System.Threading.Tasks;

namespace Lck.Utility.Extensions.Cache
{
    /// <summary>
    /// 採用Redis進行Cache機制的基礎類別
    /// </summary>
    public class RedisCache : BaseCache
    {
        private static Lazy<ConnectionMultiplexer> Connection;

        //請確保config有配置好 這些設置
        private static string _redis_conn_config => SettingHelper.ThirdParty.RedisConn;

        private static int _redis_dbNumber_config => SettingHelper.ThirdParty.RedisDBNumber;

        private static int _redis_timeout_seconds => SettingHelper.ThirdParty.RedisTimeoutSeconds;


        private static EndPoint _endPoint { get; set; }


        private IDatabase redisDB
        {
            get
            {
                return Connection.Value.GetDatabase(_redis_dbNumber_config);
            }
        }

        private IServer redisServer
        {
            get
            {
                return Connection.Value.GetServer(_endPoint);
            }
        }


        public RedisCache()
        {
            InitialRedis();
        }


        private void InitialRedis()
        {
            //若已經初始化的話 就不再重新連線
            if (Connection == null)
            {
                int timeoutMilliseconds = _redis_timeout_seconds * 1000;

                ConfigurationOptions redisConfig = ConfigurationOptions.Parse(_redis_conn_config);
                redisConfig.ConnectTimeout = timeoutMilliseconds;
                redisConfig.SyncTimeout = timeoutMilliseconds * 2;
                redisConfig.AllowAdmin = true;

                //redisConfig.AbortOnConnectFail = false;
                //redisConfig.ConnectRetry = 3;

                Connection = new Lazy<ConnectionMultiplexer>(() => ConnectionMultiplexer.Connect(redisConfig));
                _endPoint = redisConfig.EndPoints.FirstOrDefault();
            }
        }


        public override T Get<T>(string key)
        {
            try
            {
                if (typeof(T) == typeof(byte[]))
                    return (dynamic)redisDB.StringGet(key);

                string jsonText = redisDB.StringGet(key);

                if (typeof(T) == typeof(string))
                    return (dynamic)jsonText;

                return DeserializeObject<T>(jsonText);
            }
            catch (Exception ex)
            {
                return default(T);
            }
        }


        public override bool Set(string key, object value, TimeSpan expiredTime)
        {
            try
            {
                string jsonText = null;
                if (value != null)
                {
                    Type myValueType = value.GetType();

                    if (myValueType == typeof(byte[]))
                    {
                        return SetValue(key, (byte[])value, expiredTime);
                    }

                    if (myValueType == typeof(string))
                    {
                        jsonText = value.ToString();
                    }
                    else
                    {
                        jsonText = JsonConvert.SerializeObject(value);
                    }
                }

                return SetValue(key, jsonText, expiredTime);

            }
            catch (Exception ex)
            {
                return false;
            }
        }


        private bool SetValue(string key, RedisValue jsonValue, TimeSpan? expiredTime = null)
        {
            if (expiredTime == null)
            {
                return redisDB.StringSet(key, jsonValue);
            }

            return redisDB.StringSet(key, jsonValue, expiredTime.Value);
        }


        /// <summary>
        /// 關鍵字找出特定keys
        /// </summary>
        public override List<string> GetCacheKeysByKeyword(string keyword)
        {
            var matchKeys = redisServer.Keys(_redis_dbNumber_config , pattern: $"*{keyword}*");
            var result = matchKeys.Select(z => z.ToString()).ToList();
            return result;
        }


        /// <summary>
        /// 清除所有Cache
        /// </summary>
        public override void FlushAll()
        {
            redisServer.FlushDatabase(_redis_dbNumber_config);
        }


        /// <summary>
        /// 清除鍵值為key的特定Cache
        /// </summary>
        public override void Flush(string key)
        {
            try
            {
                redisDB.KeyDelete(key);
            }
            catch (Exception ex)
            {
                string msg = $"Flush Fail: {key}" + ex.Message;
                SendTelegramNotify(msg);
            }
        }

        /// <summary>
        /// 清除鍵值為key的特定Cache
        /// </summary>
        public override async Task FlushAsync(string key)
        {
            try
            {
                await redisDB.KeyDeleteAsync(key);
            }
            catch (Exception ex)
            {
                string msg = $"FlushAsync error , {key} , " + ex.Message;
                SendTelegramNotify(msg);
            }
        }



        private void SendTelegramNotify(string msg)
        {
            MySlackUtility.SendMessageToChannelAsync(new SlackMessageParameter
            {
                Message = msg,
                Channel = SlackChannelEnum.n_tgbot_notify
            });
        }


        protected static T DeserializeObject<T>(string jsonText)
        {
            if (typeof(T) == typeof(string))
                return (dynamic)jsonText;

            return JsonConvert.DeserializeObject<T>(jsonText);
        }
    }
}