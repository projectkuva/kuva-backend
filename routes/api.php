<?php

use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/
Route::group(['prefix' => 'user'], function() {
	Route::post('register', 'AuthController@register');
	Route::post('auth', 'AuthController@authenticate');
	Route::post('reset/store', 'AuthController@performPasswordReset');
	Route::post('reset', 'AuthController@sendPasswordReset');
});

Route::group(['prefix' => 'user', 'middleware' => ['jwt.auth']], function() {
	Route::post('photos/create', 'PhotosController@create');
	Route::post('photos/{photo}/delete', 'PhotosController@delete');
	Route::post('photos/comment/{photo}', 'PhotosController@comment');
	Route::post('photos/like/{photo}', 'PhotosController@like');
	Route::get('photos/feed', 'PhotosController@feed');
	Route::get('photos', 'PhotosController@userPhotos');
});