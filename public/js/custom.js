/**
 *
 * You can write your JS code here, DO NOT touch the default style file
 * because it will make it harder for you to update.
 *
 */

"use strict";
// for pos system
var session_key = $(location).attr("href").split('/').pop();
//

$(function () {
    if ($('.custom-scroll').length) {
        $(".custom-scroll").niceScroll();
        $(".custom-scroll-horizontal").niceScroll();
    }


    // loadConfirm();


});

//file validation
$(document).ready(function() {
    $(document).on("change",".file-validate",function(){
        let file_input = $(this)[0];
        let max_size = file_size;
        let allowed_extensions = file_types;
        let file_error = $('.file-error');
        file_error.text('');
        if (file_input.files.length > 0) {
            let file = file_input.files[0];
            let file_size = file.size / 1024;
            let file_extension = file.name.split('.').pop().toLowerCase();
            let extensions_array = allowed_extensions.split(',');
            if (!extensions_array.includes(file_extension)) {
                $(this).next().text(type_err);
                file_input.value = '';
                // document.getElementById('blah').src = '';
                return false;
            } else if (file_size > max_size) {
                $(this).next().text(size_err);
                file_input.value = '';
                // document.getElementById('blah').src = '';
                return false;
            }
        }
        return true;
    });
});


function validation() {
    var forms = document.querySelectorAll('.needs-validation');

    Array.prototype.forEach.call(forms, function (form) {

        form.addEventListener('submit', function (event) {
            var submitButton = form.querySelector('button[type="submit"], input[type="submit"]');

            if (submitButton) {
                submitButton.disabled = true;
            }
            if (form.checkValidity() === false) {
                event.preventDefault();
                event.stopPropagation();
                if (submitButton) {
                    submitButton.disabled = false;
                }
            }

            form.classList.add('was-validated');
        }, false);
    });
}


function getDatatableLabels() {
    return (typeof window.datatableLabels === 'object' && window.datatableLabels !== null)
        ? { labels: window.datatableLabels }
        : {};
}

$(document).on('click', '.ischeck', function () {
    var ischeck = $(this).data('id');
    if (!ischeck) {
        return;
    }
    $('.isscheck_' + ischeck).prop('checked', this.checked);
});

$(document).ready(function () {
    if ($(".datatable").length > 0) {
        const dataTable =  new simpleDatatables.DataTable(".datatable", getDatatableLabels());

    }

    select2();
    summernote();
    daterange();
    // loadConfirm();
});

$(document).ready(function () {

    if ($(".needs-validation").length > 0) {
        validation();
    }

    if ($(".pc-dt-simple").length > 0) {
        $( $(".pc-dt-simple") ).each(function( index,element ) {
            var id = $(element).attr('id');
            const dataTable = new simpleDatatables.DataTable("#"+id, getDatatableLabels());
        });
    }

    select2();
    summernote();
    daterange();
});

function daterange() {
    if ($("#pc-daterangepicker-1").length > 0) {
        document.querySelector("#pc-daterangepicker-1").flatpickr({
            mode: "range"
        });
    }
}


function select2() {
    if ($(".select2").length > 0) {
        $($(".select2")).each(function (index, element) {
            var id = $(element).attr('id');
            var multipleCancelButton = new Choices(
                '#' + id, {
                    removeItemButton: true,
                }
            );
        });

    }
}

function show_toastr(type, message) {

    var f = document.getElementById('liveToast');
    var a = new bootstrap.Toast(f).show();
    if (type == 'success') {
        $('#liveToast').addClass('bg-primary');
    } else {
        $('#liveToast').addClass('bg-danger');
    }
    $('#liveToast .toast-body').html(message);
}

$(document).on('click', 'a[data-ajax-popup="true"], button[data-ajax-popup="true"], div[data-ajax-popup="true"]', function () {

    var data = {};
    var title1 = $(this).data("title");

    var title2 = $(this).data("bs-original-title");
    var title3 = $(this).data("original-title");
    var title = (title1 != undefined) ? title1 : title2;
    var title=(title != undefined) ? title : title3;

    $('.modal-dialog').removeClass('modal-xl');
    var size = ($(this).data('size') == '') ? 'md' : $(this).data('size');

    var url = $(this).data('url');
    $("#commonModal .modal-title").html(title);
    $("#commonModal .modal-dialog").addClass('modal-' + size);

    if ($('#vc_name_hidden').length > 0) {
        data['vc_name'] = $('#vc_name_hidden').val();
    }
    if ($('#warehouse_name_hidden').length > 0) {
        data['warehouse_name'] = $('#warehouse_name_hidden').val();
    }
    if ($('#discount_hidden').length > 0) {
        data['discount'] = $('#discount_hidden').val();
    }
    if ($('#quotation_id').length > 0) {
        data['quotation_id'] = $('#quotation_id').val();
    }
    if ($('#pos_order_type_hidden').length > 0) {
        data['pos_order_type'] = $('#pos_order_type_hidden').val();
    }
    if ($('#pos_table_id_hidden').length > 0) {
        data['pos_table_id'] = $('#pos_table_id_hidden').val();
    }
    if ($('#pos_shift_id_hidden').length > 0) {
        data['pos_shift_id'] = $('#pos_shift_id_hidden').val();
    }
    $.ajax({
        url: url,
        data: data,
        success: function (data) {
            $('#commonModal .body').html(data);
            $("#commonModal").modal('show');
            taskCheckbox();
            common_bind("#commonModal");
            if (document.querySelector('#commonModal #assigned_users')) {
                new Choices('#assigned_users', {
                    removeItemButton: true,
                    shouldSort: false,
                    searchResultLimit: 20,
                });
            }
            validation();
            commonLoader();

        },
        error: function (data) {
            data = data.responseJSON;
            show_toastr('Error', data.error, 'error')
        }
    });

});


function arrayToJson(form) {
    var data = $(form).serializeArray();
    var indexed_array = {};

    $.map(data, function (n, i) {
        indexed_array[n['name']] = n['value'];
    });

    return indexed_array;
}


function common_bind() {
    select2();
}


function taskCheckbox() {
    var checked = 0;
    var count = 0;
    var percentage = 0;

    count = $("#check-list input[type=checkbox]").length;
    checked = $("#check-list input[type=checkbox]:checked").length;
    percentage = parseInt(((checked / count) * 100), 10);
    if (isNaN(percentage)) {
        percentage = 0;
    }
    $(".custom-label").text(percentage + "%");
    $('#taskProgress').css('width', percentage + '%');


    $('#taskProgress').removeClass('bg-warning');
    $('#taskProgress').removeClass('bg-primary');
    $('#taskProgress').removeClass('bg-success');
    $('#taskProgress').removeClass('bg-danger');

    if (percentage <= 15) {
        $('#taskProgress').addClass('bg-danger');
    } else if (percentage > 15 && percentage <= 33) {
        $('#taskProgress').addClass('bg-warning');
    } else if (percentage > 33 && percentage <= 70) {
        $('#taskProgress').addClass('bg-primary');
    } else {
        $('#taskProgress').addClass('bg-success');
    }
}


function commonLoader() {
    $('[data-toggle="tooltip"]').tooltip();
    if ($('[data-toggle="tags"]').length > 0) {
        $('[data-toggle="tags"]').tagsinput({tagClass: "badge badge-primary"});
    }


    // $(function(){
    //
    //     var dtToday = new Date();
    //
    //     var month = dtToday.getMonth() + 1;
    //     var day = dtToday.getDate();
    //     var year = dtToday.getFullYear();
    //     if(month < 10)
    //         month = '0' + month.toString();
    //     if(day < 10)
    //         day = '0' + day.toString();
    //
    //     var maxDate = year + '-' + month + '-' + day;
    //
    //     $("input[type='date']").attr('max', maxDate);
    // });

    var e = $(".scrollbar-inner");
    e.length && e.scrollbar().scrollLock()

    var e1 = $(".custom-input-file");
    e1.length && e1.each(function () {
        var e1 = $(this);
        e1.on("change", function (t) {
            !function (e, t, a) {
                var n, o = e.next("label"), i = o.html();
                t && t.files.length > 1 ? n = (t.getAttribute("data-multiple-caption") || "").replace("{count}", t.files.length) : a.target.value && (n = a.target.value.split("\\").pop()), n ? o.find("span").html(n) : o.html(i)
            }(e1, this, t)
        }), e1.on("focus", function () {
            !function (e) {
                e.addClass("has-focus")
            }(e1)
        }).on("blur", function () {
            !function (e) {
                e.removeClass("has-focus")
            }(e1)
        })
    })

    // var e2 = $('[data-toggle="autosize"]');
    // e2.length && autosize(e2);


    if ($(".jscolor").length) {
        jscolor.installByClassName("jscolor");
    }
    summernote();
    // for Choose file
    $(document).on('change', 'input[type=file]', function () {
        var fileclass = $(this).attr('data-filename');
        var finalname = $(this).val().split('\\').pop();
        $('.' + fileclass).html(finalname);
    });
}


function summernote() {
    if ($(".summernote-simple").length) {
        $('.summernote-simple').summernote({
            toolbar: [
                ['style', ['style']],
                ['font', ['bold', 'italic', 'underline', 'strikethrough']],
                ['list', ['ul', 'ol', 'paragraph']],
                ['insert', ['link', 'unlink']],
            ],
            height: 250,
});
        $('.dropdown-toggle').dropdown();
    }

    if ($(".summernote-simple-2").length) {
        $('.summernote-simple-2').summernote({
            dialogsInBody: !0,
            minHeight: 200,
            maxHeight: 300,
            toolbar: [
                ['style', ['style']],
                ["font", ["bold", "italic", "underline", "clear", "strikethrough"]],
                ['fontname', ['fontname']],
                ['color', ['color']],
                ["para", ["ul", "ol", "paragraph"]],
            ],

        });
    }

}



$(document).on("click", '.bs-pass-para', function () {
    var form = $(this).closest("form");
    const swalWithBootstrapButtons = Swal.mixin({
        customClass: {
            confirmButton: 'btn btn-success',
            cancelButton: 'btn btn-danger'
        },
        buttonsStyling: false
    })
    swalWithBootstrapButtons.fire({
        title: 'Are you sure?',
        text: "This action can not be undone. Do you want to continue?",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes',
        cancelButtonText: 'No',
        reverseButtons: true
    }).then((result) => {
        if (result.isConfirmed) {

            form.submit();

        } else if (
            result.dismiss === Swal.DismissReason.cancel
        ) {
        }
    })
});

//only pos system delete button
$(document).on("click", '.bs-pass-para-pos', function () {

    const swalWithBootstrapButtons = Swal.mixin({
        customClass: {
            confirmButton: 'btn btn-success',
            cancelButton: 'btn btn-danger'
        },
        buttonsStyling: false
    })
    swalWithBootstrapButtons.fire({
        title: 'Are you sure?',
        text: "This action can not be undone. Do you want to continue?",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes',
        cancelButtonText: 'No',
        reverseButtons: true
    }).then((result) => {
        if (result.isConfirmed) {

            document.getElementById($(this).data('confirm-yes')).submit();

        } else if (
            result.dismiss === Swal.DismissReason.cancel
        ) {
        }
    })
});


function postAjax(url, data, cb) {
    var token = $('meta[name="csrf-token"]').attr('content');
    var jdata = {_token: token};

    for (var k in data) {
        jdata[k] = data[k];
    }

    $.ajax({
        type: 'POST',
        url: url,
        data: jdata,
        success: function (data) {
            if (typeof (data) === 'object') {
                cb(data);
            } else {
                cb(data);
            }
        },
    });
}

//end only pos system delete button


function deleteAjax(url, data, cb) {
    var token = $('meta[name="csrf-token"]').attr('content');
    var jdata = {_token: token};

    for (var k in data) {
        jdata[k] = data[k];
    }

    $.ajax({
        type: 'DELETE',
        url: url,
        data: jdata,
        success: function (data) {
            if (typeof (data) === 'object') {
                cb(data);
            } else {
                cb(data);
            }
        },
    });
}

// Google calendar
$(document).on('click', '.local_calender .fc-daygrid-event, .fc-timegrid-event', function (e) {
    // if (!$(this).hasClass('project')) {
    e.preventDefault();
    var event = $(this);
    var title1 = $('.fc-event-title').html();
    var title2 = $(this).data("bs-original-title");
    var title = (title1 != undefined) ? title1 : title2;
    // var size = ($(this).data('size') == '') ? 'md' : $(this).data('size');
    var size = 'md';
    var url = $(this).attr('href');
    $("#commonModal .modal-title").html(title);
    $("#commonModal .modal-dialog").addClass('modal-' + size);
    $.ajax({
        url: url,
        success: function (data) {
            $('#commonModal .body').html(data);
            $("#commonModal").modal('show');
            common_bind();
        },
        error: function (data) {
            data = data.responseJSON;
            toastrs('Error', data.error, 'error')
        }
    });
    // }
});

//date value 4

// $(function(){
//
//     var dtToday = new Date();
//
//     var month = dtToday.getMonth() + 1;
//     var day = dtToday.getDate();
//     var year = dtToday.getFullYear();
//     if(month < 10)
//         month = '0' + month.toString();
//     if(day < 10)
//         day = '0' + day.toString();
//
//     var maxDate = year + '-' + month + '-' + day;
//
//     $("input[type='date']").attr('max', maxDate);
// });

function addCommas(num) {
    var number = parseFloat(num).toFixed(2).replace(/(\d)(?=(\d{3})+(?!\d))/g, '$1,');
    return ((site_currency_symbol_position == "pre") ? site_currency_symbol : '') + number + ((site_currency_symbol_position == "post") ? site_currency_symbol : '');
}



// PLUS MINUS QUANTITY JS
function wcqib_refresh_quantity_increments() {
    jQuery("div.quantity:not(.buttons_added), td.quantity:not(.buttons_added)").each(function (a, b) {
        var c = jQuery(b);
        c.addClass("buttons_added"),
            c.children().first().before('<input type="button" value="-" class="minus" />'),
            c.children().last().after('<input type="button" value="+" class="plus" />')
    })
}

String.prototype.getDecimals || (String.prototype.getDecimals = function () {
    var a = this,
        b = ("" + a).match(/(?:\.(\d+))?(?:[eE]([+-]?\d+))?$/);
    return b ? Math.max(0, (b[1] ? b[1].length : 0) - (b[2] ? +b[2] : 0)) : 0
}), jQuery(document).ready(function () {
    wcqib_refresh_quantity_increments()
}), jQuery(document).on("updated_wc_div", function () {
    wcqib_refresh_quantity_increments()
}), jQuery(document).on("click", ".plus, .minus", function () {
    var a = jQuery(this).closest(".quantity").find('input[name="quantity"], input[name="quantity[]"]'),
        b = parseFloat(a.val()),
        c = parseFloat(a.attr("max")),
        d = parseFloat(a.attr("min")),
        e = a.attr("step");
    b && "" !== b && "NaN" !== b || (b = 0), "" !== c && "NaN" !== c || (c = ""), "" !== d && "NaN" !== d || (d = 0), "any" !== e && "" !== e && void 0 !== e && "NaN" !== parseFloat(e) || (e = 1), jQuery(this).is(".plus") ? c && b >= c ? a.val(c) : a.val((b + parseFloat(e)).toFixed(e.getDecimals())) : d && b <= d ? a.val(d) : b > 0 && a.val((b - parseFloat(e)).toFixed(e.getDecimals())), a.trigger("change")
});

$(document).on('click', 'input[name="quantity"], input[name="quantity[]"]', function (e) {
    // Allow: backspace, delete, tab, escape, enter and .
    if ($.inArray(e.keyCode, [46, 8, 9, 27, 13, 190]) !== -1 ||
        // Allow: Ctrl+A
        (e.keyCode == 65 && e.ctrlKey === true) ||
        // Allow: home, end, left, right
        (e.keyCode >= 35 && e.keyCode <= 39)) {
        // let it happen, don't do anything
        return;
    }
    // Ensure that it is a number and stop the keypress
    if ((e.shiftKey || (e.keyCode < 48 || e.keyCode > 57)) && (e.keyCode < 96 || e.keyCode > 105)) {
        e.preventDefault();
    }
});


//for ai module
$(document).on('click', 'a[data-ajax-popup-over="true"], button[data-ajax-popup-over="true"], div[data-ajax-popup-over="true"]', function () {
    var validate = $(this).attr('data-validate');
    var id = '';
    if (validate) {
        id = $(validate).val();
    }
    var title_over = $(this).data('title');
    $('#commonModalOver .modal-dialog').removeClass('modal-lg');
    var size_over = ($(this).data('size') == '') ? 'md' : $(this).data('size');

    var url = $(this).data('url');
    var fetchUrl = url;
    if (id) {
        fetchUrl += (fetchUrl.indexOf('?') >= 0 ? '&' : '?') + 'id=' + encodeURIComponent(id);
    }
    $("#commonModalOver .modal-title").html(title_over);
    $("#commonModalOver .modal-dialog").addClass('modal-' + size_over);
    $.ajax({
        url: fetchUrl,
        success: function (data) {
            $('#commonModalOver .modal-body').html(data);
            $("#commonModalOver").modal('show');
            taskCheckbox();
            if (window._csvImportPreview) {
                var preview = window._csvImportPreview;
                window._csvImportPreview = null;
                if (typeof SetDynamicData === 'function') {
                    SetDynamicData(preview.output, preview.fields, preview.header || []);
                }
            }
        },
        error: function (data) {
            data = data.responseJSON;
            show_toastr('Error', data.error, 'error')
        }
    });
});



//start input serach box
function JsSearchBox() {
    if ($(".js-searchBox").length)
    {
        $( ".js-searchBox" ).each(function( index ) {
            if($(this).parent().find('.formTextbox').length == 0)
            {
                $(this).searchBox({ elementWidth: '250'});
            }
        });
    }
}

$(document).ready(function() {
    JsSearchBox();

    function JsSearchBox() {
        if ($(".js-searchBox").length) {
            $(".js-searchBox").each(function(index) {
                if ($(this).parent().find('.formTextbox').length === 0) {
                    $(this).searchBox({ elementWidth: '250' });
                }
            });
        }
    }
});

//end input serach box

/**
 * Populate CSV import mapping modal (thead column selects + tbody preview rows).
 */
function SetDynamicData(outputHtml, fields, csvHeader) {
    var $scope = $('#commonModalOver');
    if (!$scope.length) {
        $scope = $('#commonModal');
    }

    var $table = $scope.find('.import-data-table');
    if (!$table.length) {
        $table = $scope.find('table.table-bordered').first();
    }

    var $thead = $table.find('thead');
    var $tbody = $table.find('tbody');
    if (!$thead.length || !$tbody.length) {
        return;
    }

    var fieldList = fields || [];
    if (typeof fieldList === 'string') {
        try {
            fieldList = JSON.parse(fieldList);
        } catch (e) {
            fieldList = [];
        }
    }
    if (!Array.isArray(fieldList)) {
        fieldList = Object.values(fieldList);
    }

    var $sample = $('<table><tbody>' + (outputHtml || '') + '</tbody></table>');
    var colCount = $sample.find('tr:first td').length;
    if (colCount < 1 && Array.isArray(csvHeader)) {
        colCount = csvHeader.length;
    }

    function fieldLabel(name) {
        return String(name).replace(/_/g, ' ').replace(/\b\w/g, function (c) {
            return c.toUpperCase();
        });
    }

    function guessField(headerCell) {
        var h = String(headerCell || '').toLowerCase().replace(/[^a-z0-9]+/g, ' ');
        h = h.trim();
        var map = {
            'employee email': 'employee_email',
            email: 'employee_email',
            date: 'date',
            'clock in': 'clock_in',
            'clock out': 'clock_out',
            'clockin': 'clock_in',
            'clockout': 'clock_out'
        };
        if (map[h]) {
            return map[h];
        }
        for (var i = 0; i < fieldList.length; i++) {
            if (fieldLabel(fieldList[i]).toLowerCase() === h) {
                return fieldList[i];
            }
        }
        return '';
    }

    var headRow = '<tr>';
    for (var col = 0; col < colCount; col++) {
        var selected = '';
        if (Array.isArray(csvHeader) && csvHeader[col] !== undefined) {
            selected = guessField(csvHeader[col]);
        }
        headRow += '<th><select class="form-control form-control-sm set_column_data" data-column_number="' + col + '">';
        headRow += '<option value="">Select field</option>';
        for (var f = 0; f < fieldList.length; f++) {
            var fname = fieldList[f];
            headRow += '<option value="' + fname + '"' + (selected === fname ? ' selected' : '') + '>' + fieldLabel(fname) + '</option>';
        }
        headRow += '</select></th>';
    }
    headRow += '</tr>';
    $thead.html(headRow);
    $tbody.html(outputHtml || '');

    initCsvImportModal($scope, fieldList);
}

window.SetDynamicData = SetDynamicData;

window._csvImportPreview = window._csvImportPreview || null;
window._csvImportColumnMap = window._csvImportColumnMap || {};

function initCsvImportModal($scope, fieldList) {
    if (!$scope || !$scope.length) {
        $scope = $('#commonModalOver');
    }

    var $btn = $scope.find('#csv_import_submit');
    if (!$btn.length) {
        return;
    }

    var requiredCount = parseInt($btn.attr('data-required-count') || '0', 10);
    if (!requiredCount && Array.isArray(fieldList)) {
        requiredCount = fieldList.length;
    }

    function refreshCsvImportState() {
        var column_data = {};
        $scope.find('.set_column_data').each(function () {
            var selected = $(this).val();
            var col_num = $(this).data('column_number');
            if (selected) {
                column_data[selected] = col_num;
            }
        });

        $scope.find('.set_column_data').each(function () {
            var $select = $(this);
            var col_num = $select.data('column_number');
            $select.find('option').each(function () {
                var option_value = $(this).val();
                if (option_value !== '' && option_value in column_data && column_data[option_value] !== col_num) {
                    $(this).prop('hidden', true);
                } else {
                    $(this).prop('hidden', false);
                }
            });
        });

        var mappedCount = Object.keys(column_data).length;
        var ready = false;
        if (Array.isArray(fieldList) && fieldList.length > 0) {
            ready = fieldList.every(function (fieldName) {
                return Object.prototype.hasOwnProperty.call(column_data, fieldName);
            });
        } else if (requiredCount > 0) {
            ready = mappedCount >= requiredCount;
        }
        $btn.prop('disabled', !ready);
        if (ready) {
            window._csvImportColumnMap = column_data;
        }
    }

    $scope.find('.set_column_data').off('change.csvimport').on('change.csvimport', refreshCsvImportState);
    refreshCsvImportState();
}

$(document).on('click', '#csv_import_submit', function (event) {
    event.preventDefault();
    var $btn = $(this);
    if ($btn.prop('disabled')) {
        return;
    }

    var url = $btn.data('url');
    $.ajax({
        url: url,
        method: 'POST',
        data: {
            data: window._csvImportColumnMap || {},
            _token: $('meta[name="csrf-token"]').attr('content')
        },
        beforeSend: function () {
            $btn.prop('disabled', true).text('Importing...');
        },
        success: function (response) {
            $btn.prop('disabled', false).text('Import');
            if ($('#upload_form').length) {
                $('#upload_form')[0].reset();
            }

            if (response.html === true) {
                $('#process_area').html(response.response);
                $btn.hide();
                $('.csv-import-cancel').hide();
                show_toastr('Error', 'These data are not inserted', 'error');
            } else {
                $('#commonModalOver').modal('hide');
                show_toastr('success', response.response, 'success');
                setTimeout(function () {
                    location.reload();
                }, 800);
            }
        },
        error: function () {
            $btn.prop('disabled', false).text('Import');
            show_toastr('Error', 'Import failed. Please try again.', 'error');
        }
    });
});

$(document).on('click', '.csv-import-cancel', function () {
    $('#commonModalOver').modal('hide');
});

