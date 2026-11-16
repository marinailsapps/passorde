<?php

namespace Database\Seeders;

use App\Models\Paytype;
use App\Support\SyncMode;
use Illuminate\Database\Seeder;

/**
 * The 13 Evoliz paytypes recorded in the Evoliz connector notes in Passorde Connect. Evoliz has no active flag;
 * the ones enabled at first come from the profile (evoliz.enabled_paytypes). Existing rows keep the
 * admin's is_enabled choice. In file mode the same labels, with no Evoliz id.
 */
class PaytypeSeeder extends Seeder
{
    /** @var array<int, string> */
    public const PAYTYPES = [
        1 => 'PayPal',
        2 => 'Virement',
        3 => 'Carte bancaire',
        4 => 'Chèque',
        5 => 'Espèces',
        6 => 'Autres',
        7 => 'Chèque Emploi Service Universel (CESU)',
        8 => 'Prélèvement',
        9 => 'Lettre de change',
        10 => 'Traite',
        11 => 'Chèque vacances',
        12 => 'Contre-remboursement',
        13 => 'Avoir',
    ];

    public function run(): void
    {
        // The profile's evoliz.enabled_paytypes (config/passorde.php).
        $enabled = array_map(intval(...), (array) config('passorde.evoliz.enabled_paytypes'));

        foreach (self::PAYTYPES as $id => $label) {
            Paytype::query()->firstOrCreate(
                SyncMode::isFile() ? ['label' => $label] : ['evoliz_paytype_id' => $id],
                ['label' => $label, 'is_enabled' => in_array($id, $enabled, true)],
            );
        }
    }
}
