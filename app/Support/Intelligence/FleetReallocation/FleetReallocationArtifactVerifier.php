<?php

namespace App\Support\Intelligence\FleetReallocation;

use App\Models\FleetReallocationProposal;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class FleetReallocationArtifactVerifier
{
    public function read(FleetReallocationProposal $proposal): string
    {
        $disk = Storage::disk((string) config('intelligence.fleet_reallocation.disk'));

        try {
            $content = $disk->exists($proposal->stored_path)
                ? $disk->get($proposal->stored_path)
                : null;
        } catch (Throwable) {
            throw new RuntimeException('La proposition de réallocation privée est indisponible.');
        }

        if (! is_string($content)) {
            throw new RuntimeException('La proposition de réallocation privée est indisponible.');
        }
        if (strlen($content) !== $proposal->byte_size
            || ! hash_equals($proposal->content_sha256, hash('sha256', $content))) {
            throw new RuntimeException('Le contrôle d’intégrité de la proposition de réallocation a échoué.');
        }

        return $content;
    }

    public function valid(FleetReallocationProposal $proposal): bool
    {
        try {
            $this->read($proposal);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }
}
