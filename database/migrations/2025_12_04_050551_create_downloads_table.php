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
				->nullable()
				->constrained("users")
				->onDelete("cascade");
			$table
				->string("job_id")
				->unique()
				->index();
			$table->string("url");
			$table->string("filename")->nullable();
			$table->string("file_extension")->nullable();
			$table->string("mime_type")->nullable();
			$table->bigInteger("file_size")->nullable();
			$table->bigInteger("downloaded_size")->default(0);
			$table->string("status")->default("pending"); // pending, downloading, completed, failed, processing, paused, cancelled
			$table->integer("connections")->default(1);
			$table->decimal("progress", 5, 2)->default(0);
			$table->float("download_speed")->nullable();
			$table->text("metadata")->nullable();
			$table->text("error_message")->nullable();
			$table->string("save_path")->nullable();
			$table->json("resume_info")->nullable();
			$table->timestamp("started_at")->nullable();
			$table->timestamp("completed_at")->nullable();
			$table->timestamps();

			$table->index(["status", "created_at"]);
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
