<?php

// database/migrations/xxxx_xx_xx_xxxxxx_add_last_seen_at_to_users_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up() {
        Schema::table('users', function (Blueprint $table) {
            // Add after 'remember_token' or another suitable column
            $table->timestamp('last_seen_at')->nullable()->after('remember_token');
        });
    }
    public function down() {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_seen_at');
        });
    }
};