<?php

namespace App\Support\Intelligence\Training;

use App\Support\Intelligence\IntelligencePseudonymizer;
use App\Support\Intelligence\VehicleColor\VehicleColorContract;
use App\Support\Intelligence\VehiclePlate\VehiclePlateHybridContract;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class TrainingDatasetSchema
{
    public static function fail(string $message): never
    {
        throw ValidationException::withMessages(['dataset' => $message]);
    }

    public function decode(string $contents): array
    {
        if (strlen($contents) > 5 * 1024 * 1024) {
            self::fail('Le fichier dépasse 5 Mo.');
        }
        try {
            $value = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            self::fail('Le fichier JSON est invalide.');
        }
        if (! is_array($value)) {
            self::fail('Un objet JSON est requis.');
        }

        return $value;
    }

    public function normalize(array $input, int $tenantId): array
    {
        $family = $input['family'] ?? '';
        TrainingCatalog::get(is_string($family) ? $family : '');
        $fields = match ($family) {
            'demand' => 'date,value',
            'anomaly' => 'date,late_hours,km_per_day,fuel_drop_pct,label',
            'color', 'plate' => 'image_sha256,label',
            'damage' => 'image_sha256,boxes',
        };
        $rules = [
            'data' => ['required', 'array:schema_version,family,rows'],
            'data.schema_version' => ['required', 'in:1.0'],
            'data.family' => ['required', Rule::in(array_keys(TrainingCatalog::all()))],
            'data.rows' => ['required', 'array', 'list', 'min:1', 'max:20000'],
            'data.rows.*' => ['required', 'array:key,group,'.$fields],
            'data.rows.*.key' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,80}$/D', 'distinct:strict'],
            'data.rows.*.group' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,80}$/D'],
        ];
        if (in_array($family, ['demand', 'anomaly'], true)) {
            $rules['data.rows.*.date'] = ['required', 'date_format:Y-m-d', 'before:today'];
        }
        if ($family === 'demand') {
            $rules['data.rows.*.value'] = ['required', 'integer', 'min:0', 'max:100000'];
        } elseif ($family === 'anomaly') {
            foreach (['late_hours' => 87600, 'km_per_day' => 10000, 'fuel_drop_pct' => 100] as $field => $max) {
                $rules['data.rows.*.'.$field] = ['required', 'numeric', 'min:0', 'max:'.$max];
            }
            $rules['data.rows.*.label'] = ['required', 'integer', 'in:0,1'];
        } else {
            $rules['data.rows.*.image_sha256'] = ['required', 'string', 'regex:/^[a-f0-9]{64}$/D', 'distinct:strict'];
            if ($family === 'damage') {
                $rules += self::boxRules('data.rows.*.boxes');
            } else {
                $rules['data.rows.*.label'] = ['required', 'string', $family === 'color' ? Rule::in(VehicleColorContract::CLASSES) : 'max:30'];
            }
        }
        Validator::make(['data' => $input], $rules)->validate();
        $tenantKey = app(IntelligencePseudonymizer::class)->tenantKey($tenantId);
        $rows = [];
        foreach ($input['rows'] as $row) {
            if ($family === 'demand') {
                $row['value'] = (int) $row['value'];
            } elseif ($family === 'anomaly') {
                $row['label'] = (int) $row['label'];
                foreach (['late_hours', 'km_per_day', 'fuel_drop_pct'] as $feature) {
                    $row[$feature] = (float) $row[$feature];
                }
            }
            if ($family === 'plate' && ! VehiclePlateHybridContract::isCanonical($row['label'])) {
                self::fail('Chaque plaque doit être une transcription canonique vérifiée.');
            }
            if ($family === 'damage') {
                self::checkBoxes($row['boxes']);
                $row['boxes'] = array_map(fn ($box) => array_map(fn ($value) => (float) $value, $box), $row['boxes']);
            }
            $row['group'] = hash('sha256', $tenantKey.'|training-group|'.$row['group']);
            $row['key'] = hash('sha256', $tenantKey.'|training-row|'.$row['key']);
            $row['provider'] = $tenantKey;
            $rows[] = $row;
        }

        return ['schema_version' => '1.0', 'family' => $family, 'rows' => $rows];
    }

    public static function boxRules(string $prefix): array
    {
        return [
            $prefix => ['present', 'array', 'list', 'max:100'],
            $prefix.'.*' => ['required', 'array:x,y,w,h'],
            $prefix.'.*.x' => ['required', 'numeric', 'min:0', 'max:1'],
            $prefix.'.*.y' => ['required', 'numeric', 'min:0', 'max:1'],
            $prefix.'.*.w' => ['required', 'numeric', 'gt:0', 'max:1'],
            $prefix.'.*.h' => ['required', 'numeric', 'gt:0', 'max:1'],
        ];
    }

    public static function checkBoxes(array $boxes): void
    {
        foreach ($boxes as $box) {
            if ($box['x'] + $box['w'] > 1.000001 || $box['y'] + $box['h'] > 1.000001) {
                self::fail('Une annotation dépasse les limites de l’image.');
            }
        }
    }

    public function partition(array $datasets, string $family): array
    {
        $rows = [];
        foreach ($datasets as $data) {
            if ($data['family'] !== $family) {
                self::fail('Les contributions doivent concerner le même modèle.');
            }
            foreach ($data['rows'] as $row) {
                $identity = $row['image_sha256'] ?? ($family === 'demand' ? $row['group'].'|'.$row['date'] : $row['key']);
                if (isset($rows[$identity])) {
                    $previous = $rows[$identity];
                    unset($previous['key']);
                    $compare = $row;
                    unset($compare['key']);
                    if ($previous !== $compare) {
                        self::fail('Des observations identiques ont des groupes ou annotations contradictoires.');
                    }

                    continue;
                }
                $rows[$identity] = $row;
            }
        }
        if (count($rows) < 60 || count($rows) > 20000) {
            self::fail('Une campagne exige entre 60 et 20 000 observations distinctes.');
        }
        if ($family === 'damage' && count($rows) > 5000) {
            self::fail('Limitez une campagne de dommages à 5 000 images pour conserver un rapport de comparaison borné.');
        }
        if (collect($rows)->pluck('key')->unique()->count() !== count($rows)) {
            self::fail('Une clé d’observation désigne plusieurs données. Utilisez des clés stables et distinctes dans chaque source.');
        }
        if ($family === 'demand') {
            foreach (collect($rows)->groupBy('group') as $series) {
                $dates = $series->pluck('date')->sort()->values();
                if ($dates->count() < 120 || CarbonImmutable::parse($dates->first())->diffInDays(CarbonImmutable::parse($dates->last())) + 1 !== (float) $dates->count()) {
                    self::fail('Chaque série de demande exige au moins 120 jours consécutifs, sans date manquante.');
                }
            }
            $dates = collect($rows)->pluck('date')->unique()->sort()->values();
            $validationStart = $dates[(int) floor($dates->count() * .6)];
            $testStart = $dates[(int) floor($dates->count() * .8)];
        }
        $counts = ['train' => 0, 'validation' => 0, 'test' => 0];
        foreach ($rows as &$row) {
            // Stable per-group partition across campaigns; changing the candidate cannot reshuffle the test set.
            $bucket = hexdec(substr($row['group'], 0, 4)) % 10;
            $row['split'] = $family === 'demand'
                ? ($row['date'] < $validationStart ? 'train' : ($row['date'] < $testStart ? 'validation' : 'test'))
                : ($bucket < 6 ? 'train' : ($bucket < 8 ? 'validation' : 'test'));
            $counts[$row['split']]++;
        }
        unset($row);
        if (min($counts) < 10) {
            self::fail('Il faut au moins 10 observations dans chaque partition. Ajoutez des groupes indépendants.');
        }
        if ($family === 'demand') {
            foreach (collect($rows)->groupBy('group') as $series) {
                $bySplit = $series->countBy('split');
                if (($bySplit['train'] ?? 0) < 60 || ($bySplit['validation'] ?? 0) < 10 || ($bySplit['test'] ?? 0) < 10) {
                    self::fail('Les agences doivent couvrir des périodes compatibles : au moins 60 jours d’apprentissage, 10 de validation et 10 de test par série.');
                }
            }
        }

        return ['rows' => array_values($rows), 'counts' => $counts];
    }
}
