<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('establecimientos_socios', function (Blueprint $table) {
            $table->string('secuencial')->after('id');
            $table->unique(['id_socio', 'secuencial'], 'id_socio_secuencial_unique');
        });
    }

    public function down()
    {
        Schema::table('establecimientos_socios', function (Blueprint $table) {
            $table->dropUnique('id_socio_secuencial_unique');
            $table->dropColumn('secuencial');
        });
    }
};