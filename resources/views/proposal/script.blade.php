<script src="{{ asset('js/jquery.min.js') }} "></script>
<script type="text/javascript" src="{{ asset('js/html2pdf.bundle.min.js') }}"></script>

<script>
    function closeScript() {
        setTimeout(function () {
            window.open(window.location, '_self').close();
        }, 1000);
    }

    $(window).on('load', function () {
        var element = document.getElementById('boxes');
        var paperFormat = @json(strtoupper((string) ((isset($paperSize) ? $paperSize : ($settings['proposal_layout_size'] ?? 'a4')) === 'a5' ? 'a5' : 'a4')));
        var opt = {
            margin: [0.5, 0, 0.5, 0],
            filename: '{{Utility::customerProposalNumberFormat($proposal->proposal_id)}}',
            image: {type: 'jpeg', quality: 1},
            html2canvas: {scale: 4, dpi: 72, letterRendering: true},
            jsPDF: {unit: 'in', format: paperFormat}
        };
        html2pdf().set(opt).from(element).save().then(closeScript);
    });
</script>
