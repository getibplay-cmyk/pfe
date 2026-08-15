<?php

namespace App\Actions\Intelligence;

use App\Models\FleetReallocationProposal;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\FleetReallocation\FleetReallocationArtifactVerifier;
use App\Support\Intelligence\FleetReallocation\FleetReallocationContract;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DownloadFleetReallocationProposal
{
    public function __construct(
        private readonly FleetReallocationArtifactVerifier $verifier,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(FleetReallocationProposal $proposal): StreamedResponse
    {
        try {
            $content = $this->verifier->read($proposal);
        } catch (RuntimeException $exception) {
            abort(
                $exception->getMessage() === 'La proposition de réallocation privée est indisponible.' ? 410 : 409,
                $exception->getMessage(),
            );
        }

        $this->audit->record('prediction.fleet_reallocation.downloaded', $proposal, [], [
            'proposal_id' => $proposal->proposal_id,
            'integrity_verified' => true,
            'effect' => FleetReallocationContract::OPERATIONAL_EFFECT,
        ]);

        return response()->streamDownload(
            static function () use ($content): void {
                echo $content;
            },
            $proposal->original_name,
            [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Content-Type-Options' => 'nosniff',
                'X-RentFleet-Reallocation-Proposal' => $proposal->proposal_id,
            ],
        );
    }
}
