using System;
using System.Collections.Generic;
using System.Linq;
using System.Threading.Tasks;

namespace TG_Hook_API.Helper
{
    public static class DebugLogHelper
    {

        public static Queue<string> myQueue = new Queue<string>();

        public static void AppendToQueue(string myLog)
        {
            if (myLog == null)
                return;

            myQueue.Enqueue(myLog);

            if(myQueue.Count() >= 20)
            {
                myQueue.Dequeue();
            }
        }


        public static List<string> GetAllItems()
        {
            List<string> myValues = new List<string>();

            foreach(string item in myQueue)
            {
                myValues.Add(item);
            }

            return myValues;
        }

    }
}
