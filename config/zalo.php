<?php
return ['allowed_hosts'=>array_filter(explode(',',env('ZALO_ALLOWED_HOSTS','zalo-core,localhost,127.0.0.1,host.docker.internal')))];
