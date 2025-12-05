<?php

namespace Modules\Downloader\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Modules\Downloader\Constants\Permission;

class DownloaderController extends Controller
{
	public function __construct()
	{
		$this->middleware(["permission:" . Permission::VIEW_DOWNLOADERS])->only([
			"index",
		]);
	}

	/**
	 * Display a listing of the resource.
	 */
	public function index()
	{
		$user = \Auth::user();

		$activeDownloads = method_exists($user, "activeDownloads")
			? $user->activeDownloads()->get()
			: collect();
		$completedDownloads = method_exists($user, "completedDownloads")
			? $user
				->completedDownloads()
				->latest()
				->take(10)
				->get()
			: collect();

		return view(
			"downloader::index",
			compact("activeDownloads", "completedDownloads")
		);
	}

	/**
	 * Show the form for creating a new resource.
	 */
	public function create()
	{
		return view("downloader::create");
	}

	/**
	 * Store a newly created resource in storage.
	 */
	public function store(Request $request)
	{
	}

	/**
	 * Show the specified resource.
	 */
	public function show($id)
	{
		return view("downloader::show");
	}

	/**
	 * Show the form for editing the specified resource.
	 */
	public function edit($id)
	{
		return view("downloader::edit");
	}

	/**
	 * Update the specified resource in storage.
	 */
	public function update(Request $request, $id)
	{
	}

	/**
	 * Remove the specified resource from storage.
	 */
	public function destroy($id)
	{
	}
}
