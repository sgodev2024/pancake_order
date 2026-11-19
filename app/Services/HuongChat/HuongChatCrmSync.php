<?php
namespace App\Services\HuongChat;
use App\Models\{Shop,Order,Customer,Product};
use App\Services\{CustomerEnteredSystemEventWriter,OrderCreatedEventWriter,ReportDataVersion};
use Illuminate\Support\Facades\{DB,Cache};
use Illuminate\Support\Carbon;
class HuongChatCrmSync {
 const SHOP_KEY='huongchat_web';
 public function run(bool $dry=false):array {
  $lock=Cache::lock('huongchat-crm-sync',600);
  if(!$lock->get())throw new \RuntimeException('Đồng bộ Hương Chất đang chạy.');
  try {
   $api=app(HuongChatOrderService::class);$rows=[];$seen=[];
   for($page=1;$page<=1000;$page++){
    $result=$api->getOrders(['page'=>$page,'per_page'=>100]);
    if(!$result['success'])throw new \RuntimeException('Không đọc được danh sách Hương Chất.');
    foreach($result['data'] as $row){$id=(int)($row['id']??0);if($id<1)throw new \RuntimeException('Mã đơn nguồn không hợp lệ.');if(isset($seen[$id]))throw new \RuntimeException('Nguồn phân trang bị trùng, dừng để thử lại.');$seen[$id]=true;
     if(DB::table('hidden_huongchat_orders')->where('order_id',$id)->exists())continue;
     $detail=$api->getOrderDetail($id);if(!$detail['success'])throw new \RuntimeException('Không đọc được chi tiết đơn nguồn.');
     $rows[]=$this->normalize($detail['data']);
    }
    if($page>=(int)($result['pagination']['last_page']??1))break;
    if($page===1000)throw new \RuntimeException('Vượt giới hạn trang; không nhập dữ liệu thiếu.');
   }
   $counts=['source'=>count($seen),'excluded'=>count($seen)-count($rows),'created'=>0,'updated'=>0,'unchanged'=>0];
   if($dry)return $counts+['dry_run'=>true,'eligible'=>count($rows),'completed'=>count(array_filter($rows,fn($r)=>$r['status']===3))];
   DB::transaction(function()use($rows,&$counts){
    $shop=Shop::withTrashed()->where('pancake_shop_id',self::SHOP_KEY)->lockForUpdate()->first();
    if($shop && $shop->trashed())throw new \RuntimeException('Cửa hàng Web đã xóa, cần khôi phục trước.');
    if(!$shop)$shop=Shop::create(['pancake_shop_id'=>self::SHOP_KEY,'name'=>'Hương Chất Web','api_key'=>'','care_cycle_days'=>5,'pancake_full_data'=>['origin'=>'huongchat_web','website'=>'https://huongchat.com']]);
    foreach($rows as $r){
     // Re-check local deletion markers under the same transaction as imports.
     if(DB::table('hidden_huongchat_orders')->where('order_id',$r['external_id'])->exists())continue;
     $order=Order::withTrashed()->where('pancake_order_id',$r['key'])->lockForUpdate()->first();
     if($order && $order->trashed())continue;
     if($order && data_get($order->pancake_full_data,'source_hash')===$r['hash']){$counts['unchanged']++;continue;}
     $customerKey='hcweb-c-'.hash('sha256',$r['phone']!==''?$r['phone']:'order:'.$r['external_id']);
     $customer=Customer::firstOrNew(['shop_id'=>$shop->id,'pancake_customer_id'=>$customerKey]);$newCustomer=!$customer->exists;
     $customer->fill(['name'=>$r['name'],'phone_numbers'=>$r['phone'],'pancake_full_data'=>['origin'=>'huongchat_web','address'=>$r['address']]])->save();
     if($newCustomer)app(CustomerEnteredSystemEventWriter::class)->write($customer,'huongchat_web');
     $items=[];foreach($r['products'] as $line){
      $product=Product::updateOrCreate(['shop_id'=>$shop->id,'pancake_product_id'=>'hcweb-p-'.$line['id']],['name'=>$line['name'],'pancake_full_data'=>['origin'=>'huongchat_web','retail_price'=>$line['price'],'images'=>empty($line['image'])?[]:[$line['image']]]]);
      $items[]=['product_id'=>$product->pancake_product_id,'name'=>$product->name,'quantity'=>$line['quantity'],'variation_info'=>['name'=>$product->name,'retail_price'=>$line['price'],'images'=>empty($line['image'])?[]:[$line['image']]]];
     }
     $new=!$order;if(!$order)$order=new Order;
     $payload=['origin'=>'huongchat_web','source_hash'=>$r['hash'],'source_order_id'=>$r['external_id'],'source_order_code'=>$r['code'],'source_status'=>$r['source_status'],'payment_status'=>$r['payment_status'],'items'=>$items,'prepaid'=>$r['paid'],'customer'=>['name'=>$r['name'],'phone_numbers'=>[$r['phone']],'address'=>$r['address']]];
     $order->fill(['shop_id'=>$shop->id,'customer_id'=>$customer->id,'pancake_customer_id'=>$customerKey,'pancake_order_id'=>$r['key'],'origin'=>'huongchat_web','customer_name'=>$r['name'],'customer_phone'=>$r['phone'],'customer_address'=>$r['address'],'total_quantity'=>array_sum(array_column($r['products'],'quantity')),'cod'=>$r['total'],'cash'=>$r['paid'],'prepaid_amount'=>$r['paid'],'note'=>$r['note'],'status'=>$r['status'],'source_created_at'=>$r['created'],'pancake_order_source_id'=>'huongchat_web','pancake_order_source_name'=>'Hương Chất Web','pancake_order_page_id'=>'huongchat_web','pancake_order_page_name'=>'Hương Chất Web','pancake_full_data'=>$payload]);
     if($new){$order->created_at=$r['created'];$order->first_synced_at=now();}
     // Preserve CRM ownership, assignments and warehouse operations on every update.
     $order->save();if($new)app(OrderCreatedEventWriter::class)->write($order,'huongchat_web',$r['created'],$customer);
     $counts[$new?'created':'updated']++;
    }
    foreach(Customer::where('shop_id',$shop->id)->get() as $c){$q=Order::where('shop_id',$shop->id)->where('customer_id',$c->id)->where('status',3);$c->update(['order_count'=>(clone $q)->count(),'purchased_amount'=>(clone $q)->sum('cod'),'last_order_at'=>(clone $q)->max('created_at')]);}
    ReportDataVersion::afterCommit('orders','customers','products');
   },3);
   return $counts;
  }finally{$lock->release();}
 }
 public function normalize(array $row):array {
  $id=filter_var($row['id']??null,FILTER_VALIDATE_INT);if(!$id||$id<1)throw new \RuntimeException('Mã đơn không hợp lệ.');
  $status=['pending'=>0,'confirmed'=>1,'completed'=>3,'cancelled'=>6][$row['status']??'']??null;if($status===null)throw new \RuntimeException('Trạng thái nguồn chưa được ánh xạ.');
  $total=$row['total_price']??null;$deposit=$row['deposit_amount']??0;
  foreach([$total,$deposit] as $value)if(!is_numeric($value)||$value<0||$value>999999999999)throw new \RuntimeException('Giá trị tiền không hợp lệ.');
  $payment=(int)($row['payment_status']??0);$paid=$payment===1?(float)$total:($payment===2?(float)$deposit:0);
  if($paid>$total)throw new \RuntimeException('Thanh toán vượt giá trị đơn.');
  if(empty($row['created_at']))throw new \RuntimeException('Đơn thiếu thời gian nguồn.');$created=Carbon::parse($row['created_at'])->setTimezone(config('app.timezone'));
  $products=$row['products']??null;if(!is_array($products))throw new \RuntimeException('Chi tiết đơn thiếu sản phẩm.');
  foreach($products as $line)if(empty($line['id'])||empty($line['name'])||!is_numeric($line['price']??null)||$line['price']<0||!is_numeric($line['quantity']??null)||$line['quantity']<1)throw new \RuntimeException('Sản phẩm không hợp lệ.');
  $phone=preg_replace('/[^0-9+]/','',(string)($row['phone']??''));if(str_starts_with($phone,'+84'))$phone='0'.substr($phone,3);
  return ['external_id'=>$id,'key'=>'hcweb-o-'.$id,'code'=>(string)($row['code']??$id),'hash'=>hash('sha256',json_encode($row)),'name'=>(string)($row['customer_name']??'Khách vãng lai'),'phone'=>$phone,'address'=>(string)($row['address']??''),'note'=>(string)($row['notes']??''),'total'=>(float)$total,'paid'=>$paid,'status'=>$status,'source_status'=>$row['status'],'payment_status'=>$payment,'created'=>$created,'products'=>$products];
 }
}
