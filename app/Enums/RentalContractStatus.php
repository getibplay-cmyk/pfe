<?php

namespace App\Enums;

enum RentalContractStatus: string
{
    case Draft = 'draft';
    case Ready = 'ready';
    case Accepted = 'accepted';
    case Active = 'active';
    case ReturnPending = 'return_pending';
    case Returned = 'returned';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon', self::Ready => 'Prêt', self::Accepted => 'Accepté', self::Active => 'Actif', self::ReturnPending => 'Retour à traiter', self::Returned => 'Retourné', self::Closed => 'Clôturé', self::Cancelled => 'Annulé'
        };
    }
}
