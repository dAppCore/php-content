<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add preview token fields to content_items for draft preview functionality.
     */
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->string('preview_token', 64)->nullable()->after('cdn_purged_at');
            $table->timestamp('preview_expires_at')->nullable()->after('preview_token');

            $table->index('preview_token');
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->dropIndex(['preview_token']);
            $table->dropColumn(['preview_token', 'preview_expires_at']);
        });
    }
};
