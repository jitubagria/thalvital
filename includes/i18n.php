<?php
function lang_init():void{$code=$_GET['lang']??$_SESSION['lang']??'en';$file=__DIR__.'/../lang/'.preg_replace('/[^a-z]/','',$code).'.json';$_SESSION['lang']=is_file($file)?$code:'en';$GLOBALS['tr']=json_decode(file_get_contents(__DIR__.'/../lang/en.json'),true);if($_SESSION['lang']!=='en')$GLOBALS['tr']=array_replace($GLOBALS['tr'],json_decode(file_get_contents($file),true)?:[]);}
function t(string $key):string{return $GLOBALS['tr'][$key]??$key;}
lang_init();
