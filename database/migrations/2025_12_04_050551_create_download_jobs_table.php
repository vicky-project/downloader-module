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
		Schema::create("download_jobs", function (Blueprint $table) {
			$table->id();
			$table
				->foreignId("user_id")
				->nullable()
				->constrained("users")
				->onDelete("cascade");
			$table->string("job_id")->unique();
			$table->string("url");
			$table->string("filename");
			$table->string("original_filename");
			$table->string("file_type");
			$table->decimal("file_size", 10, 2)->nullable();
			$table->integer("progress")->default(0);
			$table->string("status")->default("pending"); // pending, downloading, completed, failed
			$table->text("error_message")->nullable();
			$table->string("local_path")->nullable();
			$table->json("metadata")->nullable();
			$table->timestamps();
		});
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void
	{
		Schema::dropIfExists("download_jobs");
	}
};
