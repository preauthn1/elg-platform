// Data Table JavaScripts
(function($) {
    'use strict';

    $('#dt-opt').DataTable();
    $('#dt-opt1').DataTable();
    $('#dt-opt2').DataTable({
        "order": [
            [0, 'desc']
        ]
    });

})(jQuery);


var dataTableHelper = {


    initialTableSearchFormClearEvent: function() {

        //清除查詢資料
        $(".clear").click(function(e) {
            if (e.preventDefault) {
                e.preventDefault();
            } else {
                window.event.returnValue == false;
            }
            window.location = 'index.php';
        });

    },


    initialPagingEvent: function(callbackFunction) {

        //換頁
        $(document).on('click', '.paginate_button', function(e) {
            if (e.preventDefault) {
                e.preventDefault();
            } else {
                window.event.returnValue == false;
            }
            var page = $(this).attr('data-dt-idx');
            var is_disable = $(this).hasClass('disabled');

            if (!is_disable) {

                //呼叫指定的function
                callbackFunction(page);
            }
        });

    },

    
    initialPagingEventBySelector: function(parentSelector , callbackFunction) {

        //換頁
        $(document).on('click', parentSelector + ' .paginate_button', function(e) {
            if (e.preventDefault) {
                e.preventDefault();
            } else {
                window.event.returnValue == false;
            }
            var page = $(this).attr('data-dt-idx');
            var is_disable = $(this).hasClass('disabled');

            if (!is_disable) {

                //呼叫指定的function
                callbackFunction(page);
            }
        });

    }



}