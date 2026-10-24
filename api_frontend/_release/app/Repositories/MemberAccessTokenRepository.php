<?php

namespace App\Repositories;

use DB;
use App\Models\MemberAccessToken;

class MemberAccessTokenRepository
{   

    public function DoLogoutProcess($token)
    {     
         $data = array();

         // 更新TOKEN過期時間
         $data['expire_date'] = date('Y-m-d H:i:s');
         MemberAccessToken:[REDACTED]'token', $token)->update($data);        

    }
}