using System;
using System.Collections.Generic;
using System.Linq;
using System.Threading.Tasks;
using System.Web;
namespace Lck.Utility.Extensions.Cache
{
    public abstract class BaseCache
    {
        public abstract T Get<T>(string key);

        public abstract bool Set(string key, object data , TimeSpan expiredTime);

        public abstract List<string> GetCacheKeysByKeyword(string keyword);

        public abstract void FlushAll();

        public abstract void Flush(string key);

        public abstract Task FlushAsync(string key);
    }
}

