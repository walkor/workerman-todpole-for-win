<?php 
namespace Man\Core;
require_once WORKERMAN_ROOT_DIR . 'Core/Events/Select.php';

/**
 * 抽象ThreadWorker类
 * 必须实现run方法
* @author walkor <worker-man@qq.com>
*/
class ThreadWorker extends \Thread
{
    protected $mainSocket = null;
    protected $workerFile = '';
    protected $serviceName = '';
    protected $className ='';
    
    public function __construct($main_socket, $worker_file, $service_name)
    {
        $this->mainSocket = $main_socket;
        $this->workerFile = $worker_file;
        $this->serviceName = $service_name;
        $this->className = basename($worker_file, '.php');
    }
    
    public function run()
    {
        //require_once WORKERMAN_ROOT_DIR . '../applications/EchoWorker/EchoWorker.php';
        //$class_name = 'EchoWorker';
        chdir(WORKERMAN_ROOT_DIR);
        require_once $this->workerFile;
        $worker = new $this->className($this->serviceName);
        
        // 如果该worker有配置监听端口，则将监听端口的socket传递给子进程
        if($this->mainSocket)
        {
            $worker->setListendSocket($this->mainSocket);
        }
        
        // 使worker开始服务
        $worker->start();
    }
}



