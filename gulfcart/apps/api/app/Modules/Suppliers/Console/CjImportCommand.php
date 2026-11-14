<?php

namespace App\Modules\Suppliers\Console;

use App\Modules\Suppliers\Application\Connectors\Cj\CjProductImporter;
use App\Modules\Suppliers\Infrastructure\Models\Supplier;
use Illuminate\Console\Command;

final class CjImportCommand extends Command
{
    protected $signature = 'suppliers:cj-import
        {pids* : CJ product IDs (pid), from the product page or the CJ API}
        {--supplier=cj : Supplier code}
        {--countries=AE,SA : Countries the products may be sold in}
        {--category= : Category slug for the new drafts}';

    protected $description = 'Import CJdropshipping products into the catalog as drafts';

    public function handle(CjProductImporter $importer): int
    {
        $supplier = Supplier::query()->where('code', (string) $this->option('supplier'))->where('connector_type', 'cj')->first();
        if ($supplier === null) {
            $this->error('No CJ supplier with that code. Run suppliers:cj-setup first.');

            return self::FAILURE;
        }
        $countries = array_values(array_intersect(
            array_map('trim', explode(',', strtoupper((string) $this->option('countries')))),
            (array) config('gulfcart.countries'),
        ));
        if ($countries === []) {
            $this->error('No supported country given.');

            return self::FAILURE;
        }

        $result = $importer->import($supplier, array_map('strval', (array) $this->argument('pids')), $countries, $this->option('category') ?: null);
        $import = $result['import'];

        $this->info("Created {$import->created_count}, updated {$import->updated_count}, row errors {$import->error_count}.");
        foreach ($result['skipped'] as $pid => $reason) {
            $this->warn("Skipped {$pid}: {$reason}");
        }
        foreach ((array) $import->errors as $error) {
            $this->warn("Row {$error['row']}: ".implode('; ', (array) $error['errors']));
        }
        $this->line('Products are drafts: add Arabic titles, check the category and image rights, then publish from /admin/products.');

        return $import->status === 'completed' && $result['skipped'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
