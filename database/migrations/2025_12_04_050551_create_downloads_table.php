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
		Schema::create("downloads", function (Blueprint $table) {
			$table->id();
			$table
				->foreignId("user_id")
				->constrained()
				->onDelete("cascade");
			$table
				->string("job_id")
				->unique()
				->nullable();
			$table->string("filename");
			$table->string("original_filename");
			$table->string("url");
			$table->string("type"); // google_drive, youtube, direct, etc
			$table->string("status")->default("pending"); // pending, downloading, paused, completed, failed
			$table->decimal("progress", 5, 2)->default(0);
			$table->bigInteger("total_size")->nullable();
			$table->bigInteger("downloaded_size")->default(0);
			$table->integer("speed")->nullable(); // bytes per second
			$table->integer("time_remaining")->nullable(); // seconds
			$table->text("metadata")->nullable(); // JSON data for resuming
			$table->text("error_message")->nullable();
			$table->string("file_path")->nullable();
			$table->string("mime_type")->nullable();
			$table->timestamp("started_at")->nullable();
			$table->timestamp("completed_at")->nullable();
			$table->timestamps();

			$table->index(["user_id", "status"]);
			$table->index(["job_id"]);
		});
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void
	{
		Schema::dropIfExists("downloads");
	}
};
