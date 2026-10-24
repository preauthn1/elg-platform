using Lck.Utility.Extensions.Helper;
using System;
using System.Collections.Generic;
using System.Linq;
using System.Text;
using System.Threading.Tasks;

namespace Lck.Utility.Extensions.Helper
{
    public class CacheRemoveHelper
    {

        /// <summary>
        /// 後台修改用戶狀態
        /// </summary>
        public static void RemoveCache_WhenUpdatePlayer(int playerID)
        {
            CacheHelper.RemoveCacheByKey("XXXXXXXXXXX");
        }


    }

}
