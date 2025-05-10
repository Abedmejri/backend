<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
{
    Schema::create('meetings', function (Blueprint $table) {
        $table->id();
        $table->string('title');
        $table->dateTime('date');
        $table->string('location');
        $table->string('gps'); // Store as "lat,lng"
        $table->foreignId('commission_id')->constrained('commissions')->onDelete('cascade');
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('meetings');
    }
};
