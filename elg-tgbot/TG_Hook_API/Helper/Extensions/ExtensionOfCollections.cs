using System;
using System.Collections.Generic;
using System.ComponentModel;
using System.Linq;
using System.Linq.Expressions;
using System.Reflection;
using System.Data;
using System.Dynamic;

namespace target.Utility.Extensions
{
    public static class ExtensionOfCollections
    {
        public static T CalculateTotalSumMergeToOneObject<T>(this IEnumerable<T> list) where T: new()
        {
            if (list.IsEmptyOrNull())
                return new T();

            Dictionary<string, long> totalValueDic = new Dictionary<string, long>();
            Dictionary<string, decimal> totalValueDecimalDic = new Dictionary<string, decimal>();

            foreach (var item in list)
            {
                foreach(var prop in item.GetType().GetProperties())
                {
                    string propName = prop.Name;
                    string propNameLower = prop.Name.ToLower();

                    //如果欄位有這種命名的 不會做加總
                    if (propNameLower.EndsWith("id") || 
                        propNameLower.EndsWith("type") ||
                        propNameLower.Contains("status"))
                    {
                        continue;
                    }

                    bool isInt32 = prop.PropertyType == typeof(int);
                    bool isInt64 = prop.PropertyType == typeof(long);
                    bool isDecimal = prop.PropertyType == typeof(decimal);

                    if (isInt32 || isInt64)
                    {
                        var myValue = prop.GetValue(item, null);
                        
                        if(isInt32)
                        {
                            long convertLong = Int64.Parse(myValue.ToString());
                            AddNewItemOrSumTotal(totalValueDic, propName, convertLong);
                        }
                        else
                        {
                            AddNewItemOrSumTotal(totalValueDic, propName, (long)myValue);
                        }
                    }
                    else if(isDecimal)
                    {
                        var myValue = prop.GetValue(item, null);
                        AddNewItemOrSumTotal(totalValueDecimalDic, propName, (decimal)myValue);
                    }
                }
            }

            var newInstance = new T();            
            foreach(var prop in newInstance.GetType().GetProperties())
            {
                string propName = prop.Name;
                string propNameLower = prop.Name.ToLower();

                //如果dic有該欄位的加總(int、long型別)
                if(totalValueDic.ContainsKey(propName))
                {
                    long totalSumValue = totalValueDic[propName];
                    if (prop.PropertyType == typeof(int))
                    {
                        prop.SetValue(newInstance, (int)totalSumValue, null);
                    }
                    else
                    {
                        prop.SetValue(newInstance, totalSumValue, null);
                    }       
                }

                //如果dic有該欄位的加總 (decimal型別)
                if (totalValueDecimalDic.ContainsKey(propName))
                {
                    decimal totalSumValue = totalValueDecimalDic[propName];
                    prop.SetValue(newInstance, totalSumValue, null);
                }
            }

            return newInstance;
        }

        public static Dictionary<string, object> CalculateTotalSumMergeToOneDictonaryFormat(this IEnumerable<Dictionary<string, object>> list)
        {
            if (list.IsEmptyOrNull())
                return new Dictionary<string, object>();

            Dictionary<string, long> totalValueDic = new Dictionary<string, long>();
            Dictionary<string, decimal> totalValueDecimalDic = new Dictionary<string, decimal>();

            var matchFirst = list.FirstOrDefault();

            foreach (var eachDic in list)
            {
                foreach (var prop in eachDic)
                {
                    string propName = prop.Key;
                    string propNameLower = propName.ToLower();

                    var valueType = prop.Value.GetType();

                    //如果欄位有這種命名的 不會做加總
                    if (propNameLower.EndsWith("id") ||
                        propNameLower.EndsWith("type") ||
                        propNameLower.Contains("status"))
                    {
                        continue;
                    }

                    bool isInt32 = valueType == typeof(int);
                    bool isInt64 = valueType == typeof(long);
                    bool isDecimal = valueType == typeof(decimal) || valueType == typeof(double);

                    if (isInt32 || isInt64)
                    {
                        var myValue = prop.Value;

                        if (isInt32)
                        {
                            long convertLong = Int64.Parse(myValue.ToString());
                            AddNewItemOrSumTotal(totalValueDic, propName, convertLong);
                        }
                        else
                        {
                            AddNewItemOrSumTotal(totalValueDic, propName, (long)myValue);
                        }
                    }
                    else if (isDecimal)
                    {
                        var myValue = prop.Value;
                        AddNewItemOrSumTotal(totalValueDecimalDic, propName, decimal.Parse(myValue.ToString()));
                    }
                }
            }

            var newInstance = new Dictionary<string, object>();

            foreach (var eachValue in matchFirst)
            {
                string propName = eachValue.Key;
                string propNameLower = propName.ToLower();
                Type valueType = eachValue.Value.GetType();

                //如果dic有該欄位的加總(int、long型別)
                if (totalValueDic.ContainsKey(propName))
                {
                    long totalSumValue = totalValueDic[propName];

                    if (valueType == typeof(int))
                    {
                        newInstance.Add(propName, (int)totalSumValue);
                    }
                    else
                    {
                        newInstance.Add(propName, totalSumValue);
                    }
                }

                //如果dic有該欄位的加總 (decimal型別)
                else if (totalValueDecimalDic.ContainsKey(propName))
                {
                    decimal totalSumValue = totalValueDecimalDic[propName];
                    newInstance.Add(propName, totalSumValue);
                }
                else
                {
                    newInstance.Add(propName, "");
                }
            }

            return newInstance;
        }


        public static string ConvertToSingleLineText<T>(this IEnumerable<T> list)
        {
            return string.Join(",", list.Select(z => z.ToString()));
        }


        public static IEnumerable<T> NullToEmptyCollection<T>(this IEnumerable<T> list)
        {
            if (list != null)
                return list;

            return new List<T>();
        }



        public static HashSet<T> ToHashSet<T>(this IEnumerable<T> list)
        {
            return new HashSet<T>(list);
        }
     


        /// <summary>
        /// 將單筆 int、long、string 等型別轉成 list
        /// </summary>
        public static List<T> ToListCollection<T>(this T inputValue) where T: IComparable
        {
            List<T> listResult = new List<T>();
            listResult.Add(inputValue);

            return listResult;
        }


        public static List<int> ToIntegerListCollection(this int? inputValue , List<int> defaultValues = null)
        {
            if (inputValue.HasValue)
                return inputValue.Value.ToListCollection();

            return defaultValues ?? new List<int>();
        }


        public static List<long> ToIntegerListCollection(this long? inputValue, List<long> defaultValues = null)
        {
            if (inputValue.HasValue)
                return inputValue.Value.ToListCollection();

            return defaultValues ?? new List<long>();
        }



        public static T GetMatchOrFirstDefault<T>(this IEnumerable<T> list , Func<T, bool> func) where T: class
        {
            if (list.IsEmptyOrNull())
                return null;

            var matchFirst = list.FirstOrDefault(func);

            if (matchFirst != null)
                return matchFirst;


            return list.FirstOrDefault();
        }




        public static string GetDictionaryOrEmptyValue<T>(this Dictionary<T, string> dic , T key , string defaultValue = "")
        {
            if (dic.ContainsKey(key) == false)
                return defaultValue;

            return dic[key];
        }



        /// <summary>
        /// 若找不到指定的值，返回預設第一筆
        /// </summary>
        public static string GetDictionaryOrDefaultFirstValue(this Dictionary<int, string> dic, int key)
        {
            if (dic.IsEmptyOrNull())
                return string.Empty;

            if (dic.ContainsKey(key) == false)
                return dic.FirstOrDefault().Value;

            return dic[key];
        }


        /// <summary>
        /// 找出Dic的值 或 返回預設值
        /// </summary>
        public static T GetDictionaryOrDefaultValue<T>(this Dictionary<string, object> dic, string key , T defaultValue)
        {
            if (dic.IsEmptyOrNull())
                return defaultValue;

            if (dic.ContainsKey(key) == false)
                return defaultValue;

            object dicValue = dic[key];

            if (dicValue == null)
                return defaultValue;

            try
            {
                return (T)Convert.ChangeType(dicValue, typeof(T));
            }
            catch
            {

            }

            return defaultValue;
        }



        /// <summary>
        /// Distanct第一筆
        /// </summary>
        public static List<T> DistinctBy<T, TKey>(this IList<T> list, Func<T, TKey> func)
        {
            if (list.IsEmptyOrNull())
                return new List<T>();


            return list.GroupBy(func).Select(x=> x.First()).ToList();
        }


        /// <summary>
        /// Distanct第一筆
        /// </summary>
        public static List<T> DistinctOrderBy<T, TKey, TColumn>(this IList<T> list, Func<T, TKey> func, Func<T, TColumn> orderBy)
        {
            if (list.IsEmptyOrNull())
                return new List<T>();


            return list.GroupBy(func).Select(x => x.OrderBy(orderBy).First()).ToList();
        }



        /// <summary>
        /// 將數量龐大的array切個成N等分 (e.g. 10000筆 切成5份2000筆)
        /// </summary>
        public static List<List<T>> SplitListByArraySize<T>(this List<T> collection, int size)
        {
            if (collection.IsEmptyOrNull())
                return new List<List<T>>();

            int totalCount = collection.Count();

            if (totalCount <= size)
            {
                return new List<List<T>>
                {
                    collection
                };
            }                

            List<List<T>> resultList = new List<List<T>>();

            int arraySize = totalCount / size + 1;
            int modValue = totalCount % size;

            if (modValue == 0)
            {
                arraySize = arraySize - 1;
            }

            for(int i = 0; i < arraySize; i++)
            {
                var fetchArrayItems = collection.Skip(i * size).Take(size).ToList();
                resultList.Add(fetchArrayItems);
            }

            return resultList;
        }

        /// <summary>
        /// 亂數排序List 
        /// </summary>
        public static List<T> SortByRandom<T>(this IEnumerable<T> input, int displayLength = 0)
        {
            if (input.IsEmptyOrNull())
                return new List<T>();

            Random r = new Random();

            if (displayLength > 0)
                return input.OrderBy(x => r.Next()).Take(displayLength).ToList();
            else
                return input.OrderBy(x => r.Next()).ToList();
        }


        /// <summary>
        /// 判斷List是否為null或Count == 0
        /// </summary>
        public static bool IsEmptyOrNull<T>(this IEnumerable<T> source)
        {
            if (source == null)
                return true; // or throw an exception

            if (source.Any())
                return false;

            return true;
        }

        
        /// <summary>
        /// 判斷List是否不為空 (不為null ＆＆　筆數大於０)
        /// </summary>
        public static bool IsNotEmptyNull<T>(this IEnumerable<T> source)
        {
            if (source == null)
                return false; // or throw an exception

            return source.Any();
        }

        private static void AddNewItemOrSumTotal(Dictionary<string, long> dic, string columnName, long value)
        {
            if (dic.ContainsKey(columnName))
            {
                dic[columnName] = dic[columnName] + value;
                return;
            }

            dic.Add(columnName, value);
        }

        private static void AddNewItemOrSumTotal(Dictionary<string, decimal> dic, string columnName, decimal value)
        {
            if (dic.ContainsKey(columnName))
            {
                dic[columnName] = dic[columnName] + value;
                return;
            }

            dic.Add(columnName, value);
        }




    }

}
