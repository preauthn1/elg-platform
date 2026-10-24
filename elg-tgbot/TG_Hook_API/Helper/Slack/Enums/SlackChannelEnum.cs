using System;
using System.Collections.Generic;
using System.Linq;
using System.Text;
using System.Threading.Tasks;

namespace target.Utility.Utility.ThirdParty
{
    public enum SlackChannelEnum
    {        
        [SlackTeam("target")]
        n_api_payment,

        [SlackTeam("target")]
        n_api_gameapi,

        [SlackTeam("target")]
        backend_release_k8s,

        [SlackTeam("target")]
        n_error_adminsys ,

        [SlackTeam("target")]
        n_error_game_api ,

        [SlackTeam("target")]
        n_error_public,

        [SlackTeam("target")]
        n_error_pgs_common,

        [SlackTeam("target")]
        n_tgbot_notify,

        [SlackTeam("target")]
        n_error_external_api,

        [SlackTeam("target")]
        n_pgs_risk_control,

        [SlackTeam("target")]
        n_error_merchant_api,

        [SlackTeam("target")]
        n_pgs_finance,

        [SlackTeam("target")]
        n_pgs_job_api,

        [SlackTeam("target")]
        n_pgs_externalvendor,
    }

    /// <summary>
    /// 純粹拿來標記 是屬於哪個組織下的頻道 
    /// 跟程式碼運作無關
    /// </summary>
    public class SlackTeam : Attribute
    {
        public SlackTeam(string teamName)
        {

        }
    }
    

}
