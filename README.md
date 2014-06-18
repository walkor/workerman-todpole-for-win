workerman-todpole-multithread
=================

蝌蚪游泳交互程序-HTLM5+WebSocket+Workerman , rumpetroll server writen using php

[线上DEMO](http://kedou.workerman.net)

说明
========
此版本是多线程测试版本，此版本workerman本身还有很多功能需要完善。
此版本可以同时可以运行在window平台和linux平台。

linux 平台直接运行 ./workermand/bin/workermand start 即可（注意：多线程版本需要你的php是线程安全的，即编译时启用了--enable-maintainer-zts选项，并且安装了pthreads扩展）  
windows 平台双击运行 start.bat（注意：windows平台也需要php是线程安全版本，并且有pthreads扩展，start.bat默认使用的是此版本库中的具有pthreads扩展并且线程安全的php，用户也可以在 http://windows.php.net/download/ 上自行下载并在start.bat中将php执行路径替换）  

运行起来后浏览器访问 http://ip:8383 例如 http://127.0.0.1:8383

参考资料
========
pthreads:[http://cn2.php.net/manual/zh/pthreads.installation.php](http://cn2.php.net/manual/zh/pthreads.installation.php)

非常感谢Rumpetroll
===================
本程序是由 [Rumpetroll](http://rumpetroll.com) 修改而来，主要是后台由ruby改成了php。非常感谢Rumpetroll出色的工作。  
原 [Repo: https://github.com/danielmahal/Rumpetroll](https://github.com/danielmahal/Rumpetroll)



