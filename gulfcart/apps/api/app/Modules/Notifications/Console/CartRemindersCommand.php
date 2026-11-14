<?php

namespace App\Modules\Notifications\Console;

use App\Modules\Cart\Contracts\AbandonedCarts;
use App\Modules\Notifications\Application\Notifier;
use Illuminate\Console\Command;
use Throwable;

/** One friendly reminder for carts left 3–48 hours ago (signed-in customers only). */
final class CartRemindersCommand extends Command
{
    protected $signature = 'notifications:cart-reminders {--min-hours=3} {--max-hours=48}';

    protected $description = 'Remind customers about items left in their cart';

    public function handle(AbandonedCarts $carts, Notifier $notifier): int
    {
        if (! config('gulfcart.notifications.cart_reminders', true)) {
            return self::SUCCESS;
        }
        $sent = 0;
        foreach ($carts->due((int) $this->option('min-hours'), (int) $this->option('max-hours')) as $cart) {
            try {
                $notifier->toCustomer($cart['user_id'], 'cart_reminder', ['count' => (string) $cart['items']], 'cart_reminder:'.$cart['cart_id'], 'cart', $cart['cart_id'], '/cart');
                $carts->markReminded($cart['cart_id']);
                $sent++;
            } catch (Throwable $e) {
                report($e);
            }
        }
        $this->info("{$sent} cart reminder(s)");

        return self::SUCCESS;
    }
}
