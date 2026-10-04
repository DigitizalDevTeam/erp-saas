<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CommonEmailTemplate extends Mailable
{
    use Queueable, SerializesModels;

    public $template;
    public $settings;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($template, $settings)
    {

        $this->template = $template;
        $this->settings = $settings;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $mail = $this->markdown('email.common_email_template')->subject($this->template->subject)->with('content', $this->template->content);

        // Only set From when we actually have an address; an empty From
        // makes the mailer throw before SMTP is even reached. When it is
        // empty, Laravel falls back to the configured mail.from address
        // (set from /systems by MailConfig).
        $fromAddress = $this->settings['mail_from_address'] ?? '';
        if (is_string($fromAddress) && trim($fromAddress) !== '') {
            $mail->from(trim($fromAddress), $this->template->from);
        }

        return $mail;
    }
}
