#!/usr/bin/python3
import json,os,time,ssl,smtplib,urllib.request,subprocess,fcntl,sys
from email.message import EmailMessage
BASE='/var/lib/pancake-monitor'
os.makedirs(BASE,exist_ok=True)
lock=open(BASE+'/lock','w')
try: fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
except BlockingIOError: sys.exit(0)
cfg=json.load(open('/etc/pancake-monitor/smtp.json'))
def send(subject,body):
 if not cfg.get('username') or not cfg.get('password'): raise RuntimeError('SMTP not configured; alert retained for retry')
 msg=EmailMessage();msg['From']=cfg['from'];msg['To']=cfg['to'];msg['Subject']=subject;msg.set_content(body)
 port=int(cfg['port'])
 client=smtplib.SMTP_SSL(cfg['host'],port,timeout=15,context=ssl.create_default_context()) if port==465 else smtplib.SMTP(cfg['host'],port,timeout=15)
 with client as smtp:
  if port!=465:smtp.starttls(context=ssl.create_default_context())
  smtp.login(cfg['username'],cfg['password']);smtp.send_message(msg)
if '--test-email' in sys.argv:
 send('[CRM] Kiểm tra email giám sát','Email kiểm tra cảnh báo website và worker webhook Pancake.');print('SMTP accepted test email');sys.exit(0)
try: state=json.load(open(BASE+'/state.json'))
except (IOError,ValueError):state={}
checks={}
for name,path,expected in [('website','/dang-nhap',200),('website-api','/api/v1/me',401)]:
 try:
  req=urllib.request.Request('http://127.0.0.1:8081'+path,headers={'Accept':'application/json'})
  try:
   with urllib.request.urlopen(req,timeout=8) as r:code=r.status;body=r.read(100000)
  except urllib.error.HTTPError as e:code=e.code;body=e.read(100000)
  checks[name]=(code==expected and (name!='website' or b'id="root"' in body),'HTTP '+str(code))
 except Exception as e:checks[name]=(False,type(e).__name__)
try:
 p=subprocess.run(['/usr/bin/supervisorctl','status','pancake_get_order_order_webhook:*'],stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=8,universal_newlines=True)
 checks['webhook-worker']=('RUNNING' in p.stdout,'RUNNING' if 'RUNNING' in p.stdout else 'Worker not running')
except Exception as e:checks['webhook-worker']=(False,type(e).__name__)
now=int(time.time())
for name,(ok,detail) in checks.items():
 item=state.setdefault(name,{'failures':0,'last_sent':0,'alerted':False})
 item['checked_at']=now;item['ok']=ok;item['detail']=detail
 item['failures']=0 if ok else item['failures']+1
 incident=not ok and item['failures']>=2 and (not item['alerted'] or now-item['last_sent']>=1800)
 recovery=ok and item['alerted']
 if incident or recovery:
  try:
   send('[CRM] '+('ĐÃ PHỤC HỒI: ' if recovery else 'SỰ CỐ: ')+name,
    'Dịch vụ: '+name+'\nTrạng thái: '+detail+'\nThời gian: '+time.strftime('%Y-%m-%d %H:%M:%S %z')+'\nWebsite: http://123.31.31.112:8081\nKiểm tra 1 phút/lần; cảnh báo sau 2 lần lỗi; nhắc sau 30 phút.\nKhông tự restart hoặc retry job.')
   item['alerted']=not recovery;item['last_sent']=now;item.pop('mail_error',None)
  except Exception as e:item['mail_error']=type(e).__name__+': '+str(e)[:120]
tmp=BASE+'/state.tmp'
with open(tmp,'w') as f:json.dump(state,f)
os.chmod(tmp,0o600);os.replace(tmp,BASE+'/state.json')
print(json.dumps({k:{'ok':v['ok'],'failures':v['failures'],'alerted':v['alerted'],'mail_error':v.get('mail_error')} for k,v in state.items()}))
