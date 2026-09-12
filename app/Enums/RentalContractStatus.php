<?php

namespace App\Enums;

use App\Support\Ui\UiText;

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
        return UiText::t(match ($this) {
            self::Draft => 'Brouillon', self::Ready => UiText::t('Prêt'), self::Accepted => UiText::t('Accepté'), self::Active => 'Actif', self::ReturnPending => UiText::t('Retour à traiter'), self::Returned => UiText::t('Retourné'), self::Closed => UiText::t('Clôturé'), self::Cancelled => UiText::t('Annulé')
        });
    }
}
