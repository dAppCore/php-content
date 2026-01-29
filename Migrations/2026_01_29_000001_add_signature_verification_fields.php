<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add signature verification requirement and delivery logging fields.
 *
 * P2-082: Adds require_signature field to enforce signature verification
 * P2-083: Adds delivery logging fields for comprehensive webhook tracking
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add require_signature field to webhook endpoints
        Schema::table('content_webhook_endpoints', function (Blueprint $table) {
            // Require signature verification by default (security-first approach)
            // Set to false to explicitly allow unsigned webhooks
            $table->boolean('require_signature')->default(true)->after('secret');
        });

        // Add delivery logging fields to webhook logs
        Schema::table('content_webhook_logs', function (Blueprint $table) {
            // Signature verification tracking
            $table->boolean('signature_verified')->nullable()->after('source_ip');
            $table->string('signature_failure_reason', 100)->nullable()->after('signature_verified');

            // Processing duration tracking (in milliseconds)
            $table->unsignedInteger('processing_duration_ms')->nullable()->after('processed_at');

            // Request/response details
            $table->json('request_headers')->nullable()->after('payload');
            $table->unsignedSmallInteger('response_code')->nullable()->after('processing_duration_ms');
            $table->text('response_body')->nullable()->after('response_code');

            // Index for querying verification failures
            $table->index('signature_verified', 'webhook_signature_verified_idx');
        });
    }

    public function down(): void
    {
        Schema::table('content_webhook_logs', function (Blueprint $table) {
            $table->dropIndex('webhook_signature_verified_idx');
            $table->dropColumn([
                'signature_verified',
                'signature_failure_reason',
                'processing_duration_ms',
                'request_headers',
                'response_code',
                'response_body',
            ]);
        });

        Schema::table('content_webhook_endpoints', function (Blueprint $table) {
            $table->dropColumn('require_signature');
        });
    }
};
