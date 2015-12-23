<?php
/**
 * PHP 日志写入类
 * 
 * @example LC_PHP_Logger::log('test contetent','');
 *  
 * @author huangluxiao
 *
 */
class LC_C_PHP_Logger
{
	/**
	 * 队列：Id
	 * @var int
	 */
	const LC_MSG_KEY = '0x1000001';
	/**
	 * 备份日志目录
	 * @var string
	 */
	const LC_LOG_PATH = '/home/logs/project/lc_c';
	
	private static $_queue;
	
	public static function getInstance() {
		if (!self::$_queue) {
			self::$_queue = msg_get_queue(self::LC_MSG_KEY);
		}
		return self::$_queue;
	}
	public static function log($message, $type='') {
		$contents = trim($message);
		
		if (!$contents) {
			return false;
		}
		if(empty($type))
		{
			$type = 'common';
		}
		
		$queue = self::getInstance();
		$msg = json_encode(array('msg'=>$contents,'type'=>$type));
		msg_send($queue, 1,$msg ,false,false,$errorcode);
		
		$unix_time = time();
		
		$Y = date("Y", $unix_time);
		$m = date("m", $unix_time);
		$d = date("d", $unix_time);
		$H = date("H", $unix_time);
		$log_dir = self::LC_LOG_PATH."/".$type."/$Y/$m/$d";
		if (!is_dir($log_dir)) {
			@mkdir($log_dir, 0777, true);
		}
		$log = $log_dir."/".$type.'.'.$H.'.'."log";
		file_put_contents($log, "$contents\n", FILE_APPEND);
		
		return true;
	}
}
