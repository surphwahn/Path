<?php
/**
 * @Author by Sulaiman Adewale.
 * @Date 1/16/2019
 * @Time 1:45 PM
 * @Project Path
 */

namespace Path\Http;


use Path\Storage\Caches;
use Path\Controller;
use Path\Controller\Live\TestLive;
use Path\LiveController;
use Path\Storage\Sessions;
use Path\WatcherException;

import(
    "core/Classes/Http/Response",
    "core/Classes/LiveController",
    "core/Classes/Storage/Caches",
    "core/Classes/Storage/Sessions"
    );

class Watcher
{
    private $path;
    private $watcher_namespace = "Path\Controller\Live\\";
    private $watchers_path = "Path/Controllers/Live/";
    private $cache = [];
    public  $socket_key = "";
    private $response = [];
    private $response_instance;
    public  $session;
    private $throw_exception = false;
    public  $error = null;
    public  $server;
    private $controller_data = [
        "root"                 => null,
        "watchable_methods"    => null,
        "params"               => null
    ];

    public $has_changes = [];
    /*
     * This will be set to true at first execution
     * so watcher will execute at least once after every initiation
     * */
    public $has_executed = [];
    /*
     * Holds all controllers being watched
     * */
    private $controller;
    /*
     *
     * */
    public function __construct(string $path,$session_id)
    {
        $this->path = trim($path);
        $this->response_instance = new Response();
        $this->session = new Sessions($session_id);
        $this->extractInfo();
    }
    private function getResources($payload):array {
        $split_load = explode("&",$payload);
        $all_params = [];
        $watchable_methods = [];
        foreach ($split_load as $load){
            if(preg_match("/Params=\[(.+)\]/i",$load,$matches)){
                $params = explode(",",$matches[1]);
                foreach ($params as $param){
                    $param = explode("=",$param);
                    $all_params[$param[0]] = $param[1];
                }
            }

            if (preg_match("/Watch=\[(.+)\]/i",$load,$matches)){
                $list = $matches[1];
                $list = explode(",",$list);
                $watchable_methods = $list;
            }
        }

        return [
            'params' => $all_params,
            'watchable_methods' => $watchable_methods
        ];
    }
    private function extractInfo(){
        $path = $this->path;
        $path = array_values(array_filter(explode("/",$path),function ($p){
            return strlen(trim($p)) > 0;
        }));
        $payload = array_slice($path,1);
        $path = trim($path[0]);
        $url_resources = $this->getResources($payload[0] ?? "");
        $this->controller_data['root']                 = $path;
        $this->controller_data['watchable_methods']    = $url_resources['watchable_methods'];
        $this->controller_data['params']               = $url_resources['params'];
        $this->controller = $this->getController();
    }

    private static function isValidClassName($class_name){
        return preg_match("/^[\w]+$/",$class_name);
    }
    public function getController($message = null):?LiveController{
        $path = $this->controller_data['root'];
        if(!self::isValidClassName($path)){
            $this->throwException("Invalid LiveController Name \"{$path}\" ");
        }


        if($path){
            import($this->watchers_path.$path);
            $path = $this->watcher_namespace.$path;

            $controller = new $path(
                $this->session,
                $this->controller_data['params'],
                $message
            );

            return $controller;
        }
        return null;
    }
    private function getWatchable(){

    }
    public function watch($message = null){
        $controller = $this->getController($message);
        if(!isset($controller->watch_list)){
            $this->throwException("Specify \"watch_list\" in ". get_class($controller));
        }


        $watch_list = self::castToString($controller->watch_list);
//        cache watch_list values if not already cached
        $this->execute($watch_list,$controller);
        $this->cache($watch_list);

    }

    private static function castToString($arr){
        $ret = [];
        foreach ($arr as $key => $value){
            $ret[$key] = is_array($value)?json_encode($value):(string) $value;
        }
        return $ret;
    }
    private static function shouldCache($method,$value){
        $_value = Caches::get($method);
        return is_null($_value) || $_value !== $value;
    }

    private function method($method){
        return md5($this->socket_key.$method);
    }

    private function cache($watch_list){
        foreach ($watch_list as $method => $value){
            $method = $this->method($method);
            if(self::shouldCache($method,$value)){
//                echo "caching {$method} to {$value}".PHP_EOL;
//                var_dump($value);
                Caches::set($method,$value);
            }
        }
    }

    public function clearCache(){
        $controller = $this->getController();
        $watch_list = self::castToString($controller->watch_list);

        foreach ($watch_list as $method => $value) {
            $method = $this->method($method);
            Caches::delete($method);
        }
    }

    private  function shouldExecute($method, $value){
        $_method = $this->method($method);
        $cached_value = Caches::get($_method);
        return (is_null($cached_value) || $cached_value != $value) || !@$this->has_executed[$method];
    }

    private function getPrevValue($method){
        $_method = $this->method($method);
        $cached_value = Caches::get($_method);
        return $cached_value;
    }

    public function execute($watch_list,$controller,$message = null,$force_execute = false){
        $watchable_methods = $this->controller_data['watchable_methods'];
//        var_dump($_SESSION);

        if(is_null($watchable_methods)){
//            watch all watchable
            foreach ($watch_list as $_method => $_value){
                if($this->shouldExecute($_method,$_value) OR $force_execute){
                    $this->has_changes[$_method] = true;
                    $this->has_executed[$_method] = true;
                    if(!method_exists($controller,$_method)){
                        $this->response[$_method] = $_value;
                    }else{

                        $response = is_null($message) ? $controller->{$_method}($this->response_instance,null,$this->session):$controller->{$_method}($this->response_instance,$message,$this->session);
                        $this->response[$_method] = $response;
                    }
                }else{
                    $this->has_changes[$_method] = false;
                }
            }
        }else{
//            validate the watchlist
            foreach ($watchable_methods as $method){
                $_method = $method;
                if(isset($watch_list[$method])){
                    $_value = @$watch_list[$method];
                    if($this->shouldExecute($_method,$_value) OR $force_execute){
                        $this->has_changes[$_method] = true;
                        $this->has_executed[$_method] = true;
                        if(!method_exists($controller,$_method)){
                            $this->response[$_method] = $_value;
                        }else{
                            $response = is_null($message) ? $controller->{$_method}($this->response_instance,null,$this->session):$controller->{$_method}($this->response_instance,$message,$this->session);
                            $this->response[$_method] = $response;
                        }
                    }else{
                        $this->has_changes[$_method] = false;

                    }
                }
            }
        }
    }
    public function sendMessage($message){
        $controller = $this->getController($message);
        $watch_list = self::castToString($controller->watch_list);
        $this->execute($watch_list,$controller,$message);
        $this->cache($watch_list);
    }

    public function navigate($params,$message = null){
        $this->controller_data['params'] = $params;
        $controller = $this->getController($message);
        $watch_list = self::castToString($controller->watch_list);
        $this->execute($watch_list,$controller,$message,true);
        $this->cache($watch_list);
    }

    private function throwException($error_text){
        if($this->throw_exception){
            throw new WatcherException($error_text);
        }else{
            $this->error = $error_text;
        }
    }
    public function getResponse(){
        $response = [];
        foreach ($this->response as $key => $value){
//              check if value is an instance of response, then set to appropriate data type
            if($this->hasChanges($key)){
                if($value instanceof Response){
                    $response[$key] = [
                        "data"           => $value->content,
                        "status"         => $value->status,
                        "headers"        => $value->headers,
                        "has_changes"    => $this->hasChanges($key)
                    ];
                }else{
                    $response[$key] = [
                        "data"           => $value,
                        "status"         => 200,
                        "headers"        => [],
                        "has_changes"    => $this->hasChanges($key)
                    ];
                }
            }

        }
        return $response;
    }
    public static function log($text){
        echo PHP_EOL.$text.PHP_EOL;
//        flush();
//        ob_flush();
    }

    /**
     * @return bool
     */
    public function changesOccurred(){
        foreach ($this->has_changes as $method => $status){
            if($status === true){
                return true;
            }
        }
        return false;
    }


    /**
     * @param $method
     * @return bool
     */
    private function hasChanges($method)
    {
        return (@$this->has_changes[$method] === true);
    }
}