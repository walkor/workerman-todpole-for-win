<?php 
namespace Man\Core;

if(!defined('WORKERMAN_ROOT_DIR'))
{
    define('WORKERMAN_ROOT_DIR', realpath(__DIR__."/../../")."/");
}

require_once WORKERMAN_ROOT_DIR . 'Core/Lib/Checker.php';
require_once WORKERMAN_ROOT_DIR . 'Core/Lib/Config.php';
require_once WORKERMAN_ROOT_DIR . 'Core/Lib/Task.php';
require_once WORKERMAN_ROOT_DIR . 'Core/Lib/Log.php';
require_once WORKERMAN_ROOT_DIR . 'Core/Lib/Mutex.php';
require_once WORKERMAN_ROOT_DIR . 'Core/SocketWorker.php';
require_once WORKERMAN_ROOT_DIR . 'Core/ThreadWorker.php';

/**
 * 
 * 主进程
 * 
 * @package Core
 * 
* @author walkor <worker-man@qq.com>
 * <b>使用示例:</b>
 * <pre>
 * <code>
 * Man\Core\Master::run();
 * <code>
 * </pre>
 * 
 */
class Master
{
    /**
     * 版本
     * @var string
     */
    const VERSION = '2.0.1';
    
    /**
     * 服务名
     * @var string
     */
    const NAME = 'WorkerMan';
    
    /**
     * 服务状态 启动中
     * @var integer
     */ 
    const STATUS_STARTING = 1;
    
    /**
     * 服务状态 运行中
     * @var integer
     */
    const STATUS_RUNNING = 2;
    
    /**
     * 服务状态 关闭中
     * @var integer
     */
    const STATUS_SHUTDOWN = 4;
    
    /**
     * 服务状态 平滑重启中
     * @var integer
     */
    const STATUS_RESTARTING_WORKERS = 8;
    
    /**
     * 整个服务能够启动的最大进程数
     * @var integer
     */
    const SERVER_MAX_WORKER_COUNT = 5000;
    
    /**
     * 单个进程打开文件数限制
     * @var integer
     */
    const MIN_SOFT_OPEN_FILES = 10000;
    
    /**
     * 单个进程打开文件数限制 硬性限制
     * @var integer
     */
    const MIN_HARD_OPEN_FILES = 10000;
    
    /**
     * 共享内存中用于存储主进程统计信息的变量id
     * @var integer
     */
    const STATUS_VAR_ID = 1;
    
    /**
     * 发送停止命令多久后worker没退出则发送sigkill信号
     * @var integer
     */
    const KILL_WORKER_TIME_LONG = 4;
    
    /**
     * 用于保存所有子进程pid ['worker_name1'=>[pid1=>pid1,pid2=>pid2,..], 'worker_name2'=>[pid3,..], ...]
     * @var array
     */
    protected static $workerPids = array();
    
    /**
     * 服务的状态，默认是启动中
     * @var integer
     */
    protected static $serverStatus = self::STATUS_STARTING;
    
    /**
     * 用来监听端口的Socket数组，用来fork worker使用
     * @var array
     */
    protected static $listenedSockets = array();
    
    /**
     * 要重启的worker的pid数组 [pid1=>time_stamp, pid2=>time_stamp, ..]
     * @var array
     */
    protected static $workerToRestart = array();
    
    /**
     * 共享内存resource id
     * @var resource
     */
    protected static $shmId = 0;
    
    /**
     * 消息队列 resource id
     * @var resource
     */
    protected static $queueId = 0;
    
    /**
     * master进程pid
     * @var integer
     */
    protected static $masterPid = 0;
    
    /**
     * server统计信息 ['start_time'=>time_stamp, 'worker_exit_code'=>['worker_name1'=>[code1=>count1, code2=>count2,..], 'worker_name2'=>[code3=>count3,...], ..] ]
     * @var array
     */
    protected static $serverStatusInfo = array(
        'start_time' => 0,
        'worker_exit_code' => array(),
    );
    
    protected static $threads = array();
    
    /**
     * 服务运行
     * @return void
     */
    public static function run()
    {
        // 输出信息
        self::notice("Workerman is starting ...", true);
        // 初始化
        self::init();
        // 检查环境
        self::checkEnv();
        // 变成守护进程
        self::daemonize();
        // 保存进程pid
        self::savePid();
        // 安装信号
        self::installSignal();
        // 创建监听套接字
        self::createSocketsAndListen();
        // 创建worker进程
        self::createWorkers();
        // 输出信息
        self::notice("\033[1A\n\033[KWorkerman start success ...\033[0m", true);
        // 标记sever状态为运行中...
        self::$serverStatus = self::STATUS_RUNNING;
        // 关闭标准输出
        self::resetStdFd();
        // 主循环
        self::loop();
    }
    
    
    /**
     * 初始化 配置、进程名、共享内存、消息队列等
     * @return void
     */
    public static function init()
    {
        // 获取配置文件
        $config_path = Lib\Config::$filename;
    
        // 设置进程名称，如果支持的话
        self::setProcessTitle(self::NAME.':master with-config:' . $config_path);
        
        // 初始化共享内存消息队列
        if(extension_loaded('sysvmsg') && extension_loaded('sysvshm'))
        {
            self::$shmId = shm_attach(IPC_KEY, DEFAULT_SHM_SIZE, 0666);
            self::$queueId = msg_get_queue(IPC_KEY, 0666);
            msg_set_queue(self::$queueId,array('msg_qbytes'=>65535));
        }
        
        // 初始化监听套接字容器
        self::$listenedSockets = new \Stackable();
        
        // 初始化线程容器
        self::$threads = new \Stackable();
    }
    
    /**
     * 检查环境配置
     * @return void
     */
    public static function checkEnv()
    {
        // 检查PID文件
        Lib\Checker::checkPidFile();
        
        // 检查扩展支持情况
        Lib\Checker::checkExtension();
        
        // 检查函数禁用情况
        Lib\Checker::checkDisableFunction();
        
        // 检查log目录是否可读
        Lib\Log::init();
        
        // 检查配置和语法错误等
        Lib\Checker::checkWorkersConfig();
        
        // 检查文件限制
        Lib\Checker::checkLimit();
    }
    
    /**
     * 使之脱离终端，变为守护进程
     * @return void
     */
    protected static function daemonize()
    {
        // 记录server启动时间
        self::$serverStatusInfo['start_time'] = time();
    }
    
    /**
     * 保存主进程pid
     * @return void
     */
    public static function savePid()
    {
        return;
        // 保存在变量中
        self::$masterPid = posix_getpid();
        
        // 保存到文件中，用于实现停止、重启
        if(false === @file_put_contents(WORKERMAN_PID_FILE, self::$masterPid))
        {
            exit("\033[31;40mCan not save pid to pid-file(" . WORKERMAN_PID_FILE . ")\033[0m\n\n\033[31;40mServer start fail\033[0m\n\n");
        }
        
        // 更改权限
        chmod(WORKERMAN_PID_FILE, 0644);
    }
    
    /**
     * 获取主进程pid
     * @return int
     */
    public static function getMasterPid()
    {
        return self::$masterPid;
    }
    
    /**
     * 根据配置文件，创建监听套接字
     * @return void
     */
    protected static function createSocketsAndListen()
    {
        // 循环读取配置创建socket
        foreach (Lib\Config::getAllWorkers() as $worker_name=>$config)
        {
            if(isset($config['listen']))
            {
                $flags = substr($config['listen'], 0, 3) == 'udp' ? STREAM_SERVER_BIND : STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
                $error_no = 0;
                $error_msg = '';
                // 创建监听socket
                self::$listenedSockets[$worker_name] = stream_socket_server($config['listen'], $error_no, $error_msg, $flags);
                if(!self::$listenedSockets[$worker_name])
                {
                    Lib\Log::add("can not create socket {$config['listen']} info:{$error_no} {$error_msg}\tServer start fail");
                    exit("\n\033[31;40mcan not create socket {$config['listen']} info:{$error_no} {$error_msg}\033[0m\n\n\033[31;40mServer start fail\033[0m\n\n");
                }
            }
        }
    }
    
    
    /**
     * 根据配置文件创建Workers
     * @return void
     */
    protected static function createWorkers()
    {
        // 循环读取配置创建一定量的worker进程
        $workers = Lib\Config::getAllWorkers();
        foreach ($workers as $worker_name=>$config)
        {
            while(empty(self::$threads->$worker_name) || count(self::$threads->$worker_name) < $config['start_workers'])
            {
                // 初始化
                if(empty(self::$threads->$worker_name))
                {
                    self::$threads->$worker_name = new \Stackable();
                }
                $worker_file = \Man\Core\Lib\Config::get($worker_name.'.worker_file');
                $main_socket = isset(self::$listenedSockets[$worker_name]) ? self::$listenedSockets[$worker_name] : null;
                $thread = new \Man\Core\ThreadWorker($main_socket, $worker_file, $worker_name);
                $thread->start();
                $thread_id = $thread->getThreadId();
                self::$threads->$worker_name->$thread_id = $thread;
            }
        }
    }
    
    /**
     * 安装相关信号控制器
     * @return void
     */
    protected static function installSignal()
    {
       return true;
    }
    
    /**
     * 忽略信号
     * @return void
     */
    protected static function ignoreSignal()
    {
     
    }
    
    /**
     * 设置子进程进程名称
     * @param string $worker_name
     * @return void
     */
    public static function setWorkerProcessTitle($worker_name)
    {
        if(isset(self::$listenedSockets[$worker_name]))
        {
            // 获得socket的信息
            $sock_name = stream_socket_get_name(self::$listenedSockets[$worker_name], false);
            
            // 更改进程名，如果支持的话
            $mata_data = stream_get_meta_data(self::$listenedSockets[$worker_name]);
            $protocol = substr($mata_data['stream_type'], 0, 3);
            self::setProcessTitle(self::NAME.":worker $worker_name {$protocol}://$sock_name");
        }
        else
        {
            self::setProcessTitle(self::NAME.":worker $worker_name");
        }
            
    }
    
    /**
     * 主进程主循环 主要是监听子进程退出、服务终止、平滑重启信号
     * @return void
     */
    public static function loop()
    {
        $siginfo = array();
        while(1)
        {
            usleep(100000);
            foreach(self::$threads as $worker_name => $threads)
            {
                foreach($threads as $thread_id => $thread)
                {
                    //var_dump($thread_id, $thread->isTerminated());
                    if($thread->isTerminated())
                    {
                        //echo "isTerminated\n";
                        $thread->join();
                        unset(self::$threads[$worker_name]->$thread_id);
                        self::createWorkers();
                    }
                }
            }
            // 初始化任务系统
            Lib\Task::tick();
            // 检查是否有进程退出
        }
    }
    
    
    /**
     * 监控worker进程状态，退出重启
     * @param resource $channel
     * @param int $flag
     * @param int $pid 退出的进程id
     * @return mixed
     */
    public static function checkWorkerExit()
    {
        
    }
    
    /**
     * 获取pid 到 worker_name 的映射
     * @return array ['pid1'=>'worker_name1','pid2'=>'worker_name2', ...]
     */
    public static function getPidWorkerNameMap()
    {
        $all_pid = array();
        foreach(self::$workerPids as $worker_name=>$pid_array)
        {
            foreach($pid_array as $pid)
            {
                $all_pid[$pid] = $worker_name;
            }
        }
        return $all_pid;
    }
    
    /**
     * 放入重启队列中
     * @param array $restart_pids
     * @return void
     */
    public static function addToRestartWorkers($restart_pids)
    {
        if(!is_array($restart_pids))
        {
            self::notice("addToRestartWorkers(".var_export($restart_pids, true).") \$restart_pids not array");
            return false;
        }
    
        // 将pid放入重启队列
        foreach($restart_pids as $pid)
        {
            if(!isset(self::$workerToRestart[$pid]))
            {
                // 重启时间=0
                self::$workerToRestart[$pid] = 0;
            }
        }
    }
    
    /**
     * 重启workers
     * @return void
     */
    public static function restartWorkers()
    {
        // 标记server状态
        if(self::$serverStatus != self::STATUS_RESTARTING_WORKERS && self::$serverStatus != self::STATUS_SHUTDOWN)
        {
            self::$serverStatus = self::STATUS_RESTARTING_WORKERS;
        }
    
        // 没有要重启的进程了
        if(empty(self::$workerToRestart))
        {
            self::$serverStatus = self::STATUS_RUNNING;
            self::notice("\nWorker Restart Success");
            return true;
        }
    
        // 遍历要重启的进程 标记它们重启时间
        foreach(self::$workerToRestart as $pid => $stop_time)
        {
            if($stop_time == 0)
            {
                self::$workerToRestart[$pid] = time();
                //posix_kill($pid, SIGHUP);
                Lib\Task::add(self::KILL_WORKER_TIME_LONG, array('\Man\Core\Master', 'forceKillWorker'), array($pid), false);
                break;
            }
        }
    }
    
    /**
     * worker进程退出时，master进程的一些清理工作
     * @param string $worker_name
     * @param int $pid
     * @return void
     */
    protected static function clearWorker($worker_name, $pid)
    {
        // 释放一些不用了的数据
        unset(self::$workerToRestart[$pid], self::$workerPids[$worker_name][$pid]);
    }
    
    /**
     * 停止服务
     * @return void
     */
    public static function stop()
    {
        
        // 如果没有子进程则直接退出
        $all_worker_pid = self::getPidWorkerNameMap();
        if(empty($all_worker_pid))
        {
            exit(0);
        }
    
        // 标记server开始关闭
        self::$serverStatus = self::STATUS_SHUTDOWN;
    
        // killWorkerTimeLong 秒后如果还没停止则强制杀死所有进程
        Lib\Task::add(self::KILL_WORKER_TIME_LONG, array('\Man\Core\Master', 'stopAllWorker'), array(true), false);
    
        // 停止所有worker
        self::stopAllWorker();
    }
    
    /**
     * 停止所有worker
     * @param bool $force 是否强制退出
     * @return void
     */
    public static function stopAllWorker($force = false)
    {
        // 获得所有pid
        $all_worker_pid = self::getPidWorkerNameMap();
    
        // 强行杀死
        if($force)
        {
            // 杀死所有子进程
            foreach($all_worker_pid as $pid=>$worker_name)
            {
                // 发送SIGKILL信号
                self::forceKillWorker($pid);
                self::notice("Kill workers[$worker_name] force");
            }
        }
        else
        {
            // 向所有子进程发送终止信号
            foreach($all_worker_pid as $pid=>$worker_name)
            {
                // 发送SIGINT信号
                //posix_kill($pid, SIGINT);
            }
        }
    }
    
    
    /**
     * 强制杀死进程
     * @param int $pid
     * @return void
     */
    public static function forceKillWorker($pid)
    {
        return ;
        if(posix_kill($pid, 0))
        {
            self::notice("Kill workers $pid force!");
            //posix_kill($pid, SIGKILL);
        }
    }
    
    
    /**
     * 设置运行用户
     * @param string $worker_user
     * @return void
     */
    protected static function setWorkerUser($worker_user)
    {
        return true;
    }
    
    /**
     * 获取共享内存资源id
     * @return resource
     */
    public static function getShmId()
    {
        return self::$shmId;
    }
    
    /**
     * 获取消息队列资源id
     * @return resource
     */
    public static function getQueueId()
    {
        return self::$queueId;
    }
    
    
    /**
     * 关闭标准输入输出
     * @return void
     */
    protected static function resetStdFd()
    {
        // 开发环境不关闭标准输出，用于调试
        if(Lib\Config::get('workerman.debug') == 1 )
        {
            return;
        }
        global $STDOUT, $STDERR;
        @fclose(STDOUT);
        @fclose(STDERR);
        // 将标准输出重定向到/dev/null
        $STDOUT = fopen('/dev/null',"rw+");
        $STDERR = fopen('/dev/null',"rw+");
    }
    
    /**
     * 更新主进程收集的状态信息到共享内存
     * @return bool
     */
    protected static function updateStatusToShm()
    {
        if(!self::$shmId)
        {
            return true;
        }
        return shm_put_var(self::$shmId, self::STATUS_VAR_ID, array_merge(self::$serverStatusInfo, array('pid_map'=>self::$workerPids)));
    }
    
    /**
     * 销毁共享内存以及消息队列
     * @return void
     */
    protected static function removeShmAndQueue()
    {
        if(self::$shmId)
        {
            shm_remove(self::$shmId);
        }
        if(self::$queueId)
        {
            msg_remove_queue(self::$queueId);
        }
    }
    
    /**
     * 设置进程名称，需要proctitle支持 或者php>=5.5
     * @param string $title
     * @return void
     */
    protected static function setProcessTitle($title)
    {
        // >=php 5.5
        if (version_compare(phpversion(), "5.5", "ge") && function_exists('cli_set_process_title'))
        {
            cli_set_process_title($title);
        }
        // 需要扩展
        elseif(extension_loaded('proctitle') && function_exists('setproctitle'))
        {
            setproctitle($title);
        }
    }
    
    /**
     * notice,记录到日志
     * @param string $msg
     * @param bool $display
     * @return void
     */
    public static function notice($msg, $display = false)
    {
        Lib\Log::add("Server:".$msg);
        if($display)
        {
            if(self::$serverStatus == self::STATUS_STARTING)
            {
                echo($msg."\n");
            }
        }
    }
}

