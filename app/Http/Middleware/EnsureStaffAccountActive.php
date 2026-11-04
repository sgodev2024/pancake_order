<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Support\Facades\DB;
class EnsureStaffAccountActive {
 public function handle($request, Closure $next) {
  if($request->user() && DB::table('staff_account_states')->where('user_id',$request->user()->id)->exists())
   return response()->json(['success'=>false,'message'=>'Tài khoản đã ngừng hoạt động. Vui lòng liên hệ Admin.'],403);
  return $next($request);
 }
}
