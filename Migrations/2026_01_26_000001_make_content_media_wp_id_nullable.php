<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make wp_id nullable for direct uploads (non-WordPress).
     */
    public function up(): void
    {
        Schema::table('content_media', function (Blueprint $table) {
            $table->unsignedBigInteger('wp_id')->nullable()->change();
        });

        // Drop the unique constraint that includes wp_id
        Schema::table('content_media', function (Blueprint $table) {
            $table->dropUnique(['workspace_id', 'wp_id']);
        });

        // Add a unique constraint that allows multiple null wp_ids
        Schema::table('content_media', function (Blueprint $table) {
            $table->unique(['workspace_id', 'wp_id'], 'content_media_workspace_wp_unique');
        });
    }

    public function down(): void
    {
        Schema::table('content_media', function (Blueprint $table) {
            $table->dropUnique('content_media_workspace_wp_unique');
        });

        Schema::table('content_media', function (Blueprint $table) {
            $table->unique(['workspace_id', 'wp_id']);
        });

        Schema::table('content_media', function (Blueprint $table) {
            $table->unsignedBigInteger('wp_id')->nullable(false)->change();
        });
    }
};
