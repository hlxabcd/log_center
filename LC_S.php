<?php
/**
 * 日志服务器：服务端
 * 
 * 简介：
 * 此服务用于异步接收多机汇总日志使用，此为服务端程序。传输协议：UDP
 * 
 * 使用方法：
 * 1.配置以下配置参数：
 * 	(i)	队列id唯一
 *  (ii)内存队列配置:/etc/sysctl.conf  参考：http://blog.csdn.net/truelie/article/details/1622088
		kernel.msgmnb 每个消息队列的最大字节限制。
		kernel.msgmax 单个消息的最大size。 
 * 2.php LogServer.php
 * 
 * 作者：huangluxiao
 * 时间：2015.2.3
 */

/**
 * 运行日志目录
 * @var int
 */
define('RUNTIME_LOG_PATH', '/tmp/lc/server.log');
/**
 * 队列：id
 * @var int
 */
define('MSG_KEY', '0x1000002');
/**
 * 队列：权限
 * @var string
 */
define('MSG_PRIVILEGE', '0666');
/**
 * 地址：日志服务器
 * @var string
 */
define('SERVER_URL',"udp://192.168.0.21:9999");
/**
 * 进程数：日志服务器
 * @var int
 */
define('WORKER_NUM', 5);
/**
 * 死循环睡眠周期
 * @var int
 */
define('SLEEP_MICRO_SECONDS', 20);

daemon();
$worker_pid = array();

$queue = msg_get_queue(MSG_KEY, MSG_PRIVILEGE);
if($queue === false)
{
	error_log("[ERROR]create queue fail\n",3,RUNTIME_LOG_PATH);
    exit();
}
for($i = 0; $i <= WORKER_NUM; $i++)
{
    $pid = pcntl_fork();
    if($pid > 0)
    {
        $worker_pid[] = $pid;
        error_log("[RUNTIME]create worker $i.pid = $pid\n",3,RUNTIME_LOG_PATH);
        continue;
    }
    //子进程(读内存写文本)
    elseif($pid == 0)
    {
    	if($i == 0)
    	{
    		// 主进程(接受udp请求写内存)
    		proc_server($i);
    	}
    	else
    	{
    		proc_writeer($i);
    	}
        exit();
    }
    else
    {
    	error_log("[ERROR]fork fail\n",3,RUNTIME_LOG_PATH);
    	exit();
    }
}
pcntl_signal(SIGTERM, "sigHandlerParent");
pcntl_signal(SIGINT, "sigHandlerParent");

while(1)
{
	// 每秒接收信号量
	sleep(1);
	pcntl_signal_dispatch();
}


function proc_server()
{
    global $queue;
    //建立一个UDP服务器接收请求
    $socket = stream_socket_server(SERVER_URL, $errno, $errstr, STREAM_SERVER_BIND);
    if (!$socket)
    {
    	error_log("$errstr ($errno)",3,RUNTIME_LOG_PATH);
    }
    stream_set_blocking($socket, 1);
    
    error_log("[RUNTIME]stream_socket_server bind=".SERVER_URL."\n",3,RUNTIME_LOG_PATH);
    while (1)
    {
    	usleep(SLEEP_MICRO_SECONDS);
        $errCode = 0;
        $peer = '';
        $pkt = stream_socket_recvfrom($socket, 8192, 0, $peer);

        if($pkt == false)
        {
        	error_log("[ERROR]udp error\n",3,RUNTIME_LOG_PATH);
            continue;
        }
        $ret = msg_send($queue, 1, $pkt, false, true, $errCode); //如果队列满了，这里会阻塞
        if($ret)
        {
        	error_log("[MSG_SEND] msg_send:[".$pkt."]\n",3,RUNTIME_LOG_PATH);
        	 
            stream_socket_sendto($socket, "1\n", 0, $peer);
        }
        else
        {
        	error_log("[ERROR] msg_send:[code: ".$errCode."]\n",3,RUNTIME_LOG_PATH);
            stream_socket_sendto($socket, "0\n", 0, $peer);
        }
    }
}

function proc_writeer($id)
{
    global $queue;
    $msg_type = 0;
    $msg_pkt = '';
    $errCode = 0;
    while(1)
    {
    	if($GLOBALS['STOP_SIG'])
    	{
    		exit;
    	}
    	
        $ret = msg_receive($queue, 0, $msg_type, 8192, $msg_pkt, false, $errCode);
        if($ret)
        {
            //TODO 这里处理接收到的数据
            //.... Code ....//
        	error_log("[WORKER $id] msg_receive:[".$msg_pkt."]\n",3,RUNTIME_LOG_PATH);
            log_server($msg_pkt);
        }
        else
        {
        	error_log("[ERROR]: queue errno={$errCode}\n",3,RUNTIME_LOG_PATH);
        }
        usleep(SLEEP_MICRO_SECONDS);
    }
}

function log_server($send_msg) {
	$msgArray = json_decode($send_msg,true);
	if(!$msgArray ||empty($msgArray['msg']))
	{
		return false;
	}

	if(empty($msgArray['type']))
	{
		$msgArray['type'] = 'common';
	}

	$unix_time = time();

	$contents = trim($msgArray['msg']);
	if (!$contents) {
		return false;
	}

	$Y = date("Y", $unix_time);
	$m = date("m", $unix_time);
	$d = date("d", $unix_time);
	$H = date("H", $unix_time);
	$log_dir = "/home/logs/project/lc_s/".$msgArray['type']."/$Y/$m/$d";
	if (!is_dir($log_dir)) {
		@mkdir($log_dir, 0777, true);
	}
	$log = "$log_dir/".$msgArray['type'].'.'.$H.'.'."log";
	file_put_contents($log, "$contents\n", FILE_APPEND);
	return true;
}

function sigHandlerParent($sigNo)
{
	global $worker_pid;
	if ($sigNo == SIGTERM || $sigNo == SIGINT)
	{
		error_log("[EXIT] Interrupt Signal Catched!\n",3,RUNTIME_LOG_PATH);
		foreach ($worker_pid as $pid)
		{
			posix_kill($pid,SIGTERM);
		}
		exit();
	}
}
/**
 * 创建守护进程
 */
function daemon()
{
	$pid = pcntl_fork();

	if ($pid == -1)
	{
		die("Could Not Fork!\n");
	}
	else if ($pid)
	{
		exit();
	}

	if (!posix_setsid())
	{
		die("Could Not Detach From Terminal!\n");
	}
}
