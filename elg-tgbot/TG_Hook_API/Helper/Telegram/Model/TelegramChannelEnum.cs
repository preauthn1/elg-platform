using System;
using System.Collections.Generic;
using System.ComponentModel;
using System.Linq;
using System.Text;
using System.Threading.Tasks;

namespace target.Utility.Utility.ThirdParty
{
    public enum TelegramChannelEnum
    {
        //查看TG cahtID 可以透過  https://example.invalid/bot1490132862:AAEV0BmAVOfWrXnnwNrfAvwT-nTqV_wbQ3Q/getUpdates

        [Description("-615178692")]
        pgs_merchant_statistic_report,

        [Description("-576451364")]
        pgs_common_error,

        [Description("-483617918")]
        pgs_single_wallet_erorr,

        [Description("-401633394")]
        pgs_job_api,

        [Description("-541624047")]
        pgs_externalAPI,

        [Description("-607182589")]
        pgs_adminSys_api,

        [Description("-505089736")]
        pgs_risk_control,

        [Description("-894381258")]
        pgs_rtp_warning,
    }

}
