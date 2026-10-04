{{-- Global Printer Settings (header). Only for users with the "manage printers" permission. --}}
@php
    $printerBranches = collect();
    $printerDefaultBranchId = null;
    if (isset($posPrinterBranches) && $posPrinterBranches instanceof \Illuminate\Support\Collection && $posPrinterBranches->isNotEmpty()) {
        $printerBranches = $posPrinterBranches;
        $printerDefaultBranchId = optional($printerBranches->first())->id;
    } elseif (\Auth::check()) {
        $selectionIds = \Auth::user()->branchesForSelection()->pluck('id')->all();
        $printerBranches = \App\Models\Branch::where('created_by', \Auth::user()->creatorId())
            ->where('is_active', true)
            ->where('pos_type', \App\Models\Branch::POS_TYPE_RESTAURANT)
            ->whereIn('id', $selectionIds ?: [0])
            ->orderBy('name')
            ->get();
        $activeBranchId = \Auth::user()->activeBranchContextId();
        $printerDefaultBranchId = $printerBranches->contains('id', $activeBranchId)
            ? $activeBranchId
            : optional($printerBranches->first())->id;
    }
@endphp

<div class="modal fade" id="printerSettingsModal" tabindex="-1" aria-labelledby="printerSettingsModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="printerSettingsModalLabel">{{ __('Printer Settings') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex align-items-center justify-content-between mb-3 p-2 border rounded bg-light gap-2 flex-wrap">
                    <span class="fw-semibold">{{ __('Printer Agent Status') }}</span>
                    <div class="d-flex align-items-center gap-2">
                        <span id="printer-settings-agent-status-badge" class="badge bg-secondary">{{ __('Offline') }}</span>
                        <a href="{{ asset('downloads/print-agent/PosPrinterAgent.zip') }}"
                           class="btn btn-sm btn-outline-success"
                           download="PosPrinterAgent.zip">
                            <i class="ti ti-download me-1"></i>{{ __('pos.download_print_agent') }}
                        </a>
                    </div>
                </div>
                <p class="text-muted small mb-3">
                    {{ __('pos.download_print_agent_help') }}
                </p>
                <div class="mb-3">
                    <label class="form-label" for="printer-settings-branch">{{ __('Branch') }}</label>
                    <select id="printer-settings-branch" class="form-select">
                        @foreach ($printerBranches as $printerBranch)
                            <option value="{{ $printerBranch->id }}" @selected((int) $printerDefaultBranchId === (int) $printerBranch->id)>{{ $printerBranch->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="printer-settings-kitchen">{{ __('Kitchen Printer') }}</label>
                    <select id="printer-settings-kitchen" class="form-select">
                        <option value="">{{ __('Select printer') }}</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="printer-settings-bar">{{ __('Bar Printer') }}</label>
                    <select id="printer-settings-bar" class="form-select">
                        <option value="">{{ __('Select printer') }}</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="printer-settings-default">{{ __('Default Printer for Other Prints') }}</label>
                    <select id="printer-settings-default" class="form-select">
                        <option value="">{{ __('Select printer') }}</option>
                    </select>
                    <small class="text-muted">{{ __('Used for bills, invoices, receipts, and other non-kitchen/bar prints via the Print Agent.') }}</small>
                </div>
                <div class="mb-3 form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="printer-settings-use-agent" value="1">
                    <label class="form-check-label" for="printer-settings-use-agent">{{ __('pos.use_print_agent_for_invoice') }}</label>
                    <div class="form-text">{{ __('pos.use_print_agent_for_invoice_help') }}</div>
                </div>
                <div class="mb-3 form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="printer-settings-save-image" value="1">
                    <label class="form-check-label" for="printer-settings-save-image">{{ __('pos.save_image_when_offline') }}</label>
                    <div class="form-text">{{ __('pos.save_image_when_offline_help') }}</div>
                </div>
                <div id="printer-settings-empty" class="alert alert-warning d-none mb-0">
                    {{ __('Install and start the printer agent to sync printers. Until then, browser printing remains available as a fallback.') }}
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                <button type="button" class="btn btn-primary" id="printer-settings-save-btn">
                    <i class="ti ti-device-floppy me-1"></i>{{ __('Save') }}
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    (function () {
        var printerSettingsStatusTimer = null;

        function fillPrinterSelect($select, printers, selectedId) {
            var placeholder = '{{ __("Select printer") }}';
            $select.empty().append($('<option></option>').val('').text(placeholder));
            (printers || []).forEach(function (p) {
                var label = p.display_name || p.system_name || ('#' + p.id);
                var $opt = $('<option></option>').val(p.id).text(label);
                if (selectedId && parseInt(selectedId, 10) === parseInt(p.id, 10)) {
                    $opt.prop('selected', true);
                }
                $select.append($opt);
            });
        }

        function setAgentBadge(agent) {
            var $badge = $('#printer-settings-agent-status-badge');
            if (agent && agent.online) {
                $badge.removeClass('bg-secondary bg-danger').addClass('bg-success').text('{{ __("Online") }}');
            } else {
                $badge.removeClass('bg-success bg-danger').addClass('bg-secondary').text('{{ __("Offline") }}');
            }
        }

        function currentBranchId() {
            return $('#printer-settings-branch').val() || '';
        }

        function printerSettingsCsrf() {
            var meta = document.querySelector('meta[name="csrf-token"]');
            return meta ? (meta.getAttribute('content') || '') : '';
        }

        function printerSettingsToast(title, message, type) {
            if (typeof show_toastr === 'function') {
                try {
                    show_toastr(title, message, type);
                    return;
                } catch (e) { /* fall through to alert */ }
            }
            alert(message);
        }

        function printerSettingsErrorMessage(xhr) {
            if (xhr && xhr.status === 419) {
                return '{{ __("Session expired. Please refresh the page and try again.") }}';
            }
            return (xhr && xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : '{{ __("Error") }}';
        }

        function refreshAgentStatus() {
            var branchId = currentBranchId();
            if (!branchId) {
                setAgentBadge({ online: false });
                return;
            }
            $.ajax({
                url: '{{ route("pos.restaurant.agent-status") }}',
                type: 'GET',
                data: { branch_id: branchId },
                success: function (res) {
                    setAgentBadge((res && res.agent) ? res.agent : { online: false });
                },
                error: function () {
                    setAgentBadge({ online: false });
                }
            });
        }

        function startStatusPolling() {
            stopStatusPolling();
            refreshAgentStatus();
            printerSettingsStatusTimer = setInterval(refreshAgentStatus, 2000);
        }

        function stopStatusPolling() {
            if (printerSettingsStatusTimer) {
                clearInterval(printerSettingsStatusTimer);
                printerSettingsStatusTimer = null;
            }
        }

        function loadPrinterSettings() {
            var branchId = currentBranchId();
            if (!branchId) {
                $('#printer-settings-empty').removeClass('d-none');
                return;
            }
            setAgentBadge({ online: false });
            $.ajax({
                url: '{{ route("pos.restaurant.agent-printers") }}',
                type: 'GET',
                data: { branch_id: branchId },
                success: function (res) {
                    var printers = (res && res.printers) ? res.printers : [];
                    var config = (res && res.config) ? res.config : {};
                    fillPrinterSelect($('#printer-settings-kitchen'), printers, config.kitchen_printer_id || null);
                    fillPrinterSelect($('#printer-settings-bar'), printers, config.bar_printer_id || null);
                    fillPrinterSelect($('#printer-settings-default'), printers, config.default_printer_id || null);
                    $('#printer-settings-use-agent').prop('checked', !!config.use_print_agent_for_invoice);
                    $('#printer-settings-save-image').prop('checked', !!config.save_image_when_offline);
                    $('#printer-settings-empty').toggleClass('d-none', printers.length > 0);
                    setAgentBadge((res && res.agent) ? res.agent : { online: false });
                    startStatusPolling();
                },
                error: function (xhr) {
                    printerSettingsToast('{{ __("Error") }}', printerSettingsErrorMessage(xhr), 'error');
                }
            });
        }

        $(document).on('click', '#global-printer-settings-btn', function () {
            // On the standalone restaurant POS, open settings for the branch
            // currently selected in POS instead of the modal default.
            var posBranchId = $('#pos-restaurant-branch').val();
            if (posBranchId && $('#printer-settings-branch option[value="' + posBranchId + '"]').length) {
                $('#printer-settings-branch').val(posBranchId);
            }
            loadPrinterSettings();
            var modalEl = document.getElementById('printerSettingsModal');
            if (modalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            } else {
                $('#printerSettingsModal').modal('show');
            }
        });

        $(document).on('change', '#printer-settings-branch', function () {
            loadPrinterSettings();
        });

        $(document).on('hidden.bs.modal', '#printerSettingsModal', function () {
            stopStatusPolling();
        });

        $(document).on('click', '#printer-settings-save-btn', function () {
            var branchId = currentBranchId();
            if (!branchId) {
                printerSettingsToast('{{ __("Error") }}', '{{ __("Please select a branch.") }}', 'error');
                return;
            }
            var $btn = $(this);
            $btn.prop('disabled', true);
            $.ajax({
                url: '{{ route("pos.restaurant.print-config") }}',
                type: 'POST',
                headers: {
                    'X-CSRF-TOKEN': printerSettingsCsrf()
                },
                data: {
                    branch_id: branchId,
                    kitchen_printer_id: $('#printer-settings-kitchen').val() || '',
                    bar_printer_id: $('#printer-settings-bar').val() || '',
                    default_printer_id: $('#printer-settings-default').val() || '',
                    use_print_agent_for_invoice: $('#printer-settings-use-agent').is(':checked') ? 1 : 0,
                    save_image_when_offline: $('#printer-settings-save-image').is(':checked') ? 1 : 0
                },
                success: function (res) {
                    printerSettingsToast('{{ __("Success") }}', (res && res.message) ? res.message : '{{ __("Printer configuration saved.") }}', 'success');
                    var modalEl = document.getElementById('printerSettingsModal');
                    if (modalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                        bootstrap.Modal.getOrCreateInstance(modalEl).hide();
                    } else {
                        $('#printerSettingsModal').modal('hide');
                    }
                },
                error: function (xhr) {
                    printerSettingsToast('{{ __("Error") }}', printerSettingsErrorMessage(xhr), 'error');
                },
                complete: function () {
                    $btn.prop('disabled', false);
                }
            });
        });
    })();
</script>
