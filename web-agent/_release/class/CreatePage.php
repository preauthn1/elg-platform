<?php
class CreatePage
{


    //產生頁碼版型
    public function createPager($totalPage, $nowPage, $lang = null)
    {
    	if (!is_null($lang)) {
    		$previous_page = $lang['previous_page'];
    		$next_page = $lang['next_page'];
    	} else {
    		$previous_page = '上一頁';
    		$next_page = '下一頁';
    	}
    	
        $pagination = '';

        //上一頁
        $pagination .= '<a class="paginate_button previous ' . (($nowPage == 1) ? 'disabled' : '') . '" data-dt-idx="' . ($nowPage - 1) . '" id="dt-opt_previous">' . $previous_page . '</a>';

        $pagination .= '<span>';
        //頁數
        if ($totalPage < 10) {
            //直接列出
            for ($i = 1; $i <= $totalPage; $i++) {
                $pagination .= '<a class="paginate_button ' . (($nowPage == $i) ? 'current disabled' : '') . '" data-dt-idx="' . $i . '">' . $i . '</a>';
            }
        } else {
            $t = ($nowPage + 5) > $totalPage ? $totalPage : ($nowPage + 5);
            $i = ($t - 9) < 1 ? 1 : ($t - 9);
            $t = $i + 9;
            for ($i; $i <= $t; $i++) {
                $pagination .= '<a class="paginate_button ' . (($nowPage == $i) ? 'current disabled' : '') . '" data-dt-idx="' . $i . '">' . $i . '</a>';
            }
        }

        $pagination .= '</span>';
        //下一頁
        $pagination .= '<a class="paginate_button next ' . (($nowPage == $totalPage) ? 'disabled' : '') . '" data-dt-idx="' . ($nowPage + 1) . '" id="dt-opt_next">' . $next_page . '</a>';

        return $pagination;
    }
}