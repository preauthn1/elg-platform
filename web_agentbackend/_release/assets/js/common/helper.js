
var clipboardHelper = {

    copyElement: function ($element) {
        this.copyText($element.text())
    },

    copyText: function (text) // Linebreaks with \n
    {
        var $tempInput = $("<textarea>");
        $("body").append($tempInput);
        $tempInput.val(text).select();
        document.execCommand("copy");
        $tempInput.remove();
    }
};



var alertHelper = {        

    successAlert: function() {

        $("#success-alert").show();
        $("#success-alert").addClass("show");

        setTimeout(() => {

            $("#success-alert").removeClass("show");
            $("#success-alert").hide();

        }, 2500);

    },

    successCopyAlert: function() {

        $("#success-copy-alert").show();
        $("#success-copy-alert").addClass("show");

        setTimeout(() => {

            $("#success-copy-alert").removeClass("show");
            $("#success-copy-alert").hide();

        }, 2500);

    }

};




var imageHelper = {        

    reigsterFileUploadAutoUploadToRemoteEvent: function(config_param)
    {
        /*
            請傳入類似這樣的物件
            var config_param =
            {
                input_file_elementID : 'file1',
                input_tempUrl_elementID : 'file1',
                preivew_image_elementID : 'image1',
                uploadCallback : function() { ... }
            };
         */

        $('#' + config_param.input_file_elementID ).change(function(e) {
            if (e.preventDefault) {
                e.preventDefault();
            } else {
                window.event.returnValue == false;
            }

            imageHelper.convertToBase64(config_param); // prints the base64 string
        });
    },


    convertToBase64: function(config_param) {

        var file = document.querySelector('input[type=file]').files[0];

        var reader = new FileReader();
        reader.readAsDataURL(file);
        reader.onload = function() {
            //RETURN後變undefined，所以先在這處理
            var image = reader.result;
            $('#' + config_param.preivew_image_elementID).attr('src', image);
            $('#' + config_param.input_tempUrl_elementID).val(image);

            imageHelper.uploadImageToRemote(config_param , image);

            return reader.result;
        };
        reader.onerror = function(error) {
            return 'Error: ', error;
        };

    },

     uploadImageToRemote: function(config_param , image64) {

        $.blockUI({
            baseZ: 9000,
            overlayCSS: {
                backgroundColor: '#444444'
            }
        });
    
        let form = "action=upload_image&base64Image=" + image64;
        $.post("/ajax.php", form, function(data) {
            $.unblockUI();

            if (data.code == 1000) {

                let input_tempUrl = $("#" + config_param.input_tempUrl_elementID);
                
                if(!(input_tempUrl === undefined) && input_tempUrl != null)
                {
                    input_tempUrl.val(data.data.path);
                }

                config_param.uploadCallback(data);
    
            } else {
                alert(data.message + "." + data.data);
            }
        }, 'json');
    
    }
};



