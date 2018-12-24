<?php
/**
 * Created by PhpStorm.
 * User: HP ENVY
 * Date: 10/23/2018
 * Time: 1:22 PM
 */

namespace Path\Http;
use Path\PathException;

load_class(["Http/MiddleWare"]);

class Response
{
    public $content;
    public $status;
    public $headers = [];
    public $build_path = "";
    public function __construct($build_path = '/')
    {
        $this->build_path = $build_path;
        return $this;
    }
    public function json($arr,$status = 200){
        $this->content = json_encode((array)$arr);
        $this->status = $status;
        $this->headers = ["Content-Type" => "application/json; charset=UTF-8"];
        return $this;
    }
    public function text(String $text,$status = 200){
        $this->content = $text;
        $this->status = $status;
        $this->headers = ["Content-Type" => "text/plain; charset=UTF-8"];
        return $this;
    }
    public function htmlString(String $html, $status = 200){
        $this->content = $html;
        $this->status = $status;
        $this->headers = ["Content-Type" => "text/html; charset=UTF-8"];
        return $this;
    }
    public function html($file_path,$status = 200){
//        echo "build_path: ". $this->build_path.PHP_EOL;
//        echo "file_path: ". $file_path.PHP_EOL;
        $file = $_SERVER['DOCUMENT_ROOT'].DIRECTORY_SEPARATOR.$this->build_path."/".$file_path;
        $public_path = treat_path($this->build_path);

        if(!file_exists($file))
            throw new PathException(" \"{$file_path}\" does not exist");

//      get the content
        $file_content = file_get_contents($file);

        $match_resources = preg_replace_callback('/(href|src)=\"?([^">\s]+)\"?[^\s>]*/m',function ($matches) use ($public_path){
//            var_dump($matches);
            $resources_path = explode("/",$matches[2]);
            array_shift($resources_path);
            $resources_path = join("/",$resources_path);

            return "$matches[1]='{$public_path}{$resources_path}'";
        },$file_content);

        $this->content = $match_resources;
        $this->status = $status;
        $this->headers = ["Content-Type" => "text/html; charset=UTF-8"];

        return $this;
    }
    public function stream($data,$status = 200){
        $this->content = $data;
        $this->status = $status;
        $this->headers = ["Content-Type" => "application/octet-stream; charset=UTF-8"];
        return $this;
    }
    public function redirect($url){
        header("location: {$url}");
    }
    public function addHeader(array $header){
        $this->headers = array_merge($this->headers,$header);
        return $this;
    }
    public function file($file_path){

    }
}