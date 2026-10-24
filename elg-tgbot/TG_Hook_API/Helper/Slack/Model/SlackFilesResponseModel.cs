using System;
using System.Collections.Generic;
using System.Linq;
using System.Text;
using System.Threading.Tasks;

namespace target.Utility.Utility.ThirdParty
{


    public class SlackFilesResponseModel
    {

        public string ok { get; set; }

        public IEnumerable<SlackFileDto> files { get; set; }

    }



    public class SlackFileDto
    {

        public string id { get; set; }


        public string name { get; set; }

            

        public string title { get; set; }



        public string mimetype { get; set; }


        public string timestamp { get; set; }



        public IEnumerable<string> channels { get; set; } 





        public DateTime CreateDtm
        {
            get
            {
                return GetDatetimeByTimestamp(this.timestamp);
            }
        }



        private DateTime GetDatetimeByTimestamp(string timestamp)
        {
            System.DateTime dtDateTime = new DateTime(1970, 1, 1, 0, 0, 0, 0, System.DateTimeKind.Utc);
            dtDateTime = dtDateTime.AddSeconds(Convert.ToInt32(timestamp)).ToLocalTime();
            return dtDateTime;
        }

    }

}
