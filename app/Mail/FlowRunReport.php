<?php
// app/Mail/FlowRunReport.php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class FlowRunReport extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $reportContent,
        public readonly string $flowName,
        public readonly int $flowRunId,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[FlowAI] ' . $this->flowName . ' — ' . now()->format('d.m.Y'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.flow-run-report',
        );
    }
}
