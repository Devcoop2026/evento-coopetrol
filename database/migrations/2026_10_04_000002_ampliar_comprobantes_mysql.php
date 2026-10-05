<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::getConnection()->statement('ALTER TABLE soportes_archivos MODIFY contenido LONGBLOB NOT NULL');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::getConnection()->statement('ALTER TABLE soportes_archivos MODIFY contenido VARBINARY(255) NOT NULL');
    }
};
