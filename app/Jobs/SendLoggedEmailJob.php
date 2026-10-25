<?php

namespace App\Jobs;

use App\Services\EmailLogService;
use App\Support\MailConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Sends one logged email in the background.
 *
 * Every project email funnels through LoggedMail::send(), which dispatches
 * this job instead of talking to SMTP inside the web request — that is what
 * froze the UI. The job carries the company's SMTP settings with it and
 * applies them in the worker: mail config is process-global state, so
 * applying it at dispatch time would be lost by the time the worker runs.
 *
 * Outcome logging stays exactly as before, just moved into the worker:
 * handle() logs success, failed() logs the final failure after retries.
 * With QUEUE_CONNECTION=sync (local/dev default) the job runs inline, so
 * behaviour there is unchanged.
 */
class SendLoggedEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** SMTP blips retry instead of losing the email. */
    public $tries = 3;

    public $backoff = 60;

    public $timeout = 180;

    public function __construct(
        public $to,
        public Mailable $mailable,
        public string $recipients,
        public ?string $subject,
        public string $source,
        public int $createdBy,
        public ?int $userId,
        public ?array $mailSettings,
        public array $meta,
    ) {
    }

    public function handle(): void
    {
        MailConfig::apply($this->createdBy > 0 ? $this->createdBy : null, $this->mailSettings);

        Mail::to($this->to)->send($this->mailable);

        EmailLogService::logSuccess(
            $this->recipients,
            $this->subject,
            $this->source,
            $this->createdBy,
            $this->userId,
            $this->meta
        );
    }

    public function failed(\Throwable $e): void
    {
        EmailLogService::logFailure(
            $this->recipients,
            $this->subject,
            $this->source,
            $e->getMessage(),
            $this->createdBy,
            $this->userId,
            $this->meta
        );
    }
}
