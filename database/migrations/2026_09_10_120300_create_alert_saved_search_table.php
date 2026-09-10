<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_saved_search', function (Blueprint $table) {
            $table->foreignId('alert_id')->constrained()->cascadeOnDelete();
            $table->foreignId('saved_search_id')->constrained()->cascadeOnDelete();
            $table->primary(['alert_id', 'saved_search_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_saved_search');
    }
};
