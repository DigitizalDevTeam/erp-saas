<script>
    (function () {
        function syncAttendanceStatusFields($root) {
            var isAbsent = $root.find('input.attendance-status-radio:checked').val() === 'absent';
            var $clockWrap = $root.find('.attendance-clock-fields');
            var $inputs = $root.find('.attendance-clock-input');
            if (isAbsent) {
                $clockWrap.addClass('d-none');
                $inputs.prop('required', false).val('');
            } else {
                $clockWrap.removeClass('d-none');
                $inputs.prop('required', true);
            }
        }

        $(document).on('change', 'input.attendance-status-radio', function () {
            syncAttendanceStatusFields($(this).closest('form'));
        });

        $('#attendance_create_form, #attendance_edit_form').each(function () {
            syncAttendanceStatusFields($(this));
        });
    })();
</script>
