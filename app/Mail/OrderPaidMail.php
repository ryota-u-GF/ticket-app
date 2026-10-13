<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class OrderPaidMail extends Mailable
{
    /**
     * Create a new message instance.
     */
    public function __construct(public Order $order)
    {
        $this->order->loadMissing(['event', 'items.ticketType']);
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "【まちチケ】チケットの購入が完了しました（{$this->order->event->title}）",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'mail.orders.paid',
            with: ['orderUrl' => route('orders.show', $this->order)],
        );
    }
}
