<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_edges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_id')->constrained()->cascadeOnDelete();
            $table->string('from_node_key');         // references flow_nodes.node_key
            $table->string('to_node_key');
            $table->string('from_port')->default('output_1');  // multi-port nodes
            $table->string('to_port')->default('input_1');
            $table->string('label')->nullable();
            $table->timestamps();

            $table->index(['flow_id', 'from_node_key']);
            $table->index(['flow_id', 'to_node_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_edges');
    }
};
