<?php

namespace App\Modules\Suppliers\Console;

use App\Modules\Suppliers\Application\Connectors\Cj\CjDropshippingConnector;
use App\Modules\Suppliers\Domain\Connector\PurchaseLine;
use App\Modules\Suppliers\Infrastructure\Models\Supplier;
use Illuminate\Console\Command;
use Throwable;

/**
 * Creates (or updates) the CJdropshipping supplier record and checks the API key.
 * The supplier stays inactive until an authorization record is approved in admin.
 */
final class CjSetupCommand extends Command
{
    protected $signature = 'suppliers:cj-setup
        {--code=cj : Supplier code}
        {--check-vid= : Optional CJ variant ID to test shipping quotes to AE and SA}';

    protected $description = 'Register the CJdropshipping supplier and verify the API key';

    public function handle(): int
    {
        $supplier = Supplier::query()->withTrashed()->firstOrNew(['code' => (string) $this->option('code')]);
        $supplier->fill([
            'name' => $supplier->name ?: 'CJdropshipping',
            'connector_type' => 'cj',
            'base_currency' => 'USD',
            'credentials_ref' => $supplier->credentials_ref ?: 'config:gulfcart.cj.api_key',
            'direct_shipping_enabled' => true,
            'metadata' => (array) ($supplier->metadata ?? []) + ['cj' => ['from_country' => config('gulfcart.cj.from_country', 'CN')]],
        ]);
        if (! $supplier->exists) {
            $supplier->is_active = false;
            $supplier->commercial_authorization_status = 'pending';
        }
        $supplier->save();
        $supplier->restore();
        $this->info("Supplier {$supplier->code} ({$supplier->id}) is ready, status: ".($supplier->is_active ? 'active' : 'inactive, approve its authorization in admin'));

        try {
            $connector = new CjDropshippingConnector($supplier);
            $connector->client()->ping();
            $this->info('CJ API key OK.');
        } catch (Throwable $e) {
            $this->error('CJ API check failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($vid = $this->option('check-vid')) {
            foreach (['AE', 'SA'] as $country) {
                try {
                    $option = $connector->freight([new PurchaseLine('', (string) $vid, 1, '0', 'USD')], $country);
                    $this->line("  {$country}: {$option['name']} {$option['price']} USD".($option['aging'] ? " ({$option['aging']} days)" : ''));
                } catch (Throwable $e) {
                    $this->warn("  {$country}: {$e->getMessage()}");
                }
            }
        }

        return self::SUCCESS;
    }
}
