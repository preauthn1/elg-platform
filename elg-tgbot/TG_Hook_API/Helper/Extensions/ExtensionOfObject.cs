using System;
using Newtonsoft.Json;
using System.Reflection;
using Omu.ValueInjecter;
using Omu.ValueInjecter.Injections;
using Newtonsoft.Json.Serialization;
using System.Collections.Generic;
using System.Collections;

namespace target.Utility.Extensions
{
    /// <summary>
    /// Object 的擴充方法
    /// </summary>
    public static class ExtensionOfObject
    {

        /// <summary>
        /// 似乎必須傳入proprtyType才能識別出來
        /// </summary>
        public static bool IsNullableType(this Type type)
        {
            return type.IsGenericType && type.GetGenericTypeDefinition() == typeof(Nullable<>);
        }


        public static bool IsDictionary(this object o)
        {
            if (o == null) return false;
            return o is IDictionary &&
                   o.GetType().IsGenericType &&
                   o.GetType().GetGenericTypeDefinition().IsAssignableFrom(typeof(Dictionary<,>));
        }

        public static string ConvertToJsonString(this object obj)
        {
            if (obj == null)
                return null;

            return JsonConvert.SerializeObject(obj);
        }

        /// <summary>
        /// 首字轉轉小寫的json
        /// </summary>
        public static string ConvertToJsonStringCamelCase(this object obj)
        {
            if (obj == null)
                return null;

            var setting = new JsonSerializerSettings
            {
                ContractResolver = new CamelCasePropertyNamesContractResolver()
            };

            return JsonConvert.SerializeObject(obj , setting);
        }



        public static string ConvertToJsonStringIndent(this object obj)
        {
            if (obj == null)
                return null;

            var json = JsonConvert.SerializeObject(obj, Formatting.Indented);
            return json;
        }
     

        public static T TryJsonConvertToModel<T>(this string jsonText)
        {
            try
            {
                return JsonConvertToModel<T>(jsonText);
            }
            catch(Exception ex)
            {
                string msg = ex.Message;
            }
            return default(T);
        }



        public static T JsonConvertToModel<T>(this string jsonText)
        {
            if (string.IsNullOrWhiteSpace(jsonText))
                return default(T);

            return JsonConvert.DeserializeObject<T>(jsonText);
        }


        public static T JsonConvertToModelIgnoreErrorColumn<T>(this string jsonText)
        {
            if (string.IsNullOrWhiteSpace(jsonText))
                return default(T);

            return JsonConvert.DeserializeObject<T>(jsonText, new JsonSerializerSettings
            {
                Error = (se, ev) => { ev.ErrorContext.Handled = true; }
            });
        }



        /// <summary>
        /// new一個指定的物件 並把 資料型別、欄位名稱蕭彤 的欄位值 都帶過去新物件
        /// </summary>
        public static T ReturnInjecteObject<T>(this object fromModel) where T : new()
        {
            if (fromModel == null)
                return default(T);

            var model2 = new T();

            model2.InjectFrom(fromModel);
            return model2;
        }

    }


}