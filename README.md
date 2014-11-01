workerman-todpole-for-win
=================

蝌蚪游泳交互程序-HTLM5+WebSocket+Workerman , rumpetroll server writen using php  

[线上DEMO](http://kedou.workerman.net)  

安装
========

安装
==============
## 1、要求安装php多线程安全版本及pthreads扩展，并设置php环境变量
PHP5.6线程安全版本下载链接：[http://windows.php.net/download](http://windows.php.net/download)   
pthreads2.0.9 for php5.6 下载链接： [http://windows.php.net/downloads/pecl/releases/pthreads](http://windows.php.net/downloads/pecl/releases/pthreads/)    
![安装线程安全php及pthreads](http://www.workerman.net/img/gif/install-php-pthread.gif)

## 2、设置php.ini，开启sockets、pthreads扩展
![设置php.ini](http://www.workerman.net/img/gif/php-ini-config.gif)

## 3、下载workerman-for-win并启动
运行起来后浏览器访问 http://ip:8383 例如 http://127.0.0.1:8383
![启动workerman-for-win](http://www.workerman.net/img/gif/run-todpole-for-win.gif)


非常感谢Rumpetroll
===================
本程序是由 [Rumpetroll](http://rumpetroll.com) 修改而来，主要是后台由ruby改成了php。非常感谢Rumpetroll出色的工作。  
原 [Repo: https://github.com/danielmahal/Rumpetroll](https://github.com/danielmahal/Rumpetroll)
