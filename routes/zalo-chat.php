<?php
use App\Http\Controllers\Api\ZaloChatController as Zalo;
use App\Http\Middleware\AdminOnlyMiddleware;
use Illuminate\Support\Facades\Route;
Route::middleware('auth:api')->prefix('v1/zalo-chat')->group(function(){
 Route::middleware(AdminOnlyMiddleware::class)->group(function(){
  Route::get('connection',[Zalo::class,'configuration']);
  Route::put('connection',[Zalo::class,'save']);
  Route::post('connection/test',[Zalo::class,'test']);
  Route::get('monitoring',[Zalo::class,'monitoring']);
  Route::post('accounts',[Zalo::class,'createAccount']);
  Route::get('accounts/{accountId}/grants',[Zalo::class,'grants'])->whereUuid('accountId');
  Route::put('accounts/{accountId}/grants',[Zalo::class,'saveGrants'])->whereUuid('accountId');
 });
 Route::get('accounts',[Zalo::class,'accounts']);
 Route::get('conversations',[Zalo::class,'conversations']);
 Route::get('conversations/{id}/messages',[Zalo::class,'messages'])->whereUuid('id');
 Route::post('conversations/{id}/messages',[Zalo::class,'send'])->whereUuid('id');
 Route::post('conversations/{id}/read',[Zalo::class,'read'])->whereUuid('id');
 Route::get('accounts/{accountId}/friends',[Zalo::class,'friends'])->whereUuid('accountId');
 Route::post('accounts/{accountId}/friends/open',[Zalo::class,'openFriend'])->whereUuid('accountId');
});
