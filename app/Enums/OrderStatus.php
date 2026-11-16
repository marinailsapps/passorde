<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Sent = 'sent';
    case Failed = 'failed';

    /** Statuses whose lines hold stock (see the article_reservations view). */
    public function reservesStock(): bool
    {
        return in_array($this, [self::Pending, self::Approved, self::Failed], true);
    }

    /**
     * What a sales person sees: a push failure is the admin's business, so
     * "failed" looks like "approved" (still on its way to Evoliz).
     */
    public function forAuthor(): self
    {
        return $this === self::Failed ? self::Approved : $this;
    }
}
