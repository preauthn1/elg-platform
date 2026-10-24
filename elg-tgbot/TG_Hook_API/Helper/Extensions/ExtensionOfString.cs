using System;
using System.Collections.Generic;
using System.Linq;
using System.Security.Cryptography;
using System.Text;
using System.Text.RegularExpressions;
using System.Web;


namespace target.Utility.Extensions
{
    /// <summary>
    /// string的擴充方法
    /// </summary>
    public static class ExtensionOfString
    {

        /// <summary>
        /// 忽略大小寫 檢查是否包含關鍵字
        /// </summary>
        public static bool ContainsWihtoutCase(this string inputText, string keyword)
        {
            if (string.IsNullOrWhiteSpace(inputText))
                return false;

            if (string.IsNullOrWhiteSpace(keyword))
                return false;

            string lower_input = inputText.ToLower();
            string lower_keyword = keyword.ToLower();

            return lower_input.Contains(lower_keyword);
        }


        public static string SetDefaultValueWhenTextIsEmptyNull(this string inputText, string myValue)
        {
            if (!string.IsNullOrWhiteSpace(inputText))
                return inputText;

            return myValue;
        }

        /// <summary>
        /// 利用逗號或指定的符號 轉成List int
        /// </summary>
        public static List<int> SeperateToListInt(this string inputText , char seperator = ',')
        {
            if (string.IsNullOrWhiteSpace(inputText))
                return new List<int>();

            return inputText.Split(seperator)
                .Where(z => !string.IsNullOrWhiteSpace(z))
                .Select(x=> x.Trim())
                .Where(x=> x.IsNumeric())
                .Select(z => Int32.Parse(z))
                .ToList();
        }

        /// <summary>
        /// 利用逗號或指定的符號 轉成List int
        /// </summary>
        public static List<string> SeperateToListString(this string inputText, char seperator = ',')
        {
            if (string.IsNullOrWhiteSpace(inputText))
                return new List<string>();

            return inputText.Split(seperator)
                .Where(z => !string.IsNullOrWhiteSpace(z))
                .Select(x => x.Trim())
                .ToList();
        }

        /// <summary>
        /// 不含http、https 
        /// </summary>
        public static string GetDoaminByUrl(this string inputText)
        {
            if (string.IsNullOrWhiteSpace(inputText))
                return string.Empty;

            try
            {
                var myUri = new Uri(inputText);
                return myUri.Host;
            }
            catch
            {

            }

            return string.Empty;
        }

        /// <summary>
        /// 如果是空字串null則直接返回
        /// </summary>
        public static string TrimOrNothing(this string inputText)
        {
            if (string.IsNullOrWhiteSpace(inputText))
                return inputText;

            return inputText.Trim();
        }


        public static bool ToLowerCompareEqual(this string inputText , params string[] keywords)
        {
            if (string.IsNullOrWhiteSpace(inputText))
                return false;

            inputText = inputText.ToLower();
            return keywords.Select(z => z.ToLower()).Any(text => text == inputText);
        }

        public static string TrimOrNull(this string inputText)
        {
            if (string.IsNullOrWhiteSpace(inputText))
                return inputText;

            return inputText.Trim();
        }


        /// <summary>
        /// 若文字內有link字串，會轉成html link
        /// </summary>
        public static string ConvertToHtmlLinkFormat(this string inputText)
        {
            Regex r = new Regex(@"(https?://[^\s]+)");
            return r.Replace(inputText, "<a target='_blank' href=\"$1\">$1</a>");
        }

        public static Nullable<T> TryToEnum<T>(this string str) where T : struct
        {
            T result = default(T);
            if (Enum.TryParse(str, true, out result))
            {
                return result;
            }
            return null;
        }


        public static string Base64Encode(this string str)
        {
            byte[] bytes = System.Text.Encoding.GetEncoding("utf-8").GetBytes(str);
            string result = Convert.ToBase64String(bytes);
            return result;
        }

        public static bool IsBase64String(string base64)
        {
            Span<byte> buffer = new Span<byte>(new byte[base64.Length]);
            return Convert.TryFromBase64String(base64, buffer, out int bytesParsed);
        }


        public static string Base64Decode(this string str)
        {
            if (!IsBase64String(str))
                return "";

            byte[] data = Convert.FromBase64String(str);
            string decodedString = Encoding.UTF8.GetString(data);
            return decodedString;
        }


        public static string UrlEncode(this string str)
        {
            return HttpUtility.UrlEncode(str);
        }

        public static string UrlDecode(this string str)
        {
            return HttpUtility.UrlDecode(str);
        }


        /// <summary>
        /// 取得兩個文字中間的所有文字
        /// </summary>
        /// <param name="input"></param>
        /// <param name="prefixWord"></param>
        /// <param name="suffixWord"></param>
        /// <returns></returns>
        public static string GetInnerTextBetweenTwoWord(this string input, string prefixWord, string suffixWord)
        {
            int pFrom = input.IndexOf(prefixWord) + prefixWord.Length;
            int pTo = input.LastIndexOf(suffixWord);

            if (pFrom < 0 || pTo < 0)
                return string.Empty;

            string result = input.Substring(pFrom, pTo - pFrom);
            return result;
        }




        /// <summary>
        /// 從最後一個字開始取 取N個字 (預設值為一個字)
        /// </summary>
        public static string GetLastText(this string input, int previousHowMany = 1)
        {
            string tempText = string.Empty;

            if (string.IsNullOrWhiteSpace(input))
                return input;

            if (input.Length > previousHowMany)
                tempText = input.Substring(input.Length - previousHowMany);
            else if (input.Length == previousHowMany)
                return input;

            return tempText;
        }


        public static string ReplaceNonNumeric(this string input)
        {
            Regex digitsOnly = new Regex(@"[^\d]");
            return digitsOnly.Replace(input, "");
        }


        /// <summary>
        /// 幫重要隱私資訊 後半段打上馬賽克
        /// </summary>
        /// <param name="input"></param>
        /// <returns></returns>
        public static string GetMaskPrivateText(this string input, int prefixLength = 2)
        {
            if (string.IsNullOrWhiteSpace(input))
                return string.Empty;

            if (input.Length <= 2)
                return input;

            return string.Format("{0}*******", input.Truncate(prefixLength, ""));
        }



        /// <summary>
        /// 其實只是把前幾個字轉成小寫而已的寫法
        /// </summary>
        /// <param name="input"></param>
        /// <param name="letterSequence_MustReplace">前面幾位字</param>
        /// <returns></returns>
        public static string ToCamelCase(this string input, int letterSequence_MustReplace = 1)
        {
            // 若字數不足
            if (string.IsNullOrWhiteSpace(input))
                return input;

            StringBuilder sb = new StringBuilder();
            for (int i = 0; i < input.Length; i++)
            {
                if (i < letterSequence_MustReplace)
                {
                    sb.Append(Char.ToLower(input[i]));
                }
            }
            sb.Append(input.Substring(letterSequence_MustReplace, input.Length - letterSequence_MustReplace));
            return sb.ToString();
        }



        /// <summary>
        /// 幫重要隱私資訊
        /// </summary>
        /// <param name="input"></param>
        /// <param name="startIndex">起始字元位置</param>
        /// <param name="length">長度</param>
        /// <param name="c">隱藏顯示字(預設為星號)</param>
        /// <returns></returns>
        public static string GetMaskPrivateText(this string input, int startIndex, int length, char c = '*')
        {
            if (string.IsNullOrWhiteSpace(input))
                return string.Empty;

            if (input.Length < startIndex + length)
                return input;

            StringBuilder sb = new StringBuilder();
            sb.Append(input.Substring(0, startIndex));
            sb.Append(c, length);
            sb.Append(input.Substring(startIndex + length, input.Length - startIndex - length));

            return sb.ToString();
        }


        /// <summary>
        /// MD5 預設是 UTF8
        /// </summary>
        public static string MD5Hash(this string source , System.Text.Encoding encode = null)
        { 
            // Convert the input string to a byte array and compute the hash.
            byte[] data = null;

            if (encode == null)
            {
                data = Encoding.UTF8.GetBytes(source);
            }
            else
            {
                data = encode.GetBytes(source);
            }

            return string.Join("", MD5.Create().ComputeHash(data).Select(s => s.ToString("x2")));
        }




        /// <summary>
        /// 如果最后一个字与指定的相同 取代之
        /// </summary>
        public static string TruncateLastWordIfMatch(this string source , char targetText)
        {
            if (string.IsNullOrWhiteSpace(source))
                return string.Empty;

            var c = source.LastOrDefault();

            if (c != targetText)
                return source;

            return source.Substring(0, source.Length - 1);
        }



        /// <summary>
        /// 取代多餘的斜線符號
        /// </summary>
        public static string ReplaceDuplicateUrlChar(this string source)
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


        /// <summary>
        /// 取代掉所有特殊符號
        /// </summary>
        public static string ReplaceAllSpecialChar(this string source, string replaceToWhat = "")
        {
            if (string.IsNullOrWhiteSpace(source))
                return string.Empty;

            return Regex.Replace(source, @"[\W_]+", replaceToWhat);
        }


        /// <summary>
        /// 取代掉所有特殊符號
        /// </summary>
        public static string CutLastCharIfMatch(this string source, char replaceKeyword)
        {
            if (string.IsNullOrWhiteSpace(source))
                return source;

            char lastText = source.LastOrDefault();

            //如果最後一個字相同
            if (lastText == replaceKeyword)
                source = source.Substring(0, source.Length - 1);

            return source;
        }


        /// <summary>
        /// 判斷輸入是否為中文
        /// ps: 中英混合 也算true，數字不允許
        /// </summary>
        public static bool IsChinese(this string inputText)
        {
            if (IsContainNumber(inputText))
                return false;

            return Regex.IsMatch(inputText, "[\u4e00-\u9fa5]+");
        }


        public static bool IsContainNumber(this string inputText)
        {
            return Regex.IsMatch(inputText, "[0-9]");
        }


        /// <summary>
        /// 取出前N個字，並在最後加上 ...
        /// </summary>
        public static string Truncate(this string s, int length = 30, string truncation = "")
        {
            if (string.IsNullOrWhiteSpace(s))
                return string.Empty;

            return s.Length > length ?
                s.Substring(0, length - truncation.Length) + truncation : s;
        }


        /// <summary>
        /// 將 HTML tag 移除
        /// </summary>
        public static string StripHTML(this string htmlString)
        {
            if (string.IsNullOrEmpty(htmlString))
                htmlString = string.Empty;

            const string patternTag = @"<(.|\n)*?>";
            const string patternAscii = @"&((.[a-zA-Z])*);";

            return Regex.Replace(Regex.Replace(htmlString, patternTag, string.Empty), patternAscii, string.Empty).HtmlDecode();
        }
        

        /// <summary>
        /// 將 html 4.0 Special Entities移除
        /// </summary>
        public static string HtmlDecode(this string inputString)
        {
            if (string.IsNullOrWhiteSpace(inputString))
                return string.Empty;

            return HttpUtility.HtmlDecode(inputString);
        }


        /// <summary>
        /// 反轉字串
        /// </summary>
        public static string Reverse(this string s)
        {
            if (String.IsNullOrEmpty(s))
                return string.Empty;

            var charArray = new char[s.Length];
            var len = s.Length - 1;
            for (var i = 0; i <= len; i++)
            {
                charArray[i] = s[len - i];
            }
            return new string(charArray);
        }


        /// <summary>
        /// 判斷string是否為數字
        /// </summary>
        public static bool IsNumeric(this string s)
        {
            // Define variable to collect out parameter of the TryParse method. If the conversion fails, the out parameter is zero.
            double retNum;

            bool isNum = Double.TryParse(Convert.ToString(s), System.Globalization.NumberStyles.Any, System.Globalization.NumberFormatInfo.InvariantInfo, out retNum);
            return isNum;
        }


        /// <summary>
        /// 判斷string是否為Guid
        /// </summary>
        public static bool IsGuid(this string s)
        {
            if (string.IsNullOrWhiteSpace(s))
                return false;

            if (s.Length < 32 || s.Length > 40)
                return false;

            bool isGuid = Guid.TryParse(Convert.ToString(s), out var retGuid);
            return isGuid;
        }
               
        public static bool IsNotEmptyTextNotNull(this string source)
        {
            return !string.IsNullOrWhiteSpace(source);
        }

        public static bool IsNullOrWhiteSpace(this string source)
        {
            return string.IsNullOrWhiteSpace(source);
        }



    }
}