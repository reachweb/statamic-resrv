<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;

return new class extends Migration
{
    /**
     * How each booking's money was settled (Reach\StatamicResrv\Enums\PaymentSettlement), in place
     * of payment_unresolved. Every existing row keeps the amount received it reported before:
     *
     * - confirmed: gateway with a payment_id, out_of_band without one (none when nothing was owed),
     *   and out_of_band when an admin marked it as paid and its online payment was kept
     *   (payment_unresolved).
     * - refunded: gateway with a payment_id (out_of_band when payment_unresolved), out_of_band for
     *   a manual booking without one, otherwise none.
     * - cancelled: gateway with a payment_id, unresolved when payment_unresolved, otherwise none.
     * - Before v6.6.0 the checkout confirmed a booking paid through an offline gateway at once,
     *   with a made-up payment_id: those confirmed, refunded or cancelled rows are unresolved, as
     *   nobody recorded whether the money arrived, and lose the made-up payment_id. They are found
     *   by the offline_ prefix of that id, or by a configured gateway that confirms manually.
     * - Everything else: none.
     */
    public function up(): void
    {
        Schema::table('resrv_reservations', function (Blueprint $table) {
            $table->string('payment_settlement', 20)->default('none');
        });

        $withPaymentId = fn (Builder $query) => $query->whereNotNull('payment_id')->where('payment_id', '!=', '');
        $withoutPaymentId = fn (Builder $query) => $query->where(fn (Builder $query) => $query->whereNull('payment_id')->orWhere('payment_id', ''));
        $rows = fn (array $statuses) => DB::table('resrv_reservations')->whereIn('status', $statuses);

        $rows(['confirmed', 'refunded', 'cancelled'])->where($withPaymentId)->where('payment_unresolved', false)
            ->update(['payment_settlement' => 'gateway']);
        $rows(['confirmed', 'refunded'])->where($withPaymentId)->where('payment_unresolved', true)
            ->update(['payment_settlement' => 'out_of_band']);
        $rows(['cancelled'])->where($withPaymentId)->where('payment_unresolved', true)
            ->update(['payment_settlement' => 'unresolved']);
        $rows(['confirmed'])->where($withoutPaymentId)
            ->where(fn (Builder $query) => $query->where('payment', '!=', 0)->orWhere('payment_surcharge', '!=', 0))
            ->update(['payment_settlement' => 'out_of_band']);
        $rows(['refunded'])->where($withoutPaymentId)->whereNotNull('created_by')
            ->update(['payment_settlement' => 'out_of_band']);

        $offlineCheckout = fn () => $rows(['confirmed', 'refunded', 'cancelled'])->where($withPaymentId)->whereNull('created_by');

        // OfflinePaymentGateway always minted offline_ ids, so its rows are found even when the site
        // has since removed or renamed the gateway. '!' escapes the '_' LIKE wildcard on every driver.
        $offlineCheckout()->whereRaw("payment_id like ? escape '!'", ['offline!_%'])
            ->update(['payment_settlement' => 'unresolved', 'payment_id' => '']);

        // A third-party gateway that confirms manually is only known by the config.
        foreach ($offlineCheckout()->distinct()->pluck('payment_gateway') as $gateway) {
            if (! $this->confirmsManually($gateway)) {
                continue;
            }

            $offlineCheckout()
                ->where(fn (Builder $query) => blank($gateway)
                    ? $query->whereNull('payment_gateway')->orWhere('payment_gateway', '')
                    : $query->where('payment_gateway', $gateway))
                ->update(['payment_settlement' => 'unresolved', 'payment_id' => '']);
        }

        Schema::table('resrv_reservations', function (Blueprint $table) {
            $table->dropColumn('payment_unresolved');
        });
    }

    public function down(): void
    {
        Schema::table('resrv_reservations', function (Blueprint $table) {
            $table->boolean('payment_unresolved')->default(false);
        });

        DB::table('resrv_reservations')
            ->whereIn('payment_settlement', ['out_of_band', 'unresolved'])
            ->whereNotNull('payment_id')
            ->where('payment_id', '!=', '')
            ->update(['payment_unresolved' => true]);

        Schema::table('resrv_reservations', function (Blueprint $table) {
            $table->dropColumn('payment_settlement');
        });
    }

    /** Whether the gateway recorded on a row is an offline one, as Reservation::resolvePaymentGateway() finds it. */
    protected function confirmsManually(?string $gateway): bool
    {
        try {
            $manager = app(PaymentGatewayManager::class);

            return (blank($gateway) ? $manager->gateway() : $manager->gateway($gateway))->supportsManualConfirmation();
        } catch (Throwable) {
            return false;
        }
    }
};
