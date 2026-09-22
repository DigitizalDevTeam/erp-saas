<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PosEodReportMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $textBody,
        public string $pdfBinary,
        public string $pdfFileName
    ) {
    }

    public function build()
    {
        return $this->subject($this->subjectLine)
            ->html('<div style="white-space:pre-wrap;font-family:sans-serif">' . e($this->textBody) . '</div>')
            ->attachData($this->pdfBinary, $this->pdfFileName, [
                'mime' => 'application/pdf',
            ]);
    }
}
