<?php
namespace App\Services;
use Illuminate\Support\Facades\{DB,Mail,Cache,Log};
class CareNotificationService {
 public const PATHS=['assigned'=>'/kh-cham-soc-hom-nay','today'=>'/kh-cham-soc-hom-nay','overdue'=>'/khach-cham-soc-qua-han'];
 public static function render(string $text,array $values):string {return strtr($text,array_combine(array_map(fn($k)=>'{{'.$k.'}}',array_keys($values)),array_values($values)));}
 public function run():array {
  $lock=Cache::lock('care-notification-dispatch',180);if(!$lock->get())return ['locked'=>true];
  try {
   $templates=DB::table('care_notification_templates')->get()->keyBy('type');$counts=['created'=>0,'emailed'=>0,'failed'=>0];$now=now()->timezone('Asia/Ho_Chi_Minh');$date=$now->toDateString();
   $query=DB::table('customer_care_assignments as a')->join('customer_cares as c','c.id','=','a.customer_care_id')->join('users as u','u.id','=','a.assignee_user_id')->join('shops as s','s.id','=','a.shop_id')
    ->whereNotNull('c.date_care')->where('a.status','active')->whereNull('a.cared_at')->where('c.status',0)->where('c.is_accept',1)->whereNull('s.deleted_at')->whereColumn('c.shop_id','a.shop_id')
    ->whereExists(function($q){$q->selectRaw('1')->from('shop_users as su')->whereColumn('su.user_id','u.id')->whereColumn('su.shop_id','a.shop_id');})
    ->whereNotExists(function($q){$q->selectRaw('1')->from('staff_account_states as disabled')->whereColumn('disabled.user_id','u.id');})
    ->select('a.id','a.assigned_at','a.assignee_user_id','a.shop_id','c.date_care','u.name','u.email')->orderBy('a.id');
   foreach($query->get()->groupBy(fn($r)=>$r->assignee_user_id.':'.$r->shop_id) as $group){
    $recipient=$group->first();
    foreach(['assigned','today','overdue'] as $type){
     $tpl=$templates[$type];$rows=$group->filter(function($r)use($type,$tpl,$date){return match($type){'assigned'=>$r->assigned_at >= $tpl->created_at,'today'=>substr($r->date_care,0,10)===$date,'overdue'=>substr($r->date_care,0,10)<$date};});
     if($rows->isEmpty())continue;
     // Daily digest once a day after 08:00; assignment notification once per assignment.
     if($type!=='assigned' && $now->hour<8)continue;
     $batches=$type==='assigned'?$rows->map(fn($r)=>collect([$r])):collect([$rows]);
     foreach($batches as $batch){
      $key=$type.':'.$recipient->assignee_user_id.':'.$recipient->shop_id.':'.($type==='assigned'?$batch->first()->id:$date);
      $path=self::PATHS[$type];
      if($type==='assigned'){$due=substr($batch->first()->date_care,0,10);$path=$due>$date?'/lich-cham-soc-sap-dien-ra':($due<$date?'/khach-cham-soc-qua-han':'/kh-cham-soc-hom-nay');}
      $workPath=$path.'?shop_id='.$recipient->shop_id;
      $values=['employee_name'=>$recipient->name,'task_count'=>(string)$batch->count(),'date'=>$date,'login_url'=>'http://123.31.31.112:8081/dang-nhap','work_url'=>'http://123.31.31.112:8081'.$workPath];
      $title=self::render($tpl->subject,$values);$body=self::render($tpl->body,$values);
      $new=DB::table('care_user_notifications')->insertOrIgnore(['user_id'=>$recipient->assignee_user_id,'shop_id'=>$recipient->shop_id,'type'=>$type,'dedup_key'=>$key,'title'=>$title,'body'=>$body,'path'=>$workPath,'task_count'=>$batch->count(),'email_status'=>'not_requested','created_at'=>now()]);
      if(!$new)continue;$counts['created']++;
      $eligible=$tpl->email_enabled && ($type!=='assigned' || $batch->first()->assigned_at >= $tpl->email_enabled_at);
      // Re-check switch immediately before sending. Disabling never queues a future email.
      if(!$eligible || !DB::table('care_notification_templates')->where('type',$type)->value('email_enabled'))continue;
      if(!filter_var($recipient->email,FILTER_VALIDATE_EMAIL))continue;
      DB::table('care_user_notifications')->where('dedup_key',$key)->update(['email_status'=>'sending']);
      try {
       MailConfigService::setDynamicConfig();if(config('mail.default')!=='smtp')throw new \RuntimeException('SMTP chưa được cấu hình.');
       Mail::raw($body,fn($m)=>$m->to($recipient->email)->subject($title));
       DB::table('care_user_notifications')->where('dedup_key',$key)->update(['email_status'=>'sent']);$counts['emailed']++;
      }catch(\Throwable $e){DB::table('care_user_notifications')->where('dedup_key',$key)->update(['email_status'=>'failed']);$counts['failed']++;Log::error('care_notification.email_failed',['notification_key'=>$key,'exception'=>get_class($e)]);}
     }
    }
   }
   return $counts;
  }finally{$lock->release();}
 }
}
