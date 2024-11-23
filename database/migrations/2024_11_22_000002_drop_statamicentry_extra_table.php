
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::dropIfExists('resrv_statamicentry_extra');
    }

    public function down()
    {
        Schema::create('resrv_statamicentry_extra', function (Blueprint $table) {
            $table->string('statamicentry_id')->index();
            $table->integer('extra_id')->index();
        });
    }
};