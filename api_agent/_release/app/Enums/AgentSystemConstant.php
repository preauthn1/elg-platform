<?php

namespace App\Enums;


class AgentSystemConstant 
{

    /* 定義哪些類型的操作類型 可以給一般代理查詢 */
    public static function availableOperateTypes()
    {  
        $array = array(
            "change_password" ,
            "update_member" , 
            "update_agent" , 
            "migrate_agent" , 
            "agent_permission_sync",
            "agent_login_fail" , 
            "agent_login",
            'order_manual_deposit',
            'order_reset_status',
            'cs_user_login_fail',
            'cs_user_login',
            'update_mobile_validate'
        );

        return $array;
    }
        
    /* 取得代理後台支援哪些語系 */
    public static function availableLanguagesInAgentBackend()
    {  
        $array = array(
            1 ,
            2, 
            3
        );

        return $array;
    }

}