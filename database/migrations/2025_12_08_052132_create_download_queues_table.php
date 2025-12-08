<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
	/**
	 * Run the migrations.
	 */
	public function up(): void
	{
		Schema::create("download_queues", function (Blueprint $table) {
			$table->id();
			$table
				->foreignId("download_id")
				->constrained("downloads")
				->onDelete("cascade");
			$table->string("queue_job_id")->nullable();
			$table->integer("chunk_index");
			$table->bigInteger("start_byte")->default(0);
			$table->bigInteger("end_byte")->nullable();
			$table->bigInteger("downloaded_bytes")->default(0);
			$table->string("status")->default("pending");
			$table->string("temp_file_path")->nullable();
			$table->timestamps();
		});
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void
	{
		Schema::dropIfExists("download_queues");
	}
};
