<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nova_mcp_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('user');
            $table->string('name');

            // A SHA-256 digest. The plaintext is shown to the user once and
            // never stored, so a leaked database yields no usable tokens.
            $table->string('token', 64)->unique();

            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nova_mcp_tokens');
    }
};
