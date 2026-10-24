<?php

namespace App\ThirdPartyApi\Models;


class ThirdPartyApiPayoutResult
{        
    function __construct() {
        
    }

    public $is_success;
    
    public $third_party_order_no;

    public $third_party_response;

    public $display_error_msg;
}