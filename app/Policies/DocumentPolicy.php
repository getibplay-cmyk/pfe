<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class DocumentPolicy
{
    public function view(User $user, Document $document): bool
    {
        return $this->sameScope($user, $document) && $user->hasPermission('document.view');
    }

    public function upload(User $user, ?Document $document = null): bool
    {
        return $user->hasPermission('document.upload') && (! $document || $this->sameScope($user, $document));
    }

    public function download(User $user, Document $document): bool
    {
        return $this->sameScope($user, $document) && $user->hasPermission('document.download');
    }

    public function delete(User $user, Document $document): bool
    {
        return $this->sameScope($user, $document) && $user->hasPermission('document.delete');
    }

    private function sameScope(User $user, Document $document): bool
    {
        return $user->tenant_id === $document->tenant_id && (! $user->isAgencyManager() || $user->agency_id === $document->agency_id);
    }
}
