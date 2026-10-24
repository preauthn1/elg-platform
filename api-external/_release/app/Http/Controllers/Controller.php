<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    function getIp()
    {
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && isset($_SERVER['HTTP_VIA'])) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            return $_SERVER['REMOTE_ADDR'];
        }
    }

    function getPagination($total, $prePage, $page)
    {
        $pagination = array();
        $pagination['per_page'] = $prePage;
        $pagination['total'] = $total;
        $pages = ceil($total / $prePage);
        $pagination['pages'] = $pages;
        $pagination['previous'] = ($page != 1 && $total > 0) ? ($page - 1) : '';
        $pagination['current'] = $page;
        $pagination['next'] = ($pages > $page) ? ($page + 1) : '';
        return $pagination;
    }
}