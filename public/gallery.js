
/**
 *  SFW - SimpleFrameWork
 *
 *  Copyright (C) 2013  Stefan Paproth
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as
 *  published by the Free Software Foundation, either version 3 of the
 *  License, or (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program. If not, see
 *  http://www.gnu.org/licenses/agpl.txt.
 *
 */

$('.btnChangePreview').on('click', function() {
    const that = $(this);
    const itemId = that.data('item-id');
    const formId = that.data('form-id');
    const url = that.data('url');
    const target = that.data('target');

    $.ajax({
        type: "POST",
        url:  url + '?do=changePreview',
        dataType: "json",
        data: {
            id: itemId,
            xss: $('#xssToken').val()
        },
        success: function(response) {
            console.log('#' + formId + '_recordset_' + itemId);

            $('#xssToken').val(response.xss);
           // $('#deleteDialogModal').modal('hide');
           // $('#' + formId + '_recordset_' + itemId).fadeOut(1250, function() {$(this).remove();});
           // loadEntries(target, url, 1);
        },
        error: function(response) {
            $('#deleteDialogModal').modal('hide');
            showErrorDialog(response.responseJSON);
        }
    });
});





        showImageUploadDialogBox: function(callback, msg, cnccb){
            $('#dialogboxajaxloader').show();
            $('#btnDlgCancel').show();
            showDialogBox(
                'Bilder-Upload',
                msg,
                callback
            );
            $('#btnDlgCancel').focus();
            $('#btnDlgCancel').click(function(){
                hideDialogBox(cnccb);
            });
            return hideDialogBox;
        }







var files = [];

$('[id^="btnUpload"]').click(function(){
     sfw_login.isLoggedIn();

     if(!files){
         return false;
     }

     sfw_dialogbox.showImageUploadDialogBox(
         function(){uploadFile(files.pop());},
         dialogBoxContent(files),
         function(){window.location.reload();}
     );
     return true;
 });



        function uploadFile(file) {
            try{
                var reader = new FileReader();
                reader.onloadend = function(evt){
                    var fileType = file.type,
                        maxWidth = 800,
                        maxHeight = 800;
                    var image = new Image();
                    image.src = reader.result;
                    image.onload = function(){
                        var size = imageSize(
                            image.width,
                            image.height,
                            maxWidth,
                            maxHeight
                        ),
                        canvas = document.createElement('canvas');
                        canvas.width = size.width;
                        canvas.height = size.height;

                        var ctx = canvas.getContext("2d");
                        ctx.drawImage(this, 0, 0, size.width, size.height);
                        var data = canvas.toDataURL(fileType);
                        delete image;
                        delete canvas;
                        submitFile(data, file.name);
                    };
                };
                reader.readAsDataURL(file);
            } catch(err) {
                return false;
            }
        }

        var imageSize = function(width, height, maxWidth, maxHeight){
            var newWidth = width,
            newHeight = height;

            if(width > height){
                if(width > maxWidth){
                    newHeight *= maxWidth / width;
                    newWidth = maxWidth;
                }
            }else{
                if(height > maxHeight){
                    newWidth *= maxHeight / height;
                    newHeight = maxHeight;
                }
            }

            return {
                width: newWidth,
                height: newHeight
            };
        };

        var submitFile = function(data, filename) {
            $.post(
                window.location.pathname,
                {
                    'ajax_json' : true,
                    'do' : 'addImg',
                    'g' : $('#curgal').val(),
                    'file' : data,
                    'name' : filename
                },
                function(res){
                    var x = MD5(filename);
                    $('#' + x).fadeOut('slow', function(){
                        $('#' + x).remove();
                        if(files.length){
                           uploadFile(files.pop());
                            return;
                        }
                        window.location.reload();
                    });
                },
                "json"
            );

        };

        var dialogBoxContent = function(files){
            var ret = '';

            for(var i = 0; i < files.length; i++){
                f = files[i];
                ret = '<div id="' + MD5(f.name) + '" style="font-weight: bold; font-size: 0.8em;">' + f.name + '</div>' + ret;
            }
            return ret;
        };

/*
        if(curfile == undefined){
            ajaxAction(
                'create',
                $(':input').serializeJSON($(this).val() + '_' + templateId),
                loadPageContent
            );
            return;
        }

        try {
            var reader = new FileReader()
            reader.onloadend = send;
            reader.readAsBinaryString(curfile);
        } catch(err) {
            // TODO: Gescheite Fehlerausgabe!!!
            alert('Fehler');
            console.dir(err);
            return;
        }

        function send(e) {
			$('#ajaxloader').show();
            $('#pagecontent').css('height', $('#pagecontent').height());


			var xhr = new XMLHttpRequest(),
				upload = xhr.upload,
				file = curfile,
				start_time = new Date().getTime(),
				boundary = '------multipartformboundary' + (new Date).getTime(),
				builder = getBuilder(file.name, e.target.result, boundary);

			upload.index = 0;
			upload.file = file;
			upload.downloadStartTime = start_time;
			upload.currentStart = start_time;
			upload.currentProgress = 0;
			upload.startData = 0;


            //upload.addEventListener("progress", updateProgress, false);

			xhr.open(
                "POST",
                window.location.pathname +
                    '?ajax_xml=1&do=create&templateid=' + templateId,
                true
            );


            xhr.setRequestHeader(
                'content-type',
                'multipart/form-data; boundary=' + boundary
            );

			xhr.sendAsBinary(builder);

			xhr.onload = function() {
			    if (xhr.responseText) {
                    $('#reloadcontent').fadeOut('slow', function(){
                        $('#reloadcontent').html(xhr.responseText);
                        loadPageContent();
                        $('#ajaxloader').hide();
                    });
			    }
			};
		}

*/









/*

$(document).ready(function(){

        var files = [];

        $('.dropzone').click(function(){
            var id = sfw_helper.getId($(this).attr('id'));

            if($('#currfile_' + id).html() != ''){
                return;
            }

            if($('#fileSelectDescr_' + id).is(':visible')){
                $('#clearFileSelect_' + id).show();
                $('#fileSelectDescr_' + id).hide();
            }else{
                $('#clearFileSelect_' + id).hide();
                $('#fileSelectDescr_' + id).show();
            }
        });

        $('.fileselectupload').change(function(){
            var id = sfw_helper.getId($(this).attr('id'));
            handleFileSelect(this.files, id);
            $('#clearFileSelect_' + id).html($('#clearFileSelect_' + id).html());
        });

        var dz = $('.dropzone');

        dz.bind('dragover',  dragStart);
        dz.bind('dragexit',  dragStop);
        dz.bind('dragleave', dragStop);
        dz.bind('drop',      drop);

        function dragStart(e){
            e.originalEvent.stopPropagation();
            e.originalEvent.preventDefault();
            $('#removeBtn').css('color', '#0B8C37');
            $(this).css('color', '#0B8C37');
            $(this).css('border-color', '#0B8C37');
            $(this).css('text-shadow', '0px 0px 10px #7A7A7A, -0px -0px black');
        }

        function dragStop(e){
            e.originalEvent.stopPropagation();
            e.originalEvent.preventDefault();
            $('#removeBtn').css('color', '#BBB');
            $(this).css('color', '#BBB');
            $(this).css('border-color', '#BBB');
            $(this).css('text-shadow', 'none');
        }

        function drop(e) {
            e.originalEvent.stopPropagation();
            e.originalEvent.preventDefault();
            var id = sfw_helper.getId($(this).attr('id'));
            $('#removeBtn_' + id).css('color', '#BBB');
            $(this).css('color', '#BBB');
            $(this).css('border-color', '#BBB');
            $(this).css('text-shadow', 'none');
            handleFileSelect(e.originalEvent.dataTransfer.files, id);
        }



        function handleFileSelect(f, id){
            if(!f){
                $('.cf_' + id).remove();
                $('#title_' + id).val('');
                $('#btnRemove_' + id).hide();
                $('#fileSelectDescr_' + id).show();
                $('#btnUpload_' + id).hide();
                return;
            }

            for(var i = 0; i < f.length; i++){
                var len = files.length;
                var y = 0;
                var found = false;
                for(; y < len; y++){
                    if(
                        files[y].name === f[i].name &&
                        files[y].type === f[i].type &&
                        files[y].size === f[i].size
                    ){
                        found = true;
                        break;
                    }
                }

                if(!f[i].type.match(/^image\//) || found){
                    continue;
                }

                files.push(f[i]);

                if(files.length > 4){
                    continue;
                }
                var div = $('<div></div>');
                div.addClass('currfile');
                div.addClass('cf_' + id);
                div.addClass('currfilemulti');
                div.html(f[i].name + ' (' + parseInt(f[i].size / 1024) + ' KBytes)');

                $('#dropzone_' + id).append($(div));
                $('#btnRemove_' + id).show();
                $('#fileSelectDescr_' + id).hide();
                $('#btnUpload_' + id).show();
            }

            if(files.length > 4){
                if($('#filemultilast').length === 0){
                    div = $('<div></div>');
                    div.addClass('currfile');
                    div.addClass('cf_' + id);
                    div.addClass('currfilemulti');
                    div.attr('id', 'filemultilast');
                    $('#dropzone_' + id).append($(div));
                }
                $('#filemultilast').html(
                    '... und ' +  ((files.length == 5)?'einer weiteren Datei':(files.length - 4) + ' weiteren Dateien')
                );
            }
        }

        $('.btnRemove').click(function(){
            var id = sfw_helper.getId($(this).attr('id'));
            $('#clearFileSelect_' + id).html($('#clearFileSelect_' + id).html());
            $('#fileselectupload_' + id).change(function(){
                var id = sfw_helper.getId($(this).attr('id'));
                handleFileSelect(this.files, id);
                $('#clearFileSelect_' + id).html($('#clearFileSelect_' + id).html());
            });
            handleFileSelect(null, id);
            return false;
        });

        $('[id^="btnUpload"]').click(function(){
            sfw_login.isLoggedIn();

            if(!files){
                return false;
            }

            sfw_dialogbox.showImageUploadDialogBox(
                function(){uploadFile(files.pop());},
                dialogBoxContent(files),
                function(){window.location.reload();}
            );
            return true;
        });

        function uploadFile(file){
            try{
                var reader = new FileReader();
                reader.onloadend = function(evt){
                    var fileType = file.type,
                        maxWidth = 800,
                        maxHeight = 800;
                    var image = new Image();
                    image.src = reader.result;
                    image.onload = function(){
                        var size = imageSize(
                            image.width,
                            image.height,
                            maxWidth,
                            maxHeight
                        ),
                        canvas = document.createElement('canvas');
                        canvas.width = size.width;
                        canvas.height = size.height;

                        var ctx = canvas.getContext("2d");
                        ctx.drawImage(this, 0, 0, size.width, size.height);
                        var data = canvas.toDataURL(fileType);
                        delete image;
                        delete canvas;
                        submitFile(data, file.name);
                    };
                };
                reader.readAsDataURL(file);
            } catch(err) {
                return false;
            }
        }

        var imageSize = function(width, height, maxWidth, maxHeight){
            var newWidth = width,
            newHeight = height;

            if(width > height){
                if(width > maxWidth){
                    newHeight *= maxWidth / width;
                    newWidth = maxWidth;
                }
            }else{
                if(height > maxHeight){
                    newWidth *= maxHeight / height;
                    newHeight = maxHeight;
                }
            }

            return {
                width: newWidth,
                height: newHeight
            };
        };

        var submitFile = function(data, filename) {
        /*
            $.ajax({
                type: 'POST',
                url: window.location.pathname,
                data: {
                    'ajax_json' : true,
                    'do' : 'addImg',
                    'g' : $('#curgal').val(),
                    'file' : data,
                    'name' : filename
                },
                beforeSend: function(XMLHttpRequest){
    console.dir(XMLHttpRequest);
                    XMLHttpRequest.progress = function(evt){
                        console.dir(evt);
                        if (evt.lengthComputable) {
                            var percentComplete = evt.loaded / evt.total;
                            console.dir(percentComplete);
                        }
                    };
                },
                success: function(data){
                    //Do something success-ish
                }
            });
      * /

            $.post(
                window.location.pathname,
                {
                    'ajax_json' : true,
                    'do' : 'addImg',
                    'g' : $('#curgal').val(),
                    'file' : data,
                    'name' : filename
                },
                function(res){
                    var x = MD5(filename);
                    $('#' + x).fadeOut('slow', function(){
                        $('#' + x).remove();
                        if(files.length){
                           uploadFile(files.pop());
                            return;
                        }
                        window.location.reload();
                    });
                },
                "json"
            );

        };

        var dialogBoxContent = function(files){
            var ret = '';

            for(var i = 0; i < files.length; i++){
                f = files[i];
                ret = '<div id="' + MD5(f.name) + '" style="font-weight: bold; font-size: 0.8em;">' + f.name + '</div>' + ret;
            }
            return ret;
        };

/*
        if(curfile == undefined){
            ajaxAction(
                'create',
                $(':input').serializeJSON($(this).val() + '_' + templateId),
                loadPageContent
            );
            return;
        }

        try {
            var reader = new FileReader()
            reader.onloadend = send;
            reader.readAsBinaryString(curfile);
        } catch(err) {
            // TODO: Gescheite Fehlerausgabe!!!
            alert('Fehler');
            console.dir(err);
            return;
        }

        function send(e) {
			$('#ajaxloader').show();
            $('#pagecontent').css('height', $('#pagecontent').height());


			var xhr = new XMLHttpRequest(),
				upload = xhr.upload,
				file = curfile,
				start_time = new Date().getTime(),
				boundary = '------multipartformboundary' + (new Date).getTime(),
				builder = getBuilder(file.name, e.target.result, boundary);

			upload.index = 0;
			upload.file = file;
			upload.downloadStartTime = start_time;
			upload.currentStart = start_time;
			upload.currentProgress = 0;
			upload.startData = 0;


            //upload.addEventListener("progress", updateProgress, false);

			xhr.open(
                "POST",
                window.location.pathname +
                    '?ajax_xml=1&do=create&templateid=' + templateId,
                true
            );


            xhr.setRequestHeader(
                'content-type',
                'multipart/form-data; boundary=' + boundary
            );

			xhr.sendAsBinary(builder);

			xhr.onload = function() {
			    if (xhr.responseText) {
                    $('#reloadcontent').fadeOut('slow', function(){
                        $('#reloadcontent').html(xhr.responseText);
                        loadPageContent();
                        $('#ajaxloader').hide();
                    });
			    }
			};
		}

* /
});
 */