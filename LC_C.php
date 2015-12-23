<?php

/**
 * 日志服务器：客户端
 *
 * 简介：
 * 此服务用于异步发送数据至日志服务器：传输协议 UDP
 *
 * 使用方法：
 * 1.配置以下配置参数：
 * 	(i)	队列id唯一
 *  (ii)内存队列配置:/etc/sysctl.conf  参考：http://blog.csdn.net/truelie/article/details/1622088
		 kernel.msgmnb 每个消息队列的最大字节限制。
		 kernel.msgmax 单个消息的最大size。
 * 2.php Log_Client.php
 *
 * 作者：huangluxiao
 * 时间：2015.2.3
 */

/**
 * 运行日志目录
 * @var int
 */
define('RUNTIME_LOG_PATH', '/tmp/lc/client.log');

/**
 * 队列:id
 * @var int
 */
define('MSG_KEY', '0x1000001');
/**
 * 队列:权限
 * @var string
 */
define('MSG_PRIVILEGE', '0666');
/**
 * 地址:日志服务器
 * @var string
 */
define('SERVER_URL',"udp://192.168.0.21:9999");
/**
 * 客户端：发送进程数
 * @var int
 */
define('WORKER_NUM', 5);
/**
 * 死循环睡眠周期
 * @var int
 */
define('SLEEP_MICRO_SECONDS', 20);
daemon();
$queue = msg_get_queue(MSG_KEY, MSG_PRIVILEGE);
if($queue === false)
{
	error_log("[ERROR]create queue fail\n",3,RUNTIME_LOG_PATH);
    exit();
}
$worker_pid = array();
for($i = 1; $i < WORKER_NUM; $i++)
{
	$pid = pcntl_fork();
	if($pid > 0)
	{
		$worker_pid[] = $pid;
        error_log("[RUNTIME]create worker $i.pid = $pid\n",3,RUNTIME_LOG_PATH);
		continue;
	}
	//子进程(读内存发数据)
	elseif($pid == 0)
	{
		proc_worker($i);
		exit;
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

/**
 * 发送子进程
 * @param unknown_type $id
 */
function proc_worker($id)
{
    global $queue;
    $msg_type = 0;
    $msg_pkt = '';
    $errCode = 0;
    while(1)
    {
        $ret = msg_receive($queue, 0, $msg_type, 8192, $msg_pkt, false, $errCode);
        if($ret)
        {
        	error_log("[WORKER $id] msg_receive:[".$msg_pkt."]\n",3,RUNTIME_LOG_PATH);
        	send_message(SERVER_URL,$msg_pkt);
        }
        else
        {
        	error_log("[ERROR]: msg_receive:[{$errCode}]\n",3,RUNTIME_LOG_PATH);
        }
        usleep(SLEEP_MICRO_SECONDS);
    }
}

function send_message($server_url,$message)
{
	$fp = stream_socket_client($server_url, $errno, $errstr);
	
	if (!$fp)
	{
		error_log("[ERROR]: stream_socket_client:[$errno - $errstr]\n",3,RUNTIME_LOG_PATH);
	}

	fwrite($fp,"$message\n");
	error_log("[UDP]: [url:$server_url][msg:$message]\n",3,RUNTIME_LOG_PATH);
	//$response =  fread($fp, 1024);
	fclose($fp);
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
