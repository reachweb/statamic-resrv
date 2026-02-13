<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resrv_reservations', function (Blueprint $table) {
            $table->timestamp('abandoned_email_sent_at')->nullable()->after('status');
        });

        DB::table('resrv_reservations')
            ->where('status', 'expired')
            ->update(['abandoned_email_sent_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('resrv_reservations', function (Blueprint $table) {
            $table->dropColumn('abandoned_email_sent_at');
        });
    }
};
