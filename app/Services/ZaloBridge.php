<?php
namespace App\Services;
use App\Models\ZaloConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
class ZaloBridge {
 public function validateUrl(string $url): string {
  $p=parse_url($url);
  if(!$p || !in_array($p['scheme']??'',['http','https']) || !in_array(strtolower($p['host']??''),config('zalo.allowed_hosts'),true) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) || !in_array(rtrim($p['path']??'','/'),['','/api/v1'],true))
   throw ValidationException::withMessages(['base_url'=>'URL phải thuộc ZALO_ALLOWED_HOSTS và trỏ tới gốc dịch vụ hoặc /api/v1.']);
  return rtrim(preg_replace('#/api/v1/?$#','',$url),'/');
 }
 public function request(string $method,string $path,array $data=[],?array $credentials=null): mixed {
  if($credentials===null){$c=ZaloConnection::find(1);if(!$c||!$c->enabled)throw new HttpException(409,'Chưa bật kết nối Zalo. Admin cần cấu hình kết nối API.');$credentials=['base_url'=>$c->base_url,'api_key'=>$c->api_key];}
  $url=$this->validateUrl($credentials['base_url']);
  try {
   // Redirects and automatic retries are disabled: never forward secrets or duplicate a send.
   $response=Http::acceptJson()->withToken($credentials['api_key'])->connectTimeout(3)->timeout(12)->withOptions(['allow_redirects'=>false])->send($method,$url.'/api/v1/zalo-ops/'.$path,$method==='GET'?['query'=>$data]:['json'=>$data]);
  }catch(ConnectionException $e){throw new HttpException(503,'Không liên lạc được dịch vụ Zalo. Kiểm tra URL hoặc trạng thái dịch vụ.');}
  if(!$response->successful()){
   $s=$response->status();
   $message=match(true){$s===401=>'API key Zalo không hợp lệ, hết hạn hoặc đã bị thu hồi.',$s===403=>'API key chưa có quyền thực hiện thao tác Zalo này.',$s===404=>'Không tìm thấy tài khoản hoặc hội thoại Zalo.',$s>=500=>'Dịch vụ Zalo đang lỗi. Vui lòng thử lại sau.',default=>$response->json('detail')?:'Dịch vụ Zalo từ chối yêu cầu.'};
   // An upstream 401 must not sign the user out of Pancake.
   throw new HttpException(in_array($s,[401,403])?424:($s>=500||($s>=300&&$s<400)?502:$s),$message);
  }
  return $response->status()===204?null:$response->json();
 }
}
