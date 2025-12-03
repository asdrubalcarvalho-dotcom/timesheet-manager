<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Se a tabela não existir, não faz nada (evita erro)
        if (!Schema::hasTable('timesheets')) {
            return;
        }

        try {
            Schema::table('timesheets', function (Blueprint $table) {
                $table->dropUnique('timesheets_technician_id_project_id_date_unique');
            });
        } catch (\Exception $e) {
            // Índice não existe, ignorar erro
        }
    }

    public function down()
    {
        // Se a tabela não existir, não faz nada
        if (!Schema::hasTable('timesheets')) {
            return;
        }

        Schema::table('timesheets', function (Blueprint $table) {
            $table->unique(['technician_id', 'project_id', 'date']);
        });
    }
};
