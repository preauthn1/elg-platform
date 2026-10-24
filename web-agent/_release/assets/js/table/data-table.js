// Data Table JavaScripts

(function ($) {
	'use strict';
    
    $('#dt-opt').DataTable();
    $('#dt-opt1').DataTable();
    $('#dt-opt2').DataTable(
        {
            "order": [[0, 'desc']]
        }
    );

})(jQuery);
