<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Authentik account each user signs in with, if any: the "sub" claim
     * Authentik sends for it. See AuthentikController.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('authentik_id')->nullable()->unique()->after('github_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['authentik_id']);
            $table->dropColumn('authentik_id');
        });
    }
};
