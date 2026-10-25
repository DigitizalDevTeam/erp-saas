<?php

namespace Tests\Unit;

use App\Jobs\SendLoggedEmailJob;
use App\Mail\TestMail;
use App\Support\LoggedMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class LoggedMailQueueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('created_by')->default(0);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('recipient');
            $table->string('subject')->nullable();
            $table->string('source')->nullable();
            $table->string('mailable_class')->nullable();
            $table->string('status');
            $table->text('error_message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    /** Dispatch path: LoggedMail::send queues instead of sending inline. */
    public function test_send_dispatches_job_with_recipients_and_context(): void
    {
        Queue::fake();

        LoggedMail::send('boss@example.com', new TestMail(), [
            'source' => 'test',
            'created_by' => 60,
        ]);

        Queue::assertPushed(SendLoggedEmailJob::class, function ($job) {
            return $job->recipients === 'boss@example.com'
                && $job->source === 'test'
                && $job->createdBy === 60
                && $job->mailable instanceof TestMail;
        });
    }

    /** Worker path: handle() sends and logs success. */
    public function test_job_handle_sends_and_logs_success(): void
    {
        Mail::fake();

        $job = new SendLoggedEmailJob(
            'a@b.com',
            new TestMail(),
            'a@b.com',
            'Hi',
            'test',
            60,
            null,
            ['mail_driver' => 'log'],
            ['mailable_class' => TestMail::class],
        );
        $job->handle();

        Mail::assertSent(TestMail::class);
        $this->assertDatabaseHas('email_logs', [
            'recipient' => 'a@b.com',
            'status' => 'success',
        ]);
    }

    /** Worker path: failed() logs the final failure after retries. */
    public function test_job_failed_logs_failure(): void
    {
        $job = new SendLoggedEmailJob(
            'a@b.com',
            new TestMail(),
            'a@b.com',
            'Hi',
            'test',
            60,
            null,
            null,
            ['mailable_class' => TestMail::class],
        );
        $job->failed(new \Exception('smtp down'));

        $this->assertDatabaseHas('email_logs', [
            'recipient' => 'a@b.com',
            'status' => 'failed',
            'error_message' => 'smtp down',
        ]);
    }

    /** Sync driver (tests/dev default): dispatch still runs inline. */
    public function test_send_runs_inline_on_sync_driver(): void
    {
        Mail::fake();

        LoggedMail::send('a@b.com', new TestMail(), [
            'source' => 'test',
            'created_by' => 60,
        ]);

        Mail::assertSent(TestMail::class);
        $this->assertDatabaseHas('email_logs', [
            'recipient' => 'a@b.com',
            'status' => 'success',
        ]);
    }
}
