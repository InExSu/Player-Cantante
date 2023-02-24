<?php

// http://cz81095.tw1.ru/Player-Cantante/progress.php

final class SessionHelper
{
	//Открыта ли сессия
	private static $started = false;
	
	private function __construct()
	{
	}
	
	static private function safeSessionStart()
	{
		$name = session_name();
		$cookie_session = false;
		if(ini_get('session.use_cookies') && isset($_COOKIE[$name]))
		{
			$cookie_session = true;
			$sessid = $_COOKIE[$name];
		}
		else if(!ini_get('session.use_only_cookies') && isset($_GET[$name]))
		{
			$sessid = $_GET[$name];
		}
		else
		{
			return @session_start();
		}
		
		if(is_array($sessid) || !preg_match('/^[a-zA-Z0-9,-]+$/', $sessid))
		{
			if($cookie_session) //Try to reset incorrect session cookie
			{
				setcookie($name, '', 1);
				unset($_COOKIE[$name]);
				if(!ini_get('session.use_only_cookies') && isset($_GET[$name]))
					unset($_GET[$name]);
				
				return @session_start();
			}
			
			return false;
		}
		
		return @session_start();
	}
	
	//Открыть сессию
	static public function init()
	{
		if(!self::$started)
		{
			if(self::safeSessionStart())
				self::$started = true;
		}
	}
	
	//Открыта ли сессия
	static public function isStarted()
	{
		return self::$started;
	}
	
	//Завершить сессию
	static public function close()
	{
		if(self::$started)
		{
			session_write_close();
			self::$started = false;
		}
	}
	
	//Получить значение ключа с именем $name из сессии
	//Если ключ отсутствует, будет возвращено значение $default_value
	static public function get($name, $default_value = null)
	{
		return isset($_SESSION[$name]) && !is_array($_SESSION[$name])
			? $_SESSION[$name] : $default_value;
	}
	
	//Установить значение ключа с именем $name в $value
	static public function set($name, $value)
	{
		$_SESSION[$name] = $value;
	}
	
	//Удалить ключ с именем $name из сессии
	static public function remove($name)
	{
		unset($_SESSION[$name]);
	}
}

class SessionInitializer
{
	//Была ли инициализирована сессия при создании класса
	private $session_initialized;
	
	public function __construct()
	{
		$this->session_initialized = SessionHelper::isStarted();
		SessionHelper::init();
	}
	
	public function __destruct()
	{
		if(!$this->session_initialized)
			SessionHelper::close();
	}
}

final class WebHelpers
{
	private function __construct()
	{
	}
	
	//Получить значение из массива $_REQUEST
	//Если значение отсутствует, вернуть $default_value
	static public function request($name, $default_value = null)
	{
		return isset($_REQUEST[$name]) && !is_array($_REQUEST[$name]) ? $_REQUEST[$name] : $default_value;
	}
	
	//Выдать ответ в формате JSON
	static public function echoJson(Array $value)
	{
		header('Content-Type: application/json; charset=UTF-8');
		$ret = json_encode($value);
		if($ret !== false)
		{
			echo $ret;
			return true;
		}
		
		return false;
	}
}

final class TaskHelper
{
	private function __construct()
	{
	}
	
	//Создать уникальный в пределах сессии идентификатор задачи
	static public function generateTaskId()
	{
		$session_initializer = new SessionInitializer;
		$id = SessionHelper::get('max_task', 0) + 1;
		SessionHelper::set('max_task', $id);
		return $id;
	}
	
	//Получить идентификатор задачи, переданный клиентом
	static public function getTaskId()
	{
		$task_id = WebHelpers::request('task');
		
		if(!preg_match('/^\d{1,9}$/', $task_id))
			return null;
		
		return (int)$_REQUEST['task'];
	}
}

class ProgressManager
{
	//Идентификатор задачи
	private $task_id = 0;
	//Количество шагов в задаче
	private $step_count = 1;
	//Текущий шаг
	private $current_step = 0;
	//Инициализатор сессии на время работы менеджера
	private $session_initializer;
	
	//Создание менеджера прогресса для задачи с идентификатором $task_id
	public function __construct($task_id)
	{
		$this->session_initializer = new SessionInitializer;
		$this->task_id = $task_id;
		SessionHelper::set('progress' . $this->task_id, 0);
		SessionHelper::close();
	}
	
	//Установка количества шагов прогресса
	public function setStepCount($step_count)
	{
		$this->step_count = $step_count;
		$this->current_step = 0;
	}
	
	//Увеличение прогресса на 1 (переход к следующему шагу)
	public function incrementProgress()
	{
		if(++$this->current_step >= $this->step_count)
			$this->current_step = $this->step_count;
		
		SessionHelper::init();
		SessionHelper::set('progress' . $this->task_id,
			(int)(($this->current_step * 100.0) / $this->step_count));
		SessionHelper::close();
		
		header_remove('Set-Cookie');
	}
	
	//Завершение подсчета прогресса
	public function __destruct()
	{
		SessionHelper::init();
		SessionHelper::remove('progress' . $this->task_id);
	}
	
	//Получение значения прогресса для идентификатора задачи, переданного клиентом
	public static function getProgress()
	{
		$task_id = TaskHelper::getTaskId();
		if($task_id === null)
			return null;
		
		$session_initializer = new SessionInitializer;
		$progress = SessionHelper::get('progress' . $task_id, null);
		
		if($progress === null)
			return null;
		
		return (int)$progress;
	}
}

error_reporting(E_ALL);
set_time_limit(0);

if(WebHelpers::request('new_task') === '1') //Генерируем новый ID задачи
{
	WebHelpers::echoJson(['task' => TaskHelper::generateTaskId()]);
	return;
}

if(WebHelpers::request('get_progress') === '1') //Получаем прогресс
{
	$progress = ProgressManager::getProgress();
	if($progress !== null)
		WebHelpers::echoJson(['progress' => $progress]);
	else
		WebHelpers::echoJson([]);
	
	return;
}

//Запускаем длительный процесс (на 60 шагов по 200 миллисекунд) с контролем прогресса
const STEP_COUNT = 60;
const STEP_DELAY = 200000;
if(WebHelpers::request('long_process') === '1')
{
	$task_id = TaskHelper::getTaskId();
	if($task_id === null)
		return;
	
	$manager = new ProgressManager($task_id);
	$manager->setStepCount(STEP_COUNT);
	
	for($i = 0; $i !== STEP_COUNT; ++$i)
	{
		$manager->incrementProgress();
		usleep(STEP_DELAY);
	}
	
	WebHelpers::echoJson([]);
	return;
}

//Вывод странички
SessionHelper::init();
?>
<!doctype html>
<html>
	<head>
		<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
		<title>Progress test</title>
		<script type="text/javascript" src="//code.jquery.com/jquery-latest.min.js"></script>
		<script type="text/javascript">
			//Идентификаторы завершенных задач
			var finishedTasks = [];
			
			//Стартовать длительную задачу
			var startLongTask = function(task_id)
			{
				$.get("?", {long_process: 1, task: task_id}, function(data)
				{
					finishedTasks.push(task_id);
					$("#task-" + task_id).text("Finished");
				}, "json");
			}
			
			//Отслеживать прогресс длительной задачи
			var monitorProgress = function(task_id)
			{
				$.get("?", {get_progress: 1, task: task_id}, function(data)
				{
					if($.inArray(task_id, finishedTasks) != -1)
						return;
					
					if(data.progress !== undefined)
						$("#task-" + task_id).text("Progress: " + data.progress + "%");
					
					setTimeout(function() { monitorProgress(task_id); }, 100);
				}, "json");
			}
			
			//Запустить длительную задачу с отслеживанием прогресса
			var runTask = function(task_id)
			{
				var progressDiv = $("<div/>").addClass("progress-div");
				$("<div/>").text("Task ID: " + task_id).appendTo(progressDiv);
				$("<div/>").attr("id", "task-" + task_id).text("Starting...")
					.appendTo(progressDiv);
				$("#progressContainer").append(progressDiv);
				
				startLongTask(task_id);
				monitorProgress(task_id);
			}
			
			//Получить новый уникальный идентификатор задачи, после чего
			//запустить длительную задачу с отслеживанием прогресса
			var startProgress = function()
			{
				$.get("?", {new_task: 1}, function(data)
				{
					runTask(data.task);
				}, "json");
			}
		</script>
		<style>
			h3
			{
				text-align: center;
			}
			
			.progress-div
			{
				padding: 5px;
				border: 1px solid gray;
				margin: 3px;
			}
		</style>
	</head>
	<body>
		<h3>Progress test</h3>
		<div id="progressContainer">
		</div>
		<div>
			<button onclick="startProgress();">Start progress</button>
		</div>
	</body>
</html>